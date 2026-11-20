//! Edge-based graph (car): a node is a directed segment, an arc A -> B costs the time along A plus the
//! penalty of the turn from A into B. Turn restrictions (via a node or via one or more ways), signals,
//! gates, U-turns, and the weight penalty for entering local-access-only roads live here.

use crate::ch::Arc;
use crate::geom::{bearing, haversine};
use crate::net::{NONE, Net};
use crate::profile::car::{GATE_PENALTY, RESTRICTED_ENTRY_WEIGHT, turn_penalty};
use crate::profile::{NODE_BARRIER, NODE_GATE, NODE_SIGNALS};
use std::collections::{HashMap, HashSet};

pub struct EdgeGraph {
    /// graph node -> directed segment (shadow nodes repeat the segment they copy)
    pub cnodes: Vec<u32>,
    pub arcs: Vec<Arc>,
    /// (original node, shadow node): the shadow stands for "arrived through the restricted sequence"
    pub shadows: Vec<(u32, u32)>,
    /// directed segment -> graph node, `NONE` when not allowed
    pub cid: Vec<u32>,
    pub stats: String,
}

struct Chain {
    a: u32,
    chain: Vec<u32>,
    outs: Vec<u32>,
    only: bool,
}

/// Directed segments (`segment * 2 + direction`) of a via-way restriction, when the geometry fits.
fn resolve_via(net: &Net, r: &crate::net::ViaWayRestriction) -> Vec<Chain> {
    let mut found = Vec::new();
    let (s0, c0) = net.way_segs[r.via[0]];
    if c0 == 0 {
        return found;
    }
    let first = net.node_junc[net.seg_nodes[s0 as usize].0 as usize] as usize;
    let last = net.node_junc[net.seg_nodes[(s0 + c0 - 1) as usize].1 as usize] as usize;
    for start in [first, last] {
        let ins: Vec<u32> = net
            .incident(start)
            .iter()
            .filter(|&&x| net.seg_way[(x >> 1) as usize] as usize == r.from)
            .map(|&x| (x & !1) | (1 - (x & 1)))
            .collect();
        if ins.is_empty() {
            continue;
        }
        let mut cur = start;
        let mut chain = Vec::new();
        let mut ok = true;
        for &w in &r.via {
            let (s, c) = net.way_segs[w];
            if c == 0 {
                ok = false;
                break;
            }
            let head = net.node_junc[net.seg_nodes[s as usize].0 as usize] as usize;
            let tail = net.node_junc[net.seg_nodes[(s + c - 1) as usize].1 as usize] as usize;
            if head == cur {
                chain.extend((s..s + c).map(|x| x * 2));
                cur = tail;
            } else if tail == cur {
                chain.extend((s..s + c).rev().map(|x| x * 2 + 1));
                cur = head;
            } else {
                ok = false;
                break;
            }
        }
        if !ok {
            continue;
        }
        let outs: Vec<u32> = net
            .incident(cur)
            .iter()
            .filter(|&&x| net.seg_way[(x >> 1) as usize] as usize == r.to)
            .copied()
            .collect();
        if outs.is_empty() {
            continue;
        }
        for a in ins {
            found.push(Chain {
                a,
                chain: chain.clone(),
                outs: outs.clone(),
                only: r.only,
            });
        }
        break;
    }
    found
}

pub fn build(net: &Net) -> EdgeGraph {
    let nseg = net.nseg();
    let nj = net.nj();

    // bearing of each directed segment, towards a point about 20 m along
    let mut brg = vec![0f32; nseg * 2];
    for s in 0..nseg {
        let p = net.points(s);
        for dir in 0..2 {
            let seq: Vec<(i32, i32)> = if dir == 0 {
                p.to_vec()
            } else {
                p.iter().rev().copied().collect()
            };
            let (mut acc, mut target) = (0.0, *seq.last().unwrap());
            for k in 1..seq.len() {
                acc += haversine(seq[k - 1], seq[k]);
                target = seq[k];
                if acc >= 20.0 {
                    break;
                }
            }
            brg[s * 2 + dir] = bearing(seq[0], target) as f32;
        }
    }

    // node restrictions at the directed-segment level
    let mut banned: HashSet<u64> = HashSet::new();
    let mut only: HashMap<u32, Vec<u32>> = HashMap::new();
    let mut n_res = 0usize;
    for &(fw, via, tw, is_only) in &net.node_restr {
        let j = net.node_junc[via as usize];
        if j == NONE {
            continue;
        }
        let es = net.incident(j as usize);
        let ins = es
            .iter()
            .filter(|&&x| net.seg_way[(x >> 1) as usize] as usize == fw)
            .map(|&x| (x & !1) | (1 - (x & 1)));
        let outs: Vec<u32> = es
            .iter()
            .filter(|&&x| net.seg_way[(x >> 1) as usize] as usize == tw)
            .copied()
            .collect();
        for e in ins {
            if is_only {
                only.entry(e).or_default().extend(outs.iter().copied());
            } else {
                for &f in &outs {
                    banned.insert(((e as u64) << 32) | f as u64);
                }
            }
            n_res += 1;
        }
    }

    // graph nodes: the allowed directed segments
    let mut cid = vec![NONE; nseg * 2];
    let mut cnodes: Vec<u32> = Vec::new();
    for d in 0..(nseg * 2) as u32 {
        if net.dur(d) > 0 {
            cid[d as usize] = cnodes.len() as u32;
            cnodes.push(d);
        }
    }

    let mut arcs: Vec<Arc> = Vec::new();
    for j in 0..nj {
        let es = net.incident(j);
        let flags = net.nflags[net.junc_node[j] as usize];
        let (light, barrier, gate) = (
            flags & NODE_SIGNALS != 0,
            flags & NODE_BARRIER != 0,
            flags & NODE_GATE != 0,
        );
        let roads = es.len();
        let outs: Vec<u32> = es.iter().copied().filter(|&d| net.dur(d) > 0).collect();
        let ins: Vec<u32> = es
            .iter()
            .map(|&x| (x & !1) | (1 - (x & 1)))
            .filter(|&d| net.dur(d) > 0)
            .collect();
        for &e in &ins {
            let only_e = only.get(&e);
            for &f in &outs {
                let uturn = f == (e ^ 1);
                if uturn {
                    if !(barrier || outs.len() == 1) {
                        continue;
                    }
                } else if barrier {
                    continue;
                }
                if banned.contains(&(((e as u64) << 32) | f as u64)) {
                    continue;
                }
                if only_e.is_some_and(|o| !o.contains(&f)) {
                    continue;
                }
                let b_in = brg[(e ^ 1) as usize] as f64 + 180.0;
                let mut a = brg[f as usize] as f64 - b_in;
                while a > 180.0 {
                    a -= 360.0;
                }
                while a <= -180.0 {
                    a += 360.0;
                }
                let mut pen = turn_penalty(a, roads, light, uturn);
                if gate {
                    pen += GATE_PENALTY;
                }
                let tp = (pen * 10.0).round() as u32;
                let mut w = net.weight(e) + tp;
                if !net.restricted(e) && net.restricted(f) {
                    w += (RESTRICTED_ENTRY_WEIGHT * 10.0) as u32;
                }
                arcs.push(Arc {
                    src: cid[e as usize],
                    dst: cid[f as usize],
                    w,
                    t: net.dur(e) + tp,
                    d: net.len_m(e),
                    sd: NONE,
                });
            }
        }
    }

    // via-way restrictions: a copy of the restricted sequence of segments, reachable only from the
    // segment the restriction starts at, whose last copy lacks (or is limited to) the restricted exits
    let mut chains: Vec<Chain> = Vec::new();
    let mut seen: HashSet<(u32, u32)> = HashSet::new();
    for r in &net.via_restr {
        for c in resolve_via(net, r) {
            let ok = cid[c.a as usize] != NONE && c.chain.iter().all(|&d| cid[d as usize] != NONE);
            if ok && seen.insert((c.a, c.chain[0])) {
                chains.push(c);
            }
        }
    }
    let n_chains = chains.len();
    let mut shadows: Vec<(u32, u32)> = Vec::new();
    if !chains.is_empty() {
        let members: HashSet<u32> = chains
            .iter()
            .flat_map(|c| c.chain.iter().map(|&d| cid[d as usize]))
            .collect();
        let mut out_of: HashMap<u32, Vec<Arc>> = HashMap::new();
        for a in &arcs {
            if members.contains(&a.src) {
                out_of.entry(a.src).or_default().push(*a);
            }
        }
        let replace: HashSet<(u32, u32)> = chains
            .iter()
            .map(|c| (cid[c.a as usize], cid[c.chain[0] as usize]))
            .collect();
        let mut redirected: Vec<Arc> = Vec::new();
        arcs.retain(|a| {
            if replace.contains(&(a.src, a.dst)) {
                redirected.push(*a);
                false
            } else {
                true
            }
        });
        for c in &chains {
            let ids: Vec<u32> = c.chain.iter().map(|&d| cid[d as usize]).collect();
            let base = cnodes.len() as u32;
            let sh: Vec<u32> = (0..ids.len() as u32).map(|i| base + i).collect();
            for (i, &d) in c.chain.iter().enumerate() {
                cnodes.push(d);
                shadows.push((ids[i], sh[i]));
            }
            // A -> first copy
            for a in redirected
                .iter()
                .filter(|a| a.src == cid[c.a as usize] && a.dst == ids[0])
            {
                arcs.push(Arc { dst: sh[0], ..*a });
            }
            let outs: HashSet<u32> = c.outs.iter().map(|&d| cid[d as usize]).collect();
            for i in 0..ids.len() {
                for a in out_of.get(&ids[i]).map(|v| v.as_slice()).unwrap_or(&[]) {
                    if i + 1 < ids.len() {
                        if a.dst == ids[i + 1] {
                            arcs.push(Arc {
                                src: sh[i],
                                dst: sh[i + 1],
                                ..*a
                            });
                        } else {
                            arcs.push(Arc { src: sh[i], ..*a });
                        }
                    } else {
                        let is_out = outs.contains(&a.dst);
                        if c.only != is_out {
                            continue;
                        }
                        arcs.push(Arc { src: sh[i], ..*a });
                    }
                }
            }
        }
    }
    let stats = format!(
        "{} nodes ({} copies for {} via-way restrictions), {} arcs, {} restrictions at nodes",
        cnodes.len(),
        shadows.len(),
        n_chains,
        arcs.len(),
        n_res
    );
    EdgeGraph {
        cnodes,
        arcs,
        shadows,
        cid,
        stats,
    }
}
