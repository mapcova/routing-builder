//! The road network cut into segments (the stretch of a way between two junctions), shared by the
//! edge-based (car) and node-based (bike, foot) graph builders.

use crate::geom::{Pt, haversine};
use crate::osm::OsmData;
use crate::profile::{NODE_RESTRICTION, Profile};
use std::collections::HashMap;

pub const NONE: u32 = u32::MAX;

/// Segment flag bits.
pub const SEG_RESTRICTED_FWD: u8 = 1;
pub const SEG_RESTRICTED_BWD: u8 = 2;
/// Not a place to start or end a route (ferry, private service road).
pub const SEG_NO_START: u8 = 4;

/// A restriction through one way or more (resolved to indexes of `Net::way_segs`).
pub struct ViaWayRestriction {
    pub from: usize,
    pub via: Vec<usize>,
    pub to: usize,
    pub only: bool,
}

pub struct Net {
    pub node_ids: Vec<i64>,
    pub coords: Vec<Pt>,
    pub nflags: Vec<u8>,
    pub pts: Vec<Pt>,
    /// start, count into `pts`
    pub seg_geom: Vec<(u32, u32)>,
    /// OSM node index of the start and of the end
    pub seg_nodes: Vec<(u32, u32)>,
    pub seg_way: Vec<u32>,
    pub seg_len: Vec<f64>,
    /// duration in 1/10 s per direction (0 = not allowed)
    pub seg_dur: Vec<[u32; 2]>,
    /// routing weight in 1/10 s per direction
    pub seg_w: Vec<[u32; 2]>,
    pub seg_flags: Vec<u8>,
    pub way_ids: Vec<i64>,
    /// first segment, count
    pub way_segs: Vec<(u32, u32)>,
    pub node_junc: Vec<u32>,
    pub junc_node: Vec<u32>,
    pub inc_first: Vec<u32>,
    /// incident segments per junction: `segment * 2 + side` (side 0: the segment starts here)
    pub inc: Vec<u32>,
    /// restrictions at a node: from way, via OSM node, to way, only
    pub node_restr: Vec<(usize, u32, usize, bool)>,
    pub via_restr: Vec<ViaWayRestriction>,
    /// Segments of toll ways.
    pub toll_segments: usize,
}

impl Net {
    pub fn nseg(&self) -> usize {
        self.seg_geom.len()
    }

    pub fn nj(&self) -> usize {
        self.junc_node.len()
    }

    /// Duration of a directed segment (`segment * 2 + direction`).
    pub fn dur(&self, d: u32) -> u32 {
        self.seg_dur[(d >> 1) as usize][(d & 1) as usize]
    }

    pub fn weight(&self, d: u32) -> u32 {
        self.seg_w[(d >> 1) as usize][(d & 1) as usize]
    }

    pub fn restricted(&self, d: u32) -> bool {
        self.seg_flags[(d >> 1) as usize] & (SEG_RESTRICTED_FWD << (d & 1)) != 0
    }

    pub fn len_m(&self, d: u32) -> u32 {
        self.seg_len[(d >> 1) as usize].round() as u32
    }

    pub fn points(&self, s: usize) -> &[Pt] {
        let (g0, c) = self.seg_geom[s];
        &self.pts[g0 as usize..(g0 + c) as usize]
    }

    pub fn build(data: &OsmData, _profile: Profile) -> Net {
        let n = data.nodes.len();
        let node_ids: Vec<i64> = data.nodes.iter().map(|x| x.id).collect();
        let coords: Vec<Pt> = data.nodes.iter().map(|x| (x.lat, x.lon)).collect();
        let mut nflags: Vec<u8> = data.nodes.iter().map(|x| x.flags).collect();
        let idx = |id: i64| node_ids.binary_search(&id).ok().map(|i| i as u32);

        let way_refs: Vec<Vec<u32>> = data
            .ways
            .iter()
            .map(|w| w.refs.iter().filter_map(|&r| idx(r)).collect())
            .collect();
        let way_ids: Vec<i64> = data.ways.iter().map(|w| w.id).collect();
        let way_index: HashMap<i64, usize> = way_ids.iter().enumerate().map(|(i, &id)| (id, i)).collect();

        let mut node_restr = Vec::new();
        let mut via_restr = Vec::new();
        for r in &data.restrictions {
            let (Some(&f), Some(&t)) = (way_index.get(&r.from), way_index.get(&r.to)) else {
                continue;
            };
            if let Some(v) = r.via_node {
                if let Some(v) = idx(v) {
                    nflags[v as usize] |= NODE_RESTRICTION;
                    node_restr.push((f, v, t, r.only));
                }
            } else {
                let via: Vec<usize> = r.via_ways.iter().filter_map(|w| way_index.get(w).copied()).collect();
                if via.len() == r.via_ways.len() {
                    via_restr.push(ViaWayRestriction {
                        from: f,
                        via,
                        to: t,
                        only: r.only,
                    });
                }
            }
        }

        let mut cnt = vec![0u8; n];
        for wr in &way_refs {
            for &i in wr {
                cnt[i as usize] = cnt[i as usize].saturating_add(1);
            }
        }
        let flagged: Vec<bool> = nflags.iter().map(|f| *f != 0).collect();
        let is_junction =
            |i: u32, pos: usize, len: usize| pos == 0 || pos + 1 == len || cnt[i as usize] >= 2 || flagged[i as usize];

        let mut net = Net {
            node_ids,
            coords,
            nflags,
            pts: Vec::new(),
            seg_geom: Vec::new(),
            seg_nodes: Vec::new(),
            seg_way: Vec::new(),
            seg_len: Vec::new(),
            seg_dur: Vec::new(),
            seg_w: Vec::new(),
            seg_flags: Vec::new(),
            way_ids,
            way_segs: vec![(0, 0); data.ways.len()],
            node_junc: vec![NONE; n],
            junc_node: Vec::new(),
            inc_first: Vec::new(),
            inc: Vec::new(),
            node_restr,
            via_restr,
            toll_segments: 0,
        };

        for (wi, wr) in way_refs.iter().enumerate() {
            net.way_segs[wi].0 = net.seg_geom.len() as u32;
            if wr.len() < 2 {
                continue;
            }
            let way = &data.ways[wi].way;
            let way_len: f64 = wr
                .windows(2)
                .map(|p| haversine(net.coords[p[0] as usize], net.coords[p[1] as usize]))
                .sum();
            let mut start = 0usize;
            for p in 1..wr.len() {
                if !is_junction(wr[p], p, wr.len()) {
                    continue;
                }
                let g0 = net.pts.len() as u32;
                let mut len = 0.0;
                for q in start..=p {
                    let c = net.coords[wr[q] as usize];
                    if q > start {
                        len += haversine(*net.pts.last().unwrap(), c);
                    }
                    net.pts.push(c);
                }
                let dur_w = |d: &crate::profile::Dir| -> (u32, u32) {
                    if d.speed <= 0.0 {
                        return (0, 0);
                    }
                    let secs = match way.duration {
                        Some(total) if way_len > 0.0 => total as f64 * len / way_len,
                        Some(total) => total as f64,
                        None => len / (d.speed as f64 / 3.6),
                    };
                    let dur = (secs * 10.0).round().max(1.0);
                    (dur as u32, (dur / d.rate.max(0.01) as f64).round() as u32)
                };
                let (df, wf) = dur_w(&way.fwd);
                let (db, wb) = dur_w(&way.bwd);
                let mut fl = 0u8;
                if way.fwd.restricted {
                    fl |= SEG_RESTRICTED_FWD;
                }
                if way.bwd.restricted {
                    fl |= SEG_RESTRICTED_BWD;
                }
                if !way.startpoint {
                    fl |= SEG_NO_START;
                }
                net.seg_geom.push((g0, (p - start + 1) as u32));
                net.seg_nodes.push((wr[start], wr[p]));
                net.seg_way.push(wi as u32);
                net.seg_len.push(len);
                net.seg_dur.push([df, db]);
                net.seg_w.push([wf, wb]);
                net.seg_flags.push(fl);
                net.toll_segments += way.toll as usize;
                start = p;
            }
            net.way_segs[wi].1 = net.seg_geom.len() as u32 - net.way_segs[wi].0;
        }

        for &(a, b) in &net.seg_nodes {
            for x in [a, b] {
                if net.node_junc[x as usize] == NONE {
                    net.node_junc[x as usize] = net.junc_node.len() as u32;
                    net.junc_node.push(x);
                }
            }
        }
        let nj = net.nj();
        let mut first = vec![0u32; nj + 1];
        for &(a, b) in &net.seg_nodes {
            first[net.node_junc[a as usize] as usize + 1] += 1;
            first[net.node_junc[b as usize] as usize + 1] += 1;
        }
        for i in 0..nj {
            first[i + 1] += first[i];
        }
        let mut fill = first.clone();
        let mut inc = vec![0u32; first[nj] as usize];
        for (s, &(a, b)) in net.seg_nodes.iter().enumerate() {
            let ja = net.node_junc[a as usize] as usize;
            inc[fill[ja] as usize] = (s as u32) * 2;
            fill[ja] += 1;
            let jb = net.node_junc[b as usize] as usize;
            inc[fill[jb] as usize] = (s as u32) * 2 + 1;
            fill[jb] += 1;
        }
        net.inc_first = first;
        net.inc = inc;
        net
    }

    /// Segments touching junction `j`.
    pub fn incident(&self, j: usize) -> &[u32] {
        &self.inc[self.inc_first[j] as usize..self.inc_first[j + 1] as usize]
    }
}
