//! Writes the tiny test city used by the PHP extension's tests: one `.mcr` per profile and
//! `expected.json`, the costs between pairs of graph nodes as computed by this crate's own reader.
//!
//!   cargo run --example fixture -- <output dir>
//!
//! The city: a 5x5 grid of residential streets (row 0 one way eastwards, column 4 one way southwards),
//! a toll motorway across the diagonal, a small block east of it with a via-way restriction, and an
//! island of two roads that no route can reach.

use mcr::osm::{NodeRec, OsmData, RawWay, Restriction};
use mcr::profile::{Profile, Tags};
use mcr::reader::Mcr;
use mcr::{BuildOptions, build_data};

const LAT0: i32 = 49_600_000;
const LON0: i32 = 6_100_000;

fn id(x: i64, y: i64) -> i64 {
    y * 100 + x + 1
}

fn city(profile: Profile) -> OsmData {
    let mut d = OsmData::default();
    let mut at = |x: i64, y: i64| {
        d.nodes.push(NodeRec {
            id: id(x, y),
            lat: LAT0 + y as i32 * 1000,
            lon: LON0 + x as i32 * 1000,
            flags: 0,
        })
    };
    for y in 0..5 {
        for x in 0..5 {
            at(x, y);
        }
    }
    for y in 0..3 {
        for x in 6..10 {
            at(x, y);
        }
    }
    at(20, 20);
    at(21, 20);
    d.nodes.sort_by_key(|n| n.id);
    let mut way = |wid: i64, refs: &[i64], tags: &[(&str, &str)]| {
        if let Some(w) = profile.way(&Tags::new(tags)) {
            d.ways.push(RawWay {
                id: wid,
                refs: refs.to_vec(),
                way: w,
            });
        }
    };
    let res = ("highway", "residential");
    for y in 0..5 {
        let refs: Vec<i64> = (0..5).map(|x| id(x, y)).collect();
        if y == 0 {
            way(1 + y, &refs, &[res, ("oneway", "yes")]);
        } else {
            way(1 + y, &refs, &[res]);
        }
    }
    for x in 0..5 {
        let refs: Vec<i64> = (0..5).map(|y| id(x, y)).collect();
        if x == 4 {
            way(11 + x, &refs, &[res, ("oneway", "yes")]);
        } else {
            way(11 + x, &refs, &[res]);
        }
    }
    way(30, &[id(0, 0), id(4, 4)], &[("highway", "motorway"), ("toll", "yes")]);
    // the block east of the grid, joined to it by a connector
    way(40, &[id(4, 0), id(6, 0)], &[res]);
    way(41, &[id(6, 0), id(7, 0), id(8, 0), id(9, 0)], &[res]);
    way(42, &[id(7, 2), id(7, 1), id(7, 0)], &[res]);
    way(43, &[id(8, 0), id(8, 1), id(8, 2)], &[res]);
    way(44, &[id(7, 1), id(8, 1)], &[res]);
    way(45, &[id(7, 2), id(8, 2)], &[res]);
    way(46, &[id(8, 1), id(9, 1)], &[res]);
    way(47, &[id(9, 1), id(9, 0)], &[res]);
    // island
    way(50, &[id(20, 20), id(21, 20)], &[res]);
    if profile.edge_based() {
        d.restrictions.push(Restriction {
            from: 42,
            via_node: None,
            via_ways: vec![44],
            to: 43,
            only: false,
        });
    }
    d
}

fn main() {
    let dir = std::env::args().nth(1).expect("usage: fixture <output dir>");
    std::fs::create_dir_all(&dir).unwrap();
    let mut expected = String::from("{\n");
    let profiles = [Profile::Car, Profile::CarNoToll, Profile::Bike, Profile::Foot];
    for (k, profile) in profiles.into_iter().enumerate() {
        let opt = BuildOptions {
            zone: "city".into(),
            profile,
            polygon: Vec::new(),
            keep_arcs: false,
            built_at: 1_700_000_000,
        };
        let built = build_data(&city(profile), &opt, &|_| {});
        std::fs::write(format!("{dir}/city-{}.mcr", profile.name()), &built.bytes).unwrap();
        let m = Mcr::from_bytes(built.bytes).unwrap();
        let mut rows = Vec::new();
        for src in 0..m.nodes {
            for dst in 0..m.nodes {
                if (src + 3 * dst) % 17 == 0 {
                    match m.query(src, dst) {
                        Some((w, t, dist)) => rows.push(format!("[{src},{dst},{w},{t},{dist}]")),
                        None => rows.push(format!("[{src},{dst},null,null,null]")),
                    }
                }
            }
        }
        expected.push_str(&format!(
            "  \"{}\": [{}]{}\n",
            profile.name(),
            rows.join(","),
            if k + 1 < 4 { "," } else { "" }
        ));
    }
    expected.push_str("}\n");
    std::fs::write(format!("{dir}/expected.json"), expected).unwrap();
}
