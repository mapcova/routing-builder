//! A small reader of `.mcr` files: `mcr info`, and the tests (a CH query between two graph nodes).

use crate::file::{HEADER, MAGIC, SECTION_NAMES, SEG_BYTES, VERSION};
use std::cmp::Reverse;
use std::collections::{BinaryHeap, HashMap};

pub struct Mcr {
    pub data: Vec<u8>,
    pub sections: Vec<(u64, u64)>,
    pub profile: u32,
    pub edge_based: bool,
    pub nodes: u32,
    pub up_fwd: u32,
    pub up_bwd: u32,
    pub segments: u32,
}

#[derive(Debug, Clone, Copy, PartialEq)]
pub struct Seg {
    pub length_m: u32,
    pub flags: u32,
    pub dur: [u32; 2],
    pub node: [u32; 2],
    pub weight: [u32; 2],
}

fn u32at(d: &[u8], o: usize) -> u32 {
    u32::from_le_bytes(d[o..o + 4].try_into().unwrap())
}

fn u64at(d: &[u8], o: usize) -> u64 {
    u64::from_le_bytes(d[o..o + 8].try_into().unwrap())
}

impl Mcr {
    pub fn open(path: &str) -> Result<Self, String> {
        Self::from_bytes(std::fs::read(path).map_err(|e| format!("cannot read {path}: {e}"))?)
    }

    pub fn from_bytes(data: Vec<u8>) -> Result<Self, String> {
        if data.len() < HEADER || &data[0..4] != MAGIC {
            return Err("not an MCR file".into());
        }
        let v = u32at(&data, 4);
        if v != VERSION {
            return Err(format!("unsupported format version {v}"));
        }
        let sections: Vec<(u64, u64)> = (0..SECTION_NAMES.len())
            .map(|i| (u64at(&data, 128 + i * 16), u64at(&data, 136 + i * 16)))
            .collect();
        // A truncated or forged section table must be an error, never a panic.
        for (i, &(o, l)) in sections.iter().enumerate() {
            match o.checked_add(l) {
                Some(end) if end <= data.len() as u64 => {}
                _ => return Err(format!("section {} lies outside the file", SECTION_NAMES[i])),
            }
        }
        Ok(Self {
            profile: u32at(&data, 8),
            edge_based: u32at(&data, 12) == 1,
            nodes: u32at(&data, 16),
            up_fwd: u32at(&data, 20),
            up_bwd: u32at(&data, 24),
            segments: u32at(&data, 28),
            sections,
            data,
        })
    }

    fn section(&self, i: usize) -> &[u8] {
        let (o, l) = self.sections[i];
        &self.data[o as usize..(o + l) as usize]
    }

    pub fn meta(&self) -> String {
        String::from_utf8_lossy(self.section(8)).into_owned()
    }

    pub fn segment(&self, s: u32) -> Seg {
        let b = &self.section(3)[s as usize * SEG_BYTES..(s as usize + 1) * SEG_BYTES];
        let f = |i: usize| u32at(b, i * 4);
        Seg {
            length_m: f(2),
            flags: f(3),
            dur: [f(4), f(5)],
            node: [f(6), f(7)],
            weight: [f(8), f(9)],
        }
    }

    /// Upward arcs of `node` as `[target, weight, duration, distance, middle]`.
    pub fn arcs(&self, node: u32, fwd: bool) -> Vec<[u32; 5]> {
        let nodes = self.section(0);
        let col = if fwd { 0 } else { 1 };
        let from = u32at(nodes, node as usize * 12 + col * 4) as usize;
        let to = u32at(nodes, (node as usize + 1) * 12 + col * 4) as usize;
        let sec = self.section(if fwd { 1 } else { 2 });
        (from..to)
            .map(|i| std::array::from_fn(|k| u32at(sec, i * 20 + k * 4)))
            .collect()
    }

    /// Shortest (weight, duration, distance) from graph node `src` to `dst`, the weight being minimised.
    pub fn query(&self, src: u32, dst: u32) -> Option<(u32, u32, u32)> {
        let up = |start: u32, fwd: bool| {
            let mut best: HashMap<u32, (u32, u32, u32)> = HashMap::new();
            let mut heap = BinaryHeap::new();
            best.insert(start, (0, 0, 0));
            heap.push(Reverse((0u32, start)));
            while let Some(Reverse((w, u))) = heap.pop() {
                if best[&u].0 < w {
                    continue;
                }
                let (_, t, d) = best[&u];
                for a in self.arcs(u, fwd) {
                    let nw = w + a[1];
                    if best.get(&a[0]).is_none_or(|b| nw < b.0) {
                        best.insert(a[0], (nw, t + a[2], d + a[3]));
                        heap.push(Reverse((nw, a[0])));
                    }
                }
            }
            best
        };
        let f = up(src, true);
        let b = up(dst, false);
        f.iter()
            .filter_map(|(n, x)| b.get(n).map(|y| (x.0 + y.0, x.1 + y.1, x.2 + y.2)))
            .min()
    }

    pub fn info(&self) -> String {
        let mut s = format!(
            "profile {} ({}), {} nodes, {} + {} upward arcs, {} segments, {} bytes\n",
            self.profile,
            if self.edge_based { "edge-based" } else { "node-based" },
            self.nodes,
            self.up_fwd,
            self.up_bwd,
            self.segments,
            self.data.len()
        );
        for (i, (o, l)) in self.sections.iter().enumerate() {
            s.push_str(&format!(
                "  {:<10} offset {:>12} length {:>12}\n",
                SECTION_NAMES[i], o, l
            ));
        }
        s.push_str(&self.meta());
        s.push('\n');
        s
    }
}
