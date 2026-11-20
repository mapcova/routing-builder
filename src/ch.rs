//! Contraction Hierarchies over `fast_paths`, written as the node and arc sections of a `.mcr` file.

use crate::net::NONE;
use fast_paths::{FastGraph, InputGraph};

/// Bytes of an arc record in the `up_fwd` and `up_bwd` sections.
pub const ARC_BYTES: usize = 20;
/// In an arc record, `middle` with this bit set means an original arc (not a shortcut).
pub const ORIGINAL: u32 = 0x8000_0000;

/// An arc of the graph to contract: weights in 1/10 s, distance in metres.
#[derive(Clone, Copy, Debug)]
pub struct Arc {
    pub src: u32,
    pub dst: u32,
    pub w: u32,
    pub t: u32,
    pub d: u32,
    /// Node-based graphs: `segment * 2 + direction` of the road the arc follows. Edge-based: `NONE`.
    pub sd: u32,
}

pub struct Contracted {
    pub nodes: Vec<u8>,
    pub up_fwd: Vec<u8>,
    pub up_bwd: Vec<u8>,
    pub n_fwd: u32,
    pub n_bwd: u32,
    /// old node id -> id in the file (0 = top of the hierarchy)
    pub new_id: Vec<u32>,
}

struct Ctx<'a> {
    fg: &'a FastGraph,
    arcs: &'a [Arc],
    memo_f: Vec<(u32, u32)>,
    memo_b: Vec<(u32, u32)>,
}

impl Ctx<'_> {
    fn original(&self, src: u32, dst: u32) -> &Arc {
        let i = self
            .arcs
            .binary_search_by(|a| (a.src, a.dst).cmp(&(src, dst)))
            .expect("original arc");
        &self.arcs[i]
    }

    /// (duration, distance) of a CH edge: the sum over the original arcs it stands for.
    fn td(&mut self, fwd: bool, e: usize) -> (u32, u32) {
        let known = if fwd { self.memo_f[e] } else { self.memo_b[e] };
        if known.0 != NONE {
            return known;
        }
        let ed = if fwd {
            self.fg.edges_fwd[e]
        } else {
            self.fg.edges_bwd[e]
        };
        let v = if !ed.is_shortcut() {
            let (s, d) = if fwd {
                (ed.base_node, ed.adj_node)
            } else {
                (ed.adj_node, ed.base_node)
            };
            let a = self.original(s as u32, d as u32);
            (a.t, a.d)
        } else {
            let x = self.td(false, ed.replaced_in_edge);
            let y = self.td(true, ed.replaced_out_edge);
            (x.0 + y.0, x.1 + y.1)
        };
        if fwd {
            self.memo_f[e] = v
        } else {
            self.memo_b[e] = v
        }
        v
    }

    /// `middle` of an original arc, which carries the road followed in node-based graphs.
    fn orig_sd(&self, fwd: bool, base: usize, adj: usize) -> u32 {
        let (s, d) = if fwd { (base, adj) } else { (adj, base) };
        let sd = self.original(s as u32, d as u32).sd;
        if sd == NONE { NONE } else { ORIGINAL | sd }
    }
}

/// `arcs` may hold parallel arcs and loops: the cheapest of each pair is kept. `segdir[i]` is stored in
/// the node record of node `i` (edge-based graphs: the directed segment it stands for).
pub fn contract(n: usize, mut arcs: Vec<Arc>, segdir: &[u32]) -> Contracted {
    arcs.retain(|a| a.src != a.dst);
    arcs.sort_unstable_by_key(|a| (a.src, a.dst, a.w, a.t));
    arcs.dedup_by_key(|a| (a.src, a.dst));
    let mut ig = InputGraph::new();
    for a in &arcs {
        ig.add_edge(a.src as usize, a.dst as usize, a.w.max(1) as usize);
    }
    ig.freeze();
    let fg = fast_paths::prepare(&ig);
    let nn = fg.ranks.len();
    let mut ctx = Ctx {
        fg: &fg,
        arcs: &arcs,
        memo_f: vec![(NONE, 0); fg.edges_fwd.len()],
        memo_b: vec![(NONE, 0); fg.edges_bwd.len()],
    };
    // new id = rank from the top; nodes without any arc come last
    let mut order = vec![0usize; n];
    for old in 0..nn {
        order[nn - 1 - fg.ranks[old]] = old;
    }
    for (i, slot) in order.iter_mut().enumerate().skip(nn) {
        *slot = i;
    }
    let mut new_id = vec![0u32; n];
    for (ni, &old) in order.iter().enumerate() {
        new_id[old] = ni as u32;
    }
    let mut out = Contracted {
        nodes: Vec::with_capacity((n + 1) * 12),
        up_fwd: Vec::new(),
        up_bwd: Vec::new(),
        n_fwd: 0,
        n_bwd: 0,
        new_id: Vec::new(),
    };
    let push = |buf: &mut Vec<u8>, vals: [u32; 5]| {
        for v in vals {
            buf.extend_from_slice(&v.to_le_bytes());
        }
    };
    for &old in &order {
        for v in [out.n_fwd, out.n_bwd, segdir.get(old).copied().unwrap_or(0)] {
            out.nodes.extend_from_slice(&v.to_le_bytes());
        }
        if old >= nn {
            continue;
        }
        for e in fg.begin_out_edges(old)..fg.end_out_edges(old) {
            let ed = fg.edges_fwd[e];
            let (t, d) = ctx.td(true, e);
            let mid = if ed.is_shortcut() {
                new_id[fg.edges_fwd[ed.replaced_out_edge].base_node]
            } else {
                ctx.orig_sd(true, ed.base_node, ed.adj_node)
            };
            push(&mut out.up_fwd, [new_id[ed.adj_node], ed.weight as u32, t, d, mid]);
            out.n_fwd += 1;
        }
        for e in fg.begin_in_edges(old)..fg.end_in_edges(old) {
            let ed = fg.edges_bwd[e];
            let (t, d) = ctx.td(false, e);
            let mid = if ed.is_shortcut() {
                new_id[fg.edges_fwd[ed.replaced_out_edge].base_node]
            } else {
                ctx.orig_sd(false, ed.base_node, ed.adj_node)
            };
            push(&mut out.up_bwd, [new_id[ed.adj_node], ed.weight as u32, t, d, mid]);
            out.n_bwd += 1;
        }
    }
    for v in [out.n_fwd, out.n_bwd, 0u32] {
        out.nodes.extend_from_slice(&v.to_le_bytes());
    }
    out.new_id = new_id;
    out
}
