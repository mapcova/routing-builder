//! Node-based graph (bike, foot): a node is a junction, an arc follows one segment in one direction.
//! No turn costs. Traffic signals cost 2 s on arriving at the junction; a barrier junction is a dead end.

use crate::ch::Arc;
use crate::net::{NONE, Net};
use crate::profile::{NODE_BARRIER, NODE_SIGNALS, Profile};

pub const SIGNAL_PENALTY: u32 = 20;
/// Bikes: OSRM charges up to 6 s for a turn at a junction (angle squared), and a route mostly goes straight on. A
/// node-based graph has no turns, so each arrival at a junction of three roads or more costs a flat 1 s instead
/// (tenths of a second): calibrated on Luxembourg, where it brings the median duration bias against OSRM to 0.
pub const BIKE_JUNCTION_PENALTY: u32 = 10;

pub fn build(net: &Net, profile: Profile) -> Vec<Arc> {
    let mut arcs = Vec::new();
    for s in 0..net.nseg() {
        let (a, b) = net.seg_nodes[s];
        let (ja, jb) = (net.node_junc[a as usize], net.node_junc[b as usize]);
        if ja == NONE || jb == NONE {
            continue;
        }
        let blocked = |n: u32| net.nflags[n as usize] & NODE_BARRIER != 0;
        if blocked(a) || blocked(b) {
            continue;
        }
        for dir in 0..2u32 {
            let d = (s as u32) * 2 + dir;
            if net.dur(d) == 0 {
                continue;
            }
            let (src, dst, to) = if dir == 0 { (ja, jb, b) } else { (jb, ja, a) };
            let mut pen = if net.nflags[to as usize] & NODE_SIGNALS != 0 {
                SIGNAL_PENALTY
            } else {
                0
            };
            if profile == Profile::Bike && net.incident(dst as usize).len() > 2 {
                pen += BIKE_JUNCTION_PENALTY;
            }
            arcs.push(Arc {
                src,
                dst,
                w: net.weight(d) + pen,
                t: net.dur(d) + pen,
                d: net.len_m(d),
                sd: d,
            });
        }
    }
    arcs
}
