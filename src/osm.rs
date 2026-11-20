//! Reading an OSM PBF extract: two passes (ways and restrictions, then the nodes the ways use).

use crate::profile::{Profile, Tags, Way, way_key_of_interest};
use osmpbf::{BlobDecode, BlobReader, Element, ElementReader, RelMemberType};

pub struct RawWay {
    pub id: i64,
    pub refs: Vec<i64>,
    pub way: Way,
}

/// A `restriction` relation: `no_*` or `only_*`, via one node or one or more ways.
#[derive(Debug, Clone)]
pub struct Restriction {
    pub from: i64,
    pub via_node: Option<i64>,
    pub via_ways: Vec<i64>,
    pub to: i64,
    pub only: bool,
}

#[derive(Debug, Clone, Copy)]
pub struct NodeRec {
    pub id: i64,
    /// 1e-6 degree
    pub lat: i32,
    pub lon: i32,
    pub flags: u8,
}

/// Everything the graph builders need from an extract; also what the tests build by hand.
#[derive(Default)]
pub struct OsmData {
    pub ways: Vec<RawWay>,
    pub restrictions: Vec<Restriction>,
    /// Sorted by id.
    pub nodes: Vec<NodeRec>,
    /// Unix time of the extract's data (replication timestamp), when the file says.
    pub timestamp: Option<i64>,
}

pub fn read_timestamp(path: &str) -> Option<i64> {
    let blob = BlobReader::from_path(path).ok()?.flatten().next()?;
    match blob.decode().ok()? {
        BlobDecode::OsmHeader(h) => h.osmosis_replication_timestamp(),
        _ => None,
    }
}

type Pass1 = (Vec<RawWay>, Vec<Restriction>);

fn pass1(path: &str, profile: Profile) -> Result<Pass1, String> {
    let reader = ElementReader::from_path(path).map_err(|e| format!("cannot open {path}: {e}"))?;
    reader
        .par_map_reduce(
            |el| {
                let (mut ways, mut rs) = (Vec::new(), Vec::new());
                match el {
                    Element::Way(w) => {
                        if w.tags().any(|(k, _)| way_key_of_interest(k)) {
                            let t = Tags(w.tags().map(|(k, v)| (k.to_string(), v.to_string())).collect());
                            if let Some(way) = profile.way(&t) {
                                ways.push(RawWay {
                                    id: w.id(),
                                    refs: w.refs().collect(),
                                    way,
                                });
                            }
                        }
                    }
                    Element::Relation(r) => {
                        let (mut is_r, mut val, mut exempt) = (false, String::new(), false);
                        for (k, v) in r.tags() {
                            match k {
                                "type" => is_r = v == "restriction",
                                "restriction" => val = v.to_string(),
                                "restriction:motorcar" | "restriction:motor_vehicle" if profile.edge_based() => {
                                    val = v.to_string()
                                }
                                "except" => exempt = profile.exempt(v),
                                _ => {}
                            }
                        }
                        if is_r && !exempt && (val.starts_with("no_") || val.starts_with("only_")) {
                            let (mut from, mut to, mut via_node) = (None, None, None);
                            let mut via_ways = Vec::new();
                            for m in r.members() {
                                match (m.role().unwrap_or(""), &m.member_type) {
                                    ("from", RelMemberType::Way) => from = Some(m.member_id),
                                    ("to", RelMemberType::Way) => to = Some(m.member_id),
                                    ("via", RelMemberType::Node) => via_node = Some(m.member_id),
                                    ("via", RelMemberType::Way) => via_ways.push(m.member_id),
                                    _ => {}
                                }
                            }
                            if let (Some(from), Some(to)) = (from, to)
                                && via_node.is_some() != !via_ways.is_empty()
                            {
                                rs.push(Restriction {
                                    from,
                                    via_node,
                                    via_ways,
                                    to,
                                    only: val.starts_with("only_"),
                                });
                            }
                        }
                    }
                    _ => {}
                }
                (ways, rs)
            },
            || (Vec::new(), Vec::new()),
            |mut a, b| {
                a.0.extend(b.0);
                a.1.extend(b.1);
                a
            },
        )
        .map_err(|e| format!("cannot read {path}: {e}"))
}

fn pass2(path: &str, ids: &[i64], profile: Profile) -> Result<Vec<(u32, i32, i32, u8)>, String> {
    let reader = ElementReader::from_path(path).map_err(|e| format!("cannot open {path}: {e}"))?;
    let conv = |d: i32| ((d as f64) / 10.0).round() as i32;
    reader
        .par_map_reduce(
            |el| {
                let mut out = Vec::new();
                match el {
                    Element::Node(n) => {
                        if let Ok(i) = ids.binary_search(&n.id()) {
                            out.push((
                                i as u32,
                                conv(n.decimicro_lat()),
                                conv(n.decimicro_lon()),
                                profile.node(n.tags()),
                            ));
                        }
                    }
                    Element::DenseNode(n) => {
                        if let Ok(i) = ids.binary_search(&n.id()) {
                            out.push((
                                i as u32,
                                conv(n.decimicro_lat()),
                                conv(n.decimicro_lon()),
                                profile.node(n.tags()),
                            ));
                        }
                    }
                    _ => {}
                }
                out
            },
            Vec::new,
            |mut a, b| {
                a.extend(b);
                a
            },
        )
        .map_err(|e| format!("cannot read {path}: {e}"))
}

pub fn read(path: &str, profile: Profile, log: &dyn Fn(&str)) -> Result<OsmData, String> {
    let (ways, mut restrictions) = pass1(path, profile)?;
    if !profile.edge_based() {
        restrictions.clear();
    }
    log(&format!(
        "pass 1: {} ways, {} restrictions",
        ways.len(),
        restrictions.len()
    ));
    let mut ids: Vec<i64> = ways.iter().flat_map(|w| w.refs.iter().copied()).collect();
    ids.sort_unstable();
    ids.dedup();
    let mut nodes: Vec<NodeRec> = ids
        .iter()
        .map(|&id| NodeRec {
            id,
            lat: i32::MIN,
            lon: i32::MIN,
            flags: 0,
        })
        .collect();
    for (i, la, lo, f) in pass2(path, &ids, profile)? {
        let n = &mut nodes[i as usize];
        n.lat = la;
        n.lon = lo;
        n.flags |= f;
    }
    nodes.retain(|n| n.lat != i32::MIN);
    log(&format!("pass 2: {} nodes", nodes.len()));
    Ok(OsmData {
        ways,
        restrictions,
        nodes,
        timestamp: read_timestamp(path),
    })
}
