//! Tiny synthetic maps built in memory: the contracted graph against a plain Dijkstra on the same arcs,
//! then the behaviour of restrictions, ferries, local-access roads, tolls, one-way streets and the profiles.

use mcr::ch::Arc;
use mcr::osm::{NodeRec, OsmData, RawWay, Restriction};
use mcr::profile::{Profile, Tags};
use mcr::reader::Mcr;
use mcr::{BuildOptions, Built, build_data};
use std::cmp::Reverse;
use std::collections::BinaryHeap;

const LAT0: i32 = 49_600_000;
const LON0: i32 = 6_100_000;

fn id(x: i64, y: i64) -> i64 {
    y * 100 + x + 1
}

fn grid(w: i64, h: i64) -> OsmData {
    let mut d = OsmData::default();
    for y in 0..h {
        for x in 0..w {
            d.nodes.push(NodeRec {
                id: id(x, y),
                lat: LAT0 + (y as i32) * 1000,
                lon: LON0 + (x as i32) * 1000,
                flags: 0,
            });
        }
    }
    d.nodes.sort_by_key(|n| n.id);
    d
}

fn way(d: &mut OsmData, profile: Profile, wid: i64, refs: &[i64], tags: &[(&str, &str)]) {
    let w = profile
        .way(&Tags::new(tags))
        .unwrap_or_else(|| panic!("way {wid} not routable"));
    d.ways.push(RawWay {
        id: wid,
        refs: refs.to_vec(),
        way: w,
    });
}

fn build(d: &OsmData, profile: Profile) -> Built {
    let opt = BuildOptions {
        zone: "test".into(),
        profile,
        polygon: Vec::new(),
        keep_arcs: true,
        built_at: 1,
    };
    build_data(d, &opt, &|_| {})
}

/// Graph node (file id) of the segment of `wid` going `from` -> `to`.
fn dir_node(b: &Built, wid: i64, from: i64, to: i64) -> u32 {
    for (s, info) in b.segs.iter().enumerate() {
        if info.way == wid && info.from == from && info.to == to {
            return b.new_id[b.cid[s * 2] as usize];
        }
        if info.way == wid && info.from == to && info.to == from {
            return b.new_id[b.cid[s * 2 + 1] as usize];
        }
    }
    panic!("no segment of way {wid} between {from} and {to}");
}

fn cost(m: &Mcr, a: u32, z: u32) -> Option<(u32, u32, u32)> {
    m.query(a, z)
}

fn dijkstra(arcs: &[Arc], new_id: &[u32], n: usize, src: u32) -> Vec<u32> {
    let mut adj: Vec<Vec<(u32, u32)>> = vec![Vec::new(); n];
    for a in arcs {
        adj[new_id[a.src as usize] as usize].push((new_id[a.dst as usize], a.w.max(1)));
    }
    let mut dist = vec![u32::MAX; n];
    let mut h = BinaryHeap::new();
    dist[src as usize] = 0;
    h.push(Reverse((0u32, src)));
    while let Some(Reverse((d, u))) = h.pop() {
        if d > dist[u as usize] {
            continue;
        }
        for &(v, w) in &adj[u as usize] {
            if d + w < dist[v as usize] {
                dist[v as usize] = d + w;
                h.push(Reverse((d + w, v)));
            }
        }
    }
    dist
}

fn lcg(s: &mut u64) -> u64 {
    *s = s.wrapping_mul(6364136223846793005).wrapping_add(1442695040888963407);
    *s >> 33
}

fn random_city(profile: Profile, seed: u64) -> OsmData {
    let mut d = grid(7, 7);
    let mut s = seed;
    let mut wid = 1;
    for y in 0..7 {
        let refs: Vec<i64> = (0..7).map(|x| id(x, y)).collect();
        let ow = ["yes", "no", "-1", "no"][lcg(&mut s) as usize % 4];
        way(
            &mut d,
            profile,
            wid,
            &refs,
            &[
                ("highway", ["residential", "primary", "tertiary"][y as usize % 3]),
                ("oneway", ow),
            ],
        );
        wid += 1;
    }
    for x in 0..7 {
        let refs: Vec<i64> = (0..7).map(|y| id(x, y)).collect();
        let ow = ["yes", "no", "no", "-1"][lcg(&mut s) as usize % 4];
        way(
            &mut d,
            profile,
            wid,
            &refs,
            &[("highway", "residential"), ("oneway", ow)],
        );
        wid += 1;
    }
    d
}

#[test]
fn contracted_graph_equals_dijkstra_edge_based() {
    for seed in [1, 2, 3] {
        let d = random_city(Profile::Car, seed);
        let b = build(&d, Profile::Car);
        let m = Mcr::from_bytes(b.bytes.clone()).unwrap();
        let n = m.nodes as usize;
        let mut checked = 0;
        for src in (0..n as u32).step_by(3) {
            let reference = dijkstra(&b.arcs, &b.new_id, n, src);
            for dst in 0..n as u32 {
                let ch = m.query(src, dst).map(|x| x.0);
                let want = (reference[dst as usize] != u32::MAX).then_some(reference[dst as usize]);
                assert_eq!(ch, want, "seed {seed}: {src} -> {dst}");
                checked += 1;
            }
        }
        assert!(checked > 1000);
    }
}

#[test]
fn contracted_graph_equals_dijkstra_node_based() {
    for profile in [Profile::Bike, Profile::Foot] {
        let d = random_city(profile, 7);
        let b = build(&d, profile);
        let m = Mcr::from_bytes(b.bytes.clone()).unwrap();
        assert!(!m.edge_based);
        let n = m.nodes as usize;
        for src in 0..n as u32 {
            let reference = dijkstra(&b.arcs, &b.new_id, n, src);
            for dst in 0..n as u32 {
                let want = (reference[dst as usize] != u32::MAX).then_some(reference[dst as usize]);
                assert_eq!(m.query(src, dst).map(|x| x.0), want, "{profile:?} {src} -> {dst}");
            }
        }
    }
}

/// A plus-shaped crossing in a 3x3 grid of two-way streets.
fn plus(profile: Profile) -> OsmData {
    let mut d = grid(3, 3);
    let t = [("highway", "residential")];
    // rows and columns, one way each; way ids: rows 1..3, columns 11..13
    for y in 0..3 {
        way(&mut d, profile, 1 + y, &[id(0, y), id(1, y), id(2, y)], &t);
    }
    for x in 0..3 {
        way(&mut d, profile, 11 + x, &[id(x, 0), id(x, 1), id(x, 2)], &t);
    }
    d
}

fn restr(from: i64, via: i64, to: i64, only: bool) -> Restriction {
    Restriction {
        from,
        via_node: Some(via),
        via_ways: vec![],
        to,
        only,
    }
}

#[test]
fn turn_restrictions_at_a_node() {
    let free = plus(Profile::Car);
    let b0 = build(&free, Profile::Car);
    let m0 = Mcr::from_bytes(b0.bytes.clone()).unwrap();
    // west -> centre (row 2 is y=1), then turn north onto column 12 (x=1): ids
    let (w, c, n) = (id(0, 1), id(1, 1), id(1, 2));
    let into = dir_node(&b0, 2, w, c);
    let out = dir_node(&b0, 12, c, n);
    let base = cost(&m0, into, out).unwrap().0;

    let mut banned = plus(Profile::Car);
    banned.restrictions.push(restr(2, c, 12, false));
    let b1 = build(&banned, Profile::Car);
    let m1 = Mcr::from_bytes(b1.bytes.clone()).unwrap();
    let detour = cost(&m1, dir_node(&b1, 2, w, c), dir_node(&b1, 12, c, n)).unwrap().0;
    assert!(
        detour > base + 300,
        "no_* restriction must force a detour: {base} vs {detour}"
    );
    // the unrelated turn east is unchanged
    let east0 = cost(&m0, dir_node(&b0, 2, w, c), dir_node(&b0, 2, c, id(2, 1)))
        .unwrap()
        .0;
    let east1 = cost(&m1, dir_node(&b1, 2, w, c), dir_node(&b1, 2, c, id(2, 1)))
        .unwrap()
        .0;
    assert_eq!(east0, east1);

    // only_straight_on from the west: the turn north is gone too
    let mut only = plus(Profile::Car);
    only.restrictions.push(restr(2, c, 2, true));
    let b2 = build(&only, Profile::Car);
    let m2 = Mcr::from_bytes(b2.bytes.clone()).unwrap();
    let north = cost(&m2, dir_node(&b2, 2, w, c), dir_node(&b2, 12, c, n)).unwrap().0;
    assert!(north > base + 300);
}

#[test]
fn via_way_restriction_forbids_only_the_whole_sequence() {
    // a 4x1 corridor A - B - C - D along way 1 (west to east), a street E north of B along way 2,
    // a street F north of C along way 3 and a link road way 4 between B and C (the via way);
    // "no_right_turn from way 2 via way 4 to way 3"-like: coming down way 2 into B, taking way 4 east to C
    // and turning north into way 3 is forbidden, any other use of way 4 stays.
    let t = [("highway", "residential")];
    let build_map = |with: bool| {
        let mut d = grid(4, 3);
        way(&mut d, Profile::Car, 1, &[id(0, 0), id(1, 0), id(2, 0), id(3, 0)], &t);
        way(&mut d, Profile::Car, 2, &[id(1, 2), id(1, 1), id(1, 0)], &t);
        way(&mut d, Profile::Car, 3, &[id(2, 0), id(2, 1), id(2, 2)], &t);
        way(&mut d, Profile::Car, 4, &[id(1, 1), id(2, 1)], &t);
        way(&mut d, Profile::Car, 5, &[id(1, 2), id(2, 2)], &t);
        if with {
            d.restrictions.push(Restriction {
                from: 2,
                via_node: None,
                via_ways: vec![4],
                to: 3,
                only: false,
            });
        }
        d
    };
    // way 2 runs (1,2) -> (1,1) -> (1,0); way 4 leaves it at (1,1); way 3 north is (2,1) -> (2,2)
    let (b_off, b_on) = (
        build(&build_map(false), Profile::Car),
        build(&build_map(true), Profile::Car),
    );
    let (m_off, m_on) = (
        Mcr::from_bytes(b_off.bytes.clone()).unwrap(),
        Mcr::from_bytes(b_on.bytes.clone()).unwrap(),
    );
    let probe = |b: &Built, m: &Mcr, from_way: i64| {
        let a = if from_way == 2 {
            dir_node(b, 2, id(1, 2), id(1, 1))
        } else {
            dir_node(b, 4, id(1, 1), id(2, 1))
        };
        let z = dir_node(b, 3, id(2, 1), id(2, 2));
        cost(m, a, z).unwrap().0
    };
    // arriving by way 2 then using way 4 then way 3: forbidden
    let off = probe(&b_off, &m_off, 2);
    let on = probe(&b_on, &m_on, 2);
    assert!(
        on > off + 100,
        "the restricted sequence must cost a detour: {off} vs {on}"
    );
    // starting on the via way itself is not restricted
    assert_eq!(probe(&b_off, &m_off, 4), probe(&b_on, &m_on, 4));
    let shadows = Mcr::from_bytes(b_on.bytes.clone()).unwrap();
    assert!(shadows.sections[7].1 >= 8, "a copy of the via segment must exist");
    assert_eq!(Mcr::from_bytes(b_off.bytes.clone()).unwrap().sections[7].1, 0);
}

#[test]
fn ferry_and_shuttle_train_use_their_duration() {
    let mut d = grid(3, 1);
    let t = [("highway", "residential")];
    way(&mut d, Profile::Car, 1, &[id(0, 0), id(1, 0)], &t);
    way(
        &mut d,
        Profile::Car,
        2,
        &[id(1, 0), id(2, 0)],
        &[("route", "ferry"), ("duration", "00:30")],
    );
    let b = build(&d, Profile::Car);
    let m = Mcr::from_bytes(b.bytes.clone()).unwrap();
    let ferry = b.segs.iter().position(|s| s.way == 2).unwrap() as u32;
    let seg = m.segment(ferry);
    assert_eq!(seg.dur, [18_000, 18_000], "30 minutes in 1/10 s, both directions");
    assert_eq!(seg.flags & 0b1100, 0, "a ferry is not a place to start a route");
    // and it connects the road network
    let a = dir_node(&b, 1, id(0, 0), id(1, 0));
    let z = dir_node(&b, 2, id(1, 0), id(2, 0));
    let (_, t, _) = cost(&m, a, z).unwrap();
    assert!(t > 0);
}

#[test]
fn local_access_roads_are_never_a_through_route() {
    // two ways between X and Y: a long open road and a short `destination` shortcut
    let mut d = grid(3, 2);
    let open = [("highway", "residential")];
    way(&mut d, Profile::Car, 1, &[id(0, 0), id(1, 0), id(2, 0)], &open);
    way(
        &mut d,
        Profile::Car,
        2,
        &[id(0, 0), id(0, 1), id(1, 1), id(2, 1), id(2, 0)],
        &open,
    );
    // a destination-only link crossing the block
    way(
        &mut d,
        Profile::Car,
        3,
        &[id(1, 0), id(1, 1)],
        &[("highway", "residential"), ("motor_vehicle", "destination")],
    );
    let b = build(&d, Profile::Car);
    let m = Mcr::from_bytes(b.bytes.clone()).unwrap();
    // west end to east end of the bottom row: the shortcut gives nothing here, but going from (0,1) to (2,1)
    // along the top is shorter than via the link; check the link is penalised: top row path vs link path
    let from = dir_node(&b, 2, id(0, 0), id(1, 1));
    let to = dir_node(&b, 2, id(1, 1), id(2, 0));
    let (w, t, _) = cost(&m, from, to).unwrap();
    assert!(
        w < 100_000,
        "the open road answers, no penalty leaks into the weight: {w}"
    );
    assert_eq!(w, t, "no rate penalties here, weight = duration");
    // reaching the destination road itself costs the entry penalty in the weight, not in the duration
    let link = dir_node(&b, 3, id(1, 0), id(1, 1));
    let (w2, t2, _) = cost(&m, dir_node(&b, 1, id(0, 0), id(1, 0)), link).unwrap();
    assert!(
        w2 > 1_000_000,
        "entering local-access-only road from the open network: {w2}"
    );
    assert!(t2 < 3_000, "but its duration stays real: {t2}");
}

#[test]
fn tolls_are_excluded_from_the_notoll_graph() {
    let make = |profile: Profile| {
        let mut d = grid(3, 1);
        way(&mut d, profile, 1, &[id(0, 0), id(1, 0)], &[("highway", "primary")]);
        way(
            &mut d,
            profile,
            2,
            &[id(1, 0), id(2, 0)],
            &[("highway", "motorway"), ("toll", "yes")],
        );
        d
    };
    let car = build(&make(Profile::Car), Profile::Car);
    assert_eq!(car.segs.len(), 2);
    assert!(
        Mcr::from_bytes(car.bytes.clone())
            .unwrap()
            .meta()
            .contains("\"toll_segments\":1")
    );
    let mut d = grid(3, 1);
    way(
        &mut d,
        Profile::CarNoToll,
        1,
        &[id(0, 0), id(1, 0)],
        &[("highway", "primary")],
    );
    assert!(
        Profile::CarNoToll
            .way(&Tags::new(&[("highway", "motorway"), ("toll", "yes")]))
            .is_none()
    );
    let notoll = build(&d, Profile::CarNoToll);
    assert_eq!(notoll.segs.len(), 1);
}

#[test]
fn bike_pushes_against_a_one_way_street_and_walkers_ignore_it() {
    let mk = |profile: Profile| {
        let mut d = grid(2, 1);
        way(
            &mut d,
            profile,
            1,
            &[id(0, 0), id(1, 0)],
            &[("highway", "residential"), ("oneway", "yes")],
        );
        build(&d, profile)
    };
    let bike = mk(Profile::Bike);
    let m = Mcr::from_bytes(bike.bytes.clone()).unwrap();
    let s = m.segment(0);
    assert!(s.length_m > 60 && s.length_m < 90);
    let (fwd, bwd) = (s.dur[0] as f64, s.dur[1] as f64);
    assert!(
        (bwd / fwd - 15.0 / 4.0).abs() < 0.05,
        "riding 15 km/h, pushing 4 km/h: {fwd} {bwd}"
    );
    let foot = mk(Profile::Foot);
    let mf = Mcr::from_bytes(foot.bytes.clone()).unwrap();
    let sf = mf.segment(0);
    assert_eq!(sf.dur[0], sf.dur[1]);
    assert!((sf.dur[0] as f64 / (s.length_m as f64 / (5.0 / 3.6) * 10.0) - 1.0).abs() < 0.02);
}

#[test]
fn header_and_metadata() {
    let d = plus(Profile::Car);
    let b = build(&d, Profile::Car);
    assert_eq!(&b.bytes[0..4], b"MCR\0");
    let m = Mcr::from_bytes(b.bytes.clone()).unwrap();
    let meta = m.meta();
    assert!(meta.contains("\"zone\":\"test\""));
    assert!(meta.contains("\"profile\":\"car\""));
    assert!(meta.contains("ODbL"));
    assert!(meta.contains("\"polygon\":[]"));
    let mut with_poly = BuildOptions {
        zone: "test".into(),
        profile: Profile::Car,
        polygon: Vec::new(),
        keep_arcs: false,
        built_at: 1,
    };
    with_poly.polygon = mcr::parse_poly(
        "name\nring\n 6.0 49.0\n 6.5 49.0\n 6.5 50.0\n 6.0 50.0\n 6.0 49.0\nEND\n!hole\n 6.1 49.1\n 6.2 49.1\n 6.2 49.2\n 6.1 49.1\nEND\nEND\n",
    );
    assert_eq!(with_poly.polygon.len(), 1, "holes are ignored");
    let p = build_data(&d, &with_poly, &|_| {});
    let pm = Mcr::from_bytes(p.bytes).unwrap().meta();
    assert!(pm.contains("\"polygon\":[[[49.000000,6.000000]"));
    let lic = String::from_utf8_lossy(&b.bytes[512..1536]).to_string();
    assert!(lic.contains("Open Database License"));
    // a file from the future is refused
    let mut bad = b.bytes.clone();
    bad[4] = 99;
    assert!(Mcr::from_bytes(bad).is_err());
}

#[test]
fn a_barrier_junction_is_a_dead_end_for_walkers_and_riders() {
    for profile in [Profile::Bike, Profile::Foot] {
        let mut d = grid(3, 1);
        d.nodes[1].flags = mcr::profile::NODE_BARRIER;
        way(
            &mut d,
            profile,
            1,
            &[id(0, 0), id(1, 0), id(2, 0)],
            &[("highway", "residential")],
        );
        let b = build(&d, profile);
        let m = Mcr::from_bytes(b.bytes.clone()).unwrap();
        let a = b.junction_of_node[0];
        let z = b.junction_of_node[2];
        assert_eq!(m.query(b.new_id[a as usize], b.new_id[z as usize]), None, "{profile:?}");
    }
}
