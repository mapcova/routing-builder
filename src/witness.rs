//! Route checks ("witnesses") of a graph: pairs of points with the duration the reference reader finds between them.
//! They travel in the signed manifest; an instance replays them on the file it installed and refuses a file that
//! does not reproduce them.

use crate::reader::Mcr;

/// One route check: both ends in 1e-6 degree, the expected duration in tenths of a second.
#[derive(Debug, Clone, PartialEq)]
pub struct Witness {
    pub from: (i32, i32),
    pub to: (i32, i32),
    pub duration_ds: i64,
}

fn lcg(s: &mut u64) -> u64 {
    *s = s.wrapping_mul(6364136223846793005).wrapping_add(1442695040888963407);
    *s >> 33
}

/// `count` checks spread over the graph, the same for the same file and `seed`. Each starts and ends at the middle of
/// an indexed segment and is a route the reader finds (at least one second long). Fewer than `count` come back only
/// when the graph has too few connected places.
pub fn witnesses(m: &Mcr, count: usize, seed: u64) -> Vec<Witness> {
    let segs = m.snappable();
    let mut out = Vec::new();
    if segs.len() < 2 {
        return out;
    }
    let mut s = seed ^ 0x9e37_79b9_7f4a_7c15;
    for _ in 0..count * 200 {
        if out.len() == count {
            break;
        }
        let a = segs[lcg(&mut s) as usize % segs.len()];
        let b = segs[lcg(&mut s) as usize % segs.len()];
        if a == b {
            continue;
        }
        let (from, to) = (m.midpoint(a), m.midpoint(b));
        if let Some(r) = m.route(from, to).filter(|r| r.duration_ds >= 10) {
            out.push(Witness {
                from,
                to,
                duration_ds: r.duration_ds,
            });
        }
    }
    out
}

/// The list the `data:pack-graph` command of the updates server takes: `[{"from": [lat, lon], "to": [lat, lon], "duration_ds": n}, ...]`.
pub fn to_json(w: &[Witness]) -> String {
    let items: Vec<String> = w
        .iter()
        .map(|x| {
            format!(
                "{{\"from\":[{},{}],\"to\":[{},{}],\"duration_ds\":{}}}",
                x.from.0, x.from.1, x.to.0, x.to.1, x.duration_ds
            )
        })
        .collect();
    format!("[{}]\n", items.join(","))
}
