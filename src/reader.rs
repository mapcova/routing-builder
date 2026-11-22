//! A small reader of `.mcr` files: `mcr info`, and the tests (a CH query between two graph nodes).

use crate::file::{HEADER, MAGIC, SECTION_NAMES, SEG_BYTES, VERSION};
use crate::geom::{CELL, R_EARTH};
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

/// A route between two points: what the reference reader says an instance must find.
#[derive(Debug, Clone, Copy, PartialEq)]
pub struct Route {
    pub weight: i64,
    /// tenths of a second
    pub duration_ds: i64,
    pub distance_m: i64,
}

/// A point on the nearest indexed segment: `f` is the fraction of the segment from its start.
#[derive(Debug, Clone, Copy)]
struct Snap {
    seg: u32,
    f: f64,
}

/// Snapping radius of a query, in metres (FORMAT.md, "Query").
const SNAP_RADIUS_M: f64 = 200.0;
const NO_NODE: u32 = u32::MAX;
/// (weight, duration, distance), signed: the first label of an edge-based source is negative.
type Label = [i64; 3];

impl Mcr {
    fn seg_field(&self, s: u32, i: usize) -> u32 {
        u32at(self.section(3), s as usize * SEG_BYTES + i * 4)
    }

    /// The polyline of a segment (section 4), in 1e-6 degree.
    pub fn points(&self, s: u32) -> Vec<(i32, i32)> {
        let g = self.section(4);
        let (off, len) = (self.seg_field(s, 0) as usize, self.seg_field(s, 1) as usize);
        let b = &g[off..off + len];
        let mut p = vec![(u32at(b, 0) as i32, u32at(b, 4) as i32)];
        let mut i = 8;
        let varint = |i: &mut usize| {
            let (mut v, mut shift) = (0u32, 0);
            loop {
                let byte = b[*i];
                *i += 1;
                v |= ((byte & 0x7f) as u32) << shift;
                if byte & 0x80 == 0 {
                    return ((v >> 1) as i32) ^ -((v & 1) as i32);
                }
                shift += 7;
            }
        };
        while i < len {
            let (dla, dlo) = (varint(&mut i), varint(&mut i));
            let last = *p.last().unwrap();
            p.push((last.0 + dla, last.1 + dlo));
        }
        p
    }

    /// Segments indexed in the 3 x 3 cells around a point (sorted, without duplicates).
    fn candidates(&self, lat: i32, lon: i32) -> Vec<u32> {
        let dir = self.section(5);
        let lists = self.section(6);
        let n = dir.len() / 8;
        let (la, lo) = (
            (lat as i64 + 90_000_000).div_euclid(CELL),
            (lon as i64 + 180_000_000).div_euclid(CELL),
        );
        let mut out = Vec::new();
        for dla in -1..=1i64 {
            for dlo in -1..=1i64 {
                let key = ((la + dla) * 100_000 + lo + dlo) as u32;
                let (mut a, mut b) = (0, n);
                while a < b {
                    let m = (a + b) / 2;
                    if u32at(dir, m * 8) < key {
                        a = m + 1;
                    } else {
                        b = m;
                    }
                }
                if a < n && u32at(dir, a * 8) == key {
                    let at = u32at(dir, a * 8 + 4) as usize;
                    let count = u32at(lists, at) as usize;
                    out.extend((0..count).map(|k| u32at(lists, at + 4 + k * 4)));
                }
            }
        }
        out.sort_unstable();
        out.dedup();
        out
    }

    /// Nearest indexed segment within the radius, with the fraction along it of the projected point.
    fn snap(&self, lat: i32, lon: i32) -> Option<Snap> {
        let k = R_EARTH.to_radians() * 1e-6; // metres per 1e-6 degree of latitude
        let kx = k * (lat as f64 * 1e-6).to_radians().cos();
        let xy = |p: (i32, i32)| ((p.1 - lon) as f64 * kx, (p.0 - lat) as f64 * k);
        let mut best: Option<(f64, Snap)> = None;
        for s in self.candidates(lat, lon) {
            let pts: Vec<(f64, f64)> = self.points(s).into_iter().map(xy).collect();
            let total: f64 = pts.windows(2).map(|w| (w[1].0 - w[0].0).hypot(w[1].1 - w[0].1)).sum();
            let mut walked = 0.0;
            for w in pts.windows(2) {
                let (dx, dy) = (w[1].0 - w[0].0, w[1].1 - w[0].1);
                let len2 = dx * dx + dy * dy;
                let t = if len2 == 0.0 {
                    0.0
                } else {
                    ((-w[0].0 * dx - w[0].1 * dy) / len2).clamp(0.0, 1.0)
                };
                let d = (w[0].0 + t * dx).hypot(w[0].1 + t * dy);
                let f = if total == 0.0 {
                    0.0
                } else {
                    (walked + t * len2.sqrt()) / total
                };
                if d <= SNAP_RADIUS_M && best.is_none_or(|b| d < b.0) {
                    best = Some((d, Snap { seg: s, f }));
                }
                walked += len2.sqrt();
            }
        }
        best.map(|b| b.1)
    }

    /// Graph nodes an edge's end can start from (`sources`) or arrive at (`targets`), each with its label.
    fn labels(&self, snap: Snap, source: bool) -> Vec<(u32, Label)> {
        let s = snap.seg;
        let flags = self.seg_field(s, 3);
        let cost = |d: usize, fraction: f64| -> Label {
            let (w, t) = (self.seg_field(s, 8 + d) as f64, self.seg_field(s, 4 + d) as f64);
            let len = self.seg_field(s, 2) as f64;
            [
                (w * fraction).round() as i64,
                (t * fraction).round() as i64,
                (len * fraction).round() as i64,
            ]
        };
        let mut out = Vec::new();
        for d in 0..2usize {
            // allowed, and usable to start or end a route
            if flags & (1 << d) == 0 || flags & (4 << d) == 0 {
                continue;
            }
            let fd = if d == 0 { snap.f } else { 1.0 - snap.f };
            if self.edge_based {
                let node = self.seg_field(s, 6 + d);
                if node == NO_NODE {
                    continue;
                }
                let c = cost(d, fd);
                if source {
                    out.push((node, c.map(|v| -v)));
                } else {
                    out.push((node, c));
                    // a route may end on a copy of the segment that restrictions made: same label
                    let sh = self.section(7);
                    let n = sh.len() / 8;
                    let (mut i, mut hi) = (0, n);
                    while i < hi {
                        let m = (i + hi) / 2;
                        if u32at(sh, m * 8) < node {
                            i = m + 1;
                        } else {
                            hi = m;
                        }
                    }
                    while i < n && u32at(sh, i * 8) == node {
                        out.push((u32at(sh, i * 8 + 4), c));
                        i += 1;
                    }
                }
            } else if source {
                // leaving along d reaches the end junction of that direction after the rest of the segment
                out.push((self.seg_field(s, 7 - d), cost(d, 1.0 - fd)));
            } else {
                out.push((self.seg_field(s, 6 + d), cost(d, fd)));
            }
        }
        out
    }

    fn upward(&self, starts: &[(u32, Label)], fwd: bool) -> HashMap<u32, Label> {
        let mut best: HashMap<u32, Label> = HashMap::new();
        let mut heap = BinaryHeap::new();
        for &(n, l) in starts {
            if best.get(&n).is_none_or(|b| l[0] < b[0]) {
                best.insert(n, l);
                heap.push(Reverse((l[0], n)));
            }
        }
        while let Some(Reverse((w, u))) = heap.pop() {
            if best[&u][0] < w {
                continue;
            }
            let l = best[&u];
            for a in self.arcs(u, fwd) {
                let nl = [l[0] + a[1] as i64, l[1] + a[2] as i64, l[2] + a[3] as i64];
                if best.get(&a[0]).is_none_or(|b| nl[0] < b[0]) {
                    best.insert(a[0], nl);
                    heap.push(Reverse((nl[0], a[0])));
                }
            }
        }
        best
    }

    /// The route an instance must find between two points (1e-6 degree): snap, labels, bidirectional upward search
    /// (FORMAT.md, "Query"). `None` when an end is not within the radius of an indexed segment, or no path exists.
    pub fn route(&self, from: (i32, i32), to: (i32, i32)) -> Option<Route> {
        let (a, b) = (self.snap(from.0, from.1)?, self.snap(to.0, to.1)?);
        let f = self.upward(&self.labels(a, true), true);
        let r = self.upward(&self.labels(b, false), false);
        let mut best: Option<Label> = f
            .iter()
            .filter_map(|(n, x)| r.get(n).map(|y| [x[0] + y[0], x[1] + y[1], x[2] + y[2]]))
            .filter(|t| t[0] >= 0)
            .min();
        // Node-based graphs also compare with driving straight along the segment both points lie on.
        if !self.edge_based && a.seg == b.seg {
            let flags = self.seg_field(a.seg, 3);
            for d in 0..2usize {
                let (fa, fb) = if d == 0 { (a.f, b.f) } else { (1.0 - a.f, 1.0 - b.f) };
                if flags & (1 << d) != 0 && fb >= fa {
                    let (w, t) = (self.seg_field(a.seg, 8 + d) as f64, self.seg_field(a.seg, 4 + d) as f64);
                    let len = self.seg_field(a.seg, 2) as f64;
                    let k = fb - fa;
                    let direct = [(w * k).round() as i64, (t * k).round() as i64, (len * k).round() as i64];
                    if best.is_none_or(|x| direct[0] < x[0]) {
                        best = Some(direct);
                    }
                }
            }
        }
        best.map(|l| Route {
            weight: l[0],
            duration_ds: l[1],
            distance_m: l[2],
        })
    }

    /// Indexed segments (usable to start or end a route), the ones a route check may start from.
    pub fn snappable(&self) -> Vec<u32> {
        (0..self.segments)
            .filter(|&s| self.seg_field(s, 3) & 0b1100 != 0)
            .collect()
    }

    /// A point of the segment: the middle vertex, or the middle of the only edge.
    pub fn midpoint(&self, s: u32) -> (i32, i32) {
        let p = self.points(s);
        if p.len() % 2 == 1 {
            p[p.len() / 2]
        } else {
            let (a, b) = (p[p.len() / 2 - 1], p[p.len() / 2]);
            ((a.0 + b.0) / 2, (a.1 + b.1) / 2)
        }
    }
}
