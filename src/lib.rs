//! Offline builder of MapCova routing graphs (`.mcr`): OSM extract in, one graph file per zone and profile out.

pub mod ch;
pub mod edge;
pub mod file;
pub mod geom;
pub mod net;
pub mod node;
pub mod osm;
pub mod profile;
pub mod reader;
pub mod scc;
pub mod witness;

use ch::Arc;
use geom::{CELL, Pt, cell_key, simplify, varint, zigzag};
use net::{NONE, Net, SEG_NO_START, SEG_RESTRICTED_BWD, SEG_RESTRICTED_FWD};
use osm::OsmData;
use profile::Profile;
use std::collections::HashMap;
use std::time::Instant;

pub const BUILDER: &str = concat!("mcr ", env!("CARGO_PKG_VERSION"));

pub struct BuildOptions {
    pub zone: String,
    pub profile: Profile,
    /// Zone polygon rings (lat, lon in 1e-6 degree); empty = the bounding box of the network.
    pub polygon: Vec<Vec<Pt>>,
    /// Keep the arcs of the graph before contraction (tests compare against a plain Dijkstra).
    pub keep_arcs: bool,
    /// Unix time stamped as "built at" (0 = now).
    pub built_at: u32,
}

/// Which way and OSM nodes a segment follows (tests, diagnostics).
#[derive(Debug, Clone, PartialEq)]
pub struct SegInfo {
    pub way: i64,
    pub from: i64,
    pub to: i64,
}

pub struct Built {
    pub bytes: Vec<u8>,
    pub report: String,
    pub arcs: Vec<Arc>,
    pub new_id: Vec<u32>,
    pub segs: Vec<SegInfo>,
    /// edge-based: directed segment -> graph node before contraction
    pub cid: Vec<u32>,
    pub junction_of_node: Vec<u32>,
}

/// Osmosis `.poly` file: rings of `lon lat`, holes (`!name`) ignored.
pub fn parse_poly(text: &str) -> Vec<Vec<Pt>> {
    let mut rings = Vec::new();
    let mut cur: Option<(bool, Vec<Pt>)> = None;
    for (i, line) in text.lines().enumerate() {
        let l = line.trim();
        if i == 0 || l.is_empty() {
            continue;
        }
        if l == "END" {
            if let Some((hole, ring)) = cur.take()
                && !hole
                && ring.len() >= 3
            {
                rings.push(ring);
            }
        } else if cur.is_none() {
            cur = Some((l.starts_with('!'), Vec::new()));
        } else if let Some((_, ring)) = cur.as_mut() {
            let mut p = l.split_whitespace().filter_map(|x| x.parse::<f64>().ok());
            if let (Some(lon), Some(lat)) = (p.next(), p.next()) {
                ring.push(((lat * 1e6).round() as i32, (lon * 1e6).round() as i32));
            }
        }
    }
    rings.iter().map(|r| simplify(r, 200.0)).collect()
}

fn le(buf: &mut Vec<u8>, vals: &[u32]) {
    for v in vals {
        buf.extend_from_slice(&v.to_le_bytes());
    }
}

pub fn build_data(data: &OsmData, opt: &BuildOptions, log: &dyn Fn(&str)) -> Built {
    let profile = opt.profile;
    let net = Net::build(data, profile);
    let nseg = net.nseg();
    log(&format!("{nseg} segments, {} junctions", net.nj()));

    // the graph
    let arcs: Vec<Arc>;
    let n: usize;
    let segdir: Vec<u32>;
    let snap_dirs: Vec<[bool; 2]>;
    let shadows: Vec<(u32, u32)>;
    let cid: Vec<u32>;
    let mut kept_cid = Vec::new();
    let in_scc_nodes: Vec<bool>;
    if profile.edge_based() {
        let g = edge::build(&net);
        log(&format!("edge-based graph: {}", g.stats));
        n = g.cnodes.len();
        in_scc_nodes = scc::largest_scc(n, &g.arcs);
        segdir = g.cnodes.clone();
        // a segment whose own node cannot be entered any more (every way in goes through a copy made for a
        // via-way restriction) is on the main network when one of its copies is
        let mut reachable = in_scc_nodes.clone();
        for &(orig, copy) in &g.shadows {
            if in_scc_nodes[copy as usize] {
                reachable[orig as usize] = true;
            }
        }
        snap_dirs = (0..nseg)
            .map(|s| [0, 1].map(|d| g.cid[s * 2 + d] != NONE && reachable[g.cid[s * 2 + d] as usize]))
            .collect();
        shadows = g.shadows;
        kept_cid = g.cid.clone();
        cid = g.cid;
        arcs = g.arcs;
    } else {
        arcs = node::build(&net, profile);
        n = net.nj();
        log(&format!("node-based graph: {n} nodes, {} arcs", arcs.len()));
        in_scc_nodes = scc::largest_scc(n, &arcs);
        segdir = vec![0; n];
        snap_dirs = (0..nseg)
            .map(|s| {
                let (a, b) = net.seg_nodes[s];
                let (ja, jb) = (net.node_junc[a as usize], net.node_junc[b as usize]);
                let ok = ja != NONE && jb != NONE && in_scc_nodes[ja as usize] && in_scc_nodes[jb as usize];
                [ok && net.dur(s as u32 * 2) > 0, ok && net.dur(s as u32 * 2 + 1) > 0]
            })
            .collect();
        shadows = Vec::new();
        cid = Vec::new();
    }
    let n_scc = in_scc_nodes.iter().filter(|&&x| x).count();
    log(&format!("largest strongly connected component: {n_scc} of {n} nodes"));

    let arcs_copy = if opt.keep_arcs { arcs.clone() } else { Vec::new() };
    let ch = ch::contract(n, arcs, &segdir);
    log(&format!("CH: {} forward / {} backward upward arcs", ch.n_fwd, ch.n_bwd));
    let new_id = &ch.new_id;

    // segments and geometry
    let mut seg_buf: Vec<u8> = Vec::with_capacity(nseg * file::SEG_BYTES);
    let mut geom: Vec<u8> = Vec::new();
    let (mut minla, mut minlo, mut maxla, mut maxlo) = (i32::MAX, i32::MAX, i32::MIN, i32::MIN);
    let mut snappable = vec![false; nseg];
    for s in 0..nseg {
        let p = net.points(s);
        let off = geom.len() as u32;
        geom.extend_from_slice(&p[0].0.to_le_bytes());
        geom.extend_from_slice(&p[0].1.to_le_bytes());
        for k in 1..p.len() {
            varint(&mut geom, zigzag(p[k].0 - p[k - 1].0));
            varint(&mut geom, zigzag(p[k].1 - p[k - 1].1));
        }
        for q in p {
            minla = minla.min(q.0);
            maxla = maxla.max(q.0);
            minlo = minlo.min(q.1);
            maxlo = maxlo.max(q.1);
        }
        let start_ok = net.seg_flags[s] & SEG_NO_START == 0;
        let mut flags = (net.dur(s as u32 * 2) > 0) as u32 | (((net.dur(s as u32 * 2 + 1) > 0) as u32) << 1);
        for (d, &snap) in snap_dirs[s].iter().enumerate() {
            if snap && start_ok {
                flags |= 4 << d;
                snappable[s] = true;
            }
        }
        if net.seg_flags[s] & SEG_RESTRICTED_FWD != 0 {
            flags |= 16;
        }
        if net.seg_flags[s] & SEG_RESTRICTED_BWD != 0 {
            flags |= 32;
        }
        let nodes = if profile.edge_based() {
            [0, 1].map(|d| {
                if cid[s * 2 + d] == NONE {
                    NONE
                } else {
                    new_id[cid[s * 2 + d] as usize]
                }
            })
        } else {
            let (a, b) = net.seg_nodes[s];
            [
                new_id[net.node_junc[a as usize] as usize],
                new_id[net.node_junc[b as usize] as usize],
            ]
        };
        le(
            &mut seg_buf,
            &[
                off,
                geom.len() as u32 - off,
                net.seg_len[s].round() as u32,
                flags,
                net.seg_dur[s][0],
                net.seg_dur[s][1],
                nodes[0],
                nodes[1],
                net.seg_w[s][0],
                net.seg_w[s][1],
            ],
        );
    }

    // snapping grid
    let mut cells: HashMap<u32, Vec<u32>> = HashMap::new();
    for s in (0..nseg).filter(|&s| snappable[s]) {
        let p = net.points(s);
        let mut add = |la: i32, lo: i32| {
            let v = cells.entry(cell_key(la, lo)).or_default();
            if v.last() != Some(&(s as u32)) {
                v.push(s as u32);
            }
        };
        add(p[0].0, p[0].1);
        for k in 1..p.len() {
            let (a, b) = (p[k - 1], p[k]);
            let steps = (((b.0 - a.0).abs().max((b.1 - a.1).abs())) / 500).max(1);
            for t in 1..=steps {
                add(
                    a.0 + ((b.0 - a.0) as i64 * t as i64 / steps as i64) as i32,
                    a.1 + ((b.1 - a.1) as i64 * t as i64 / steps as i64) as i32,
                );
            }
        }
    }
    let mut keys: Vec<u32> = cells.keys().copied().collect();
    keys.sort_unstable();
    let (mut gdir, mut glist): (Vec<u8>, Vec<u8>) = (Vec::new(), Vec::new());
    for k in &keys {
        let mut v = cells[k].clone();
        v.sort_unstable();
        v.dedup();
        le(&mut gdir, &[*k, glist.len() as u32]);
        le(&mut glist, &[v.len() as u32]);
        le(&mut glist, &v);
    }

    // shadow nodes, sorted by original node
    let mut sh: Vec<(u32, u32)> = shadows
        .iter()
        .map(|&(o, s)| (new_id[o as usize], new_id[s as usize]))
        .collect();
    sh.sort_unstable();
    let mut shadow_buf = Vec::new();
    for (o, s) in &sh {
        le(&mut shadow_buf, &[*o, *s]);
    }

    // metadata
    // no polygon given: the bounding box is the zone (readers test the box, inclusive)
    let polygon = &opt.polygon;
    let (mut zb, mut any) = ([i32::MAX, i32::MAX, i32::MIN, i32::MIN], false);
    for r in &opt.polygon {
        for p in r {
            any = true;
            zb = [zb[0].min(p.0), zb[1].min(p.1), zb[2].max(p.0), zb[3].max(p.1)];
        }
    }
    let bbox = if any { zb } else { [minla, minlo, maxla, maxlo] };
    let built_at = if opt.built_at != 0 {
        opt.built_at
    } else {
        std::time::SystemTime::now()
            .duration_since(std::time::UNIX_EPOCH)
            .map(|d| d.as_secs() as u32)
            .unwrap_or(0)
    };
    let osm_ts = data.timestamp.unwrap_or(0).max(0) as u32;
    let poly_json: Vec<String> = polygon
        .iter()
        .map(|r| {
            format!(
                "[{}]",
                r.iter()
                    .map(|p| format!("[{:.6},{:.6}]", p.0 as f64 * 1e-6, p.1 as f64 * 1e-6))
                    .collect::<Vec<_>>()
                    .join(",")
            )
        })
        .collect();
    let meta = format!(
        "{{\"format\":{},\"zone\":{},\"profile\":{},\"graph\":{},\"toll_segments\":{},\"osm_timestamp\":{},\"built_at\":{},\"builder\":{},\"bbox\":[{:.6},{:.6},{:.6},{:.6}],\"polygon\":[{}],\"license\":{},\"attribution\":{}}}",
        file::VERSION,
        file::json_str(&opt.zone),
        file::json_str(profile.name()),
        file::json_str(if profile.edge_based() { "edge" } else { "node" }),
        net.toll_segments,
        osm_ts,
        built_at,
        file::json_str(BUILDER),
        bbox[0] as f64 * 1e-6,
        bbox[1] as f64 * 1e-6,
        bbox[2] as f64 * 1e-6,
        bbox[3] as f64 * 1e-6,
        poly_json.join(","),
        file::json_str(file::LICENSE),
        file::json_str(file::ATTRIBUTION),
    )
    .into_bytes();

    let header = file::Header {
        profile: profile.id(),
        edge_based: profile.edge_based(),
        nodes: n as u32,
        up_fwd: ch.n_fwd,
        up_bwd: ch.n_bwd,
        segments: nseg as u32,
        cells: keys.len() as u32,
        bbox,
        cell: CELL as u32,
        osm_timestamp: osm_ts,
        built_at,
        builder: BUILDER.to_string(),
        zone: opt.zone.clone(),
    };
    let sections: [&Vec<u8>; 9] = [
        &ch.nodes,
        &ch.up_fwd,
        &ch.up_bwd,
        &seg_buf,
        &geom,
        &gdir,
        &glist,
        &shadow_buf,
        &meta,
    ];
    let (bytes, _) = file::assemble(&header, &sections);
    let mut report = String::new();
    for (i, s) in sections.iter().enumerate() {
        report.push_str(&format!(
            "  section {:<10} {:>12} bytes\n",
            file::SECTION_NAMES[i],
            s.len()
        ));
    }
    let segs = (0..nseg)
        .map(|s| SegInfo {
            way: net.way_ids[net.seg_way[s] as usize],
            from: net.node_ids[net.seg_nodes[s].0 as usize],
            to: net.node_ids[net.seg_nodes[s].1 as usize],
        })
        .collect();
    Built {
        bytes,
        report,
        arcs: arcs_copy,
        new_id: ch.new_id,
        segs,
        cid: kept_cid,
        junction_of_node: net.node_junc.clone(),
    }
}

/// Text of the arcs before contraction, one `src dst weight duration distance` line per arc, sorted. The PHP builder
/// (`php/`) writes the same list: diffing the two checks that both build the same graph.
pub fn dump_arcs_text(arcs: &[Arc]) -> String {
    let mut rows: Vec<[u32; 5]> = arcs.iter().map(|a| [a.src, a.dst, a.w, a.t, a.d]).collect();
    rows.sort_unstable();
    let mut text = String::with_capacity(rows.len() * 24);
    for r in rows {
        text.push_str(&format!("{} {} {} {} {}\n", r[0], r[1], r[2], r[3], r[4]));
    }
    text
}

/// Reads `input` (an OSM PBF extract) and writes the graph to `out`; with `dump_arcs`, also the arcs before
/// contraction (see `dump_arcs_text`; `opt.keep_arcs` must be set).
pub fn build_file(input: &str, out: &str, opt: &BuildOptions, dump_arcs: Option<&str>) -> Result<(), String> {
    let t0 = Instant::now();
    let log = |what: &str| eprintln!("[{:7.1}s] {}", t0.elapsed().as_secs_f64(), what);
    let data = osm::read(input, opt.profile, &log)?;
    let built = build_data(&data, opt, &log);
    drop(data);
    file::write(out, &built.bytes).map_err(|e| format!("cannot write {out}: {e}"))?;
    if let Some(path) = dump_arcs {
        std::fs::write(path, dump_arcs_text(&built.arcs)).map_err(|e| format!("cannot write {path}: {e}"))?;
    }
    eprint!("{}", built.report);
    log(&format!("wrote {out} ({} bytes)", built.bytes.len()));
    Ok(())
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn arc_dump_is_sorted_and_keeps_parallel_arcs() {
        let a = |src, dst, w, t, d| Arc {
            src,
            dst,
            w,
            t,
            d,
            sd: net::NONE,
        };
        let text = dump_arcs_text(&[a(2, 1, 5, 5, 9), a(0, 3, 7, 6, 1), a(0, 3, 7, 5, 2), a(0, 1, 1, 1, 1)]);
        assert_eq!(text, "0 1 1 1 1\n0 3 7 5 2\n0 3 7 6 1\n2 1 5 5 9\n");
    }
}
