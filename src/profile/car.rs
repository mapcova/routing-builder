//! Car profile, adapted from OSRM `car.lua` (BSD-2-Clause). Weight = duration / rate, where the rate
//! carries the service-road, width, side-road and alternating-oneway penalties of `car.lua`.
//! Not modelled: height/width/weight/length limits, directional access tags, `hov`, conditional restrictions.

use super::*;

pub const SPEED_REDUCTION: f32 = 0.8;
pub const TURN_PENALTY: f64 = 7.5;
pub const TURN_BIAS: f64 = 1.075;
pub const TRAFFIC_LIGHT_PENALTY: f64 = 2.0;
pub const U_TURN_PENALTY: f64 = 20.0;
pub const GATE_PENALTY: f64 = 60.0;
/// Weight added when a route enters a local-access-only road from the open network (seconds).
pub const RESTRICTED_ENTRY_WEIGHT: f64 = 100_000.0;

const WHITELIST: &[&str] = &[
    "yes",
    "motorcar",
    "motor_vehicle",
    "vehicle",
    "permissive",
    "designated",
    "hov",
];
const BLACKLIST: &[&str] = &[
    "no",
    "agricultural",
    "forestry",
    "emergency",
    "psv",
    "taxi",
    "share_taxi",
    "minibus",
    "bus",
    "foot",
    "emergency_vehicle",
    "restricted",
    "military",
    "official",
    "customers",
    "private",
    "delivery",
    "destination",
    "permit",
    "residents",
];
const RESTRICTED: &[&str] = &[
    "private",
    "delivery",
    "destination",
    "customers",
    "permit",
    "residents",
    "unknown",
];
const HIERARCHY: &[&str] = &["motorcar", "motor_vehicle", "vehicle", "access"];
const RESTRICTED_HIGHWAYS: &[&str] = &[
    "motorway",
    "motorway_link",
    "trunk",
    "trunk_link",
    "primary",
    "primary_link",
    "secondary",
    "secondary_link",
    "tertiary",
    "tertiary_link",
    "residential",
    "living_street",
    "unclassified",
    "service",
    "winter_road",
    "ice_road",
];
const BARRIER_WHITELIST: &[&str] = &[
    "cattle_grid",
    "border_control",
    "toll_booth",
    "sally_port",
    "no",
    "entrance",
    "height_restrictor",
    "arch",
];

pub fn highway_speed(h: &str) -> Option<f32> {
    Some(match h {
        "motorway" => 90.0,
        "motorway_link" => 45.0,
        "trunk" => 85.0,
        "trunk_link" => 40.0,
        "primary" => 65.0,
        "primary_link" => 30.0,
        "secondary" => 55.0,
        "secondary_link" => 25.0,
        "tertiary" => 40.0,
        "tertiary_link" => 20.0,
        "unclassified" => 25.0,
        "residential" => 25.0,
        "living_street" => 10.0,
        "service" => 15.0,
        "winter_road" => 20.0,
        "ice_road" => 15.0,
        _ => return None,
    })
}

fn surface_cap(s: &str) -> Option<f32> {
    Some(match s {
        "cement" | "compacted" | "fine_gravel" => 80.0,
        "paving_stones" | "metal" | "bricks" => 60.0,
        "grass" | "wood" | "sett" | "grass_paver" | "gravel" | "unpaved" | "ground" | "dirt" | "pebblestone"
        | "tartan" => 40.0,
        "cobblestone" | "clay" => 30.0,
        "earth" | "stone" | "rocky" | "sand" => 20.0,
        "laterite" => 15.0,
        "mud" => 10.0,
        "ice" => 20.0,
        "snow" => 30.0,
        _ => return None,
    })
}

fn tracktype_cap(s: &str) -> Option<f32> {
    Some(match s {
        "grade1" => 60.0,
        "grade2" => 40.0,
        "grade3" => 30.0,
        "grade4" => 25.0,
        "grade5" => 20.0,
        _ => return None,
    })
}

fn smoothness_cap(s: &str) -> Option<f32> {
    Some(match s {
        "intermediate" => 80.0,
        "bad" => 40.0,
        "very_bad" => 20.0,
        "horrible" => 10.0,
        "very_horrible" => 5.0,
        "impassable" => 0.0,
        _ => return None,
    })
}

fn service_penalty(s: &str) -> Option<f32> {
    matches!(
        s,
        "alley" | "parking" | "parking_aisle" | "driveway" | "drive-through" | "drive-thru"
    )
    .then_some(0.5)
}

/// `toll=yes` or `toll:motorcar=yes`/`toll:motor_vehicle=yes` (unless `toll=no`), or a toll booth way.
/// Swiss motorways are paid by vignette and carry no toll tag: they are not "toll" here.
pub fn is_toll(t: &Tags) -> bool {
    if t.is("toll", "yes") || t.is("toll:motorcar", "yes") || t.is("toll:motor_vehicle", "yes") {
        return true;
    }
    t.is("barrier", "toll_booth")
}

fn leading_number(s: &str) -> Option<f32> {
    let n: String = s
        .trim()
        .chars()
        .take_while(|c| c.is_ascii_digit() || *c == '.')
        .collect();
    n.parse().ok()
}

pub fn way(t: &Tags, no_toll: bool) -> Option<Way> {
    let highway = t.get("highway").filter(|h| !h.is_empty());
    let route = t.get("route").filter(|h| !h.is_empty());
    if highway.is_none() && route.is_none() {
        return None;
    }
    // blocked ways
    if t.is("area", "yes") || (no_toll && is_toll(t)) || t.is("oneway", "reversible") {
        return None;
    }
    if t.is("impassable", "yes") || t.is("status", "impassable") {
        return None;
    }
    if t.get("construction")
        .is_some_and(|c| !matches!(c, "no" | "widening" | "minor"))
    {
        return None;
    }
    if t.get("proposed").is_some() && highway.is_none_or(|h| h == "proposed") {
        return None;
    }
    if t.is("service", "emergency_access") {
        return None;
    }
    // access
    let access = find_access(t, HIERARCHY, WHITELIST, RESTRICTED);
    let restricted =
        highway.is_some_and(|h| RESTRICTED_HIGHWAYS.contains(&h)) && access.is_some_and(|a| RESTRICTED.contains(&a));
    if access.is_some_and(|a| BLACKLIST.contains(&a)) && !restricted {
        return None;
    }
    // oneway
    let oneway = t.first(&["oneway:motorcar", "oneway:motor_vehicle", "oneway:vehicle", "oneway"]);
    let (mut fwd_ok, mut bwd_ok) = (true, true);
    match oneway {
        Some("-1") => fwd_ok = false,
        Some("yes" | "1" | "true") => bwd_ok = false,
        _ => {
            let junction = t.get("junction");
            if (highway == Some("motorway") || matches!(junction, Some("roundabout" | "circular")))
                && oneway != Some("no")
            {
                bwd_ok = false;
            }
        }
    }
    // ferries, shuttle trains and movable bridges
    let (mut speed, mut duration, mut startpoint) = (None::<f32>, None::<f32>, true);
    if let Some(rs) = route.and_then(|r| match r {
        "ferry" => Some(5.0),
        "shuttle_train" => Some(10.0),
        _ => None,
    }) {
        duration = t.get("duration").and_then(parse_duration);
        speed = Some(rs);
        startpoint = false;
    }
    if t.is("bridge", "movable") {
        startpoint = true;
        match t.get("duration").and_then(parse_duration) {
            Some(d) => {
                duration = Some(d);
                speed = Some(5.0);
            }
            None => speed = Some(5.0),
        }
    }
    // speed by way type
    let speed = match speed {
        Some(s) => s,
        None => match highway.and_then(highway_speed) {
            Some(s) => s,
            None => match access {
                Some(a) if WHITELIST.contains(&a) || !BLACKLIST.contains(&a) => 10.0,
                _ => return None,
            },
        },
    };
    let (mut sf, mut sb) = (speed, speed);
    // maxspeed
    if duration.is_none() {
        let pick = |dir: &str| -> f32 {
            for k in ["maxspeed:advisory", "maxspeed", "source:maxspeed", "maxspeed:type"] {
                for key in [format!("{k}:{dir}"), k.to_string()] {
                    if let Some(v) = t.get(&key) {
                        let n = parse_maxspeed(v);
                        if n > 0.0 {
                            return n;
                        }
                    }
                }
            }
            0.0
        };
        let (mf, mb) = (pick("forward"), pick("backward"));
        if mf > 0.0 {
            sf = mf * SPEED_REDUCTION;
        }
        if mb > 0.0 {
            sb = mb * SPEED_REDUCTION;
        }
        // surface
        let cap = [
            t.get("surface").and_then(surface_cap),
            t.get("tracktype").and_then(tracktype_cap),
            t.get("smoothness").and_then(smoothness_cap),
        ]
        .into_iter()
        .flatten()
        .fold(f32::MAX, f32::min);
        sf = sf.min(cap);
        sb = sb.min(cap);
    }
    if (sf <= 0.0 && sb <= 0.0) && duration.is_none() {
        return None;
    }
    // penalties
    let both = fwd_ok && bwd_ok;
    let mut common = 1.0f32;
    if let Some(p) = t.get("service").and_then(service_penalty) {
        common = common.min(p);
    }
    if both {
        if t.is("lane_markings", "no") {
            common = common.min(0.75);
        }
        if let Some(w) = t.get("width").and_then(leading_number) {
            let p = if w <= 3.0 {
                0.5
            } else if w < 4.0 {
                0.6
            } else if w < 5.0 {
                0.75
            } else if w < 6.0 {
                0.8
            } else {
                1.0
            };
            common = common.min(p);
        }
        if t.get("lanes").and_then(leading_number).is_some_and(|l| l <= 1.0) {
            common = common.min(0.5);
        }
    }
    if t.is("oneway", "alternating") {
        common = common.min(0.4);
    }
    if matches!(t.get("side_road"), Some("yes" | "rotary")) {
        common = common.min(0.8);
    }
    let (mut rf, mut rb) = (common, common);
    match t.get("priority") {
        Some("forward") => rb = rb.min(0.7),
        Some("backward") => rf = rf.min(0.7),
        _ => {}
    }
    if highway == Some("service") && access == Some("private") {
        startpoint = false;
    }
    let dir = |ok: bool, speed: f32, rate: f32| Dir {
        speed: if ok && (speed > 0.0 || duration.is_some()) {
            speed.max(0.1)
        } else {
            0.0
        },
        rate,
        restricted,
    };
    Some(Way {
        fwd: dir(fwd_ok, sf, rf),
        bwd: dir(bwd_ok, sb, rb),
        duration,
        startpoint,
        toll: is_toll(t),
    })
}

pub fn node<'a>(tags: impl Iterator<Item = (&'a str, &'a str)>) -> u8 {
    let mut f = 0u8;
    let (mut barrier, mut bollard, mut kerb, mut highway, mut sensory) = (None, None, None, None, None);
    let mut acc: [Option<&str>; 4] = [None; 4];
    for (k, v) in tags {
        match k {
            "highway" => highway = Some(v),
            "barrier" => barrier = Some(v),
            "bollard" => bollard = Some(v),
            "kerb" => kerb = Some(v),
            "sensory" => sensory = Some(v),
            "motorcar" => acc[0] = Some(v),
            "motor_vehicle" => acc[1] = Some(v),
            "vehicle" => acc[2] = Some(v),
            "access" => acc[3] = Some(v),
            _ => {}
        }
    }
    if highway == Some("traffic_signals") {
        f |= NODE_SIGNALS;
    }
    if let Some(a) = acc.into_iter().flatten().next() {
        if BLACKLIST.contains(&a) && !RESTRICTED.contains(&a) {
            f |= NODE_BARRIER;
        }
    } else if let Some(b) = barrier {
        if matches!(b, "gate" | "lift_gate") {
            f |= NODE_GATE;
        } else if !BARRIER_WHITELIST.contains(&b)
            && bollard != Some("rising")
            && !matches!(kerb, Some("lowered" | "flush"))
            && !(b == "kerb" && highway == Some("crossing"))
            && !(b == "fence" && matches!(sensory, Some("audible" | "audio")))
        {
            f |= NODE_BARRIER;
        }
    }
    f
}

/// OSRM `process_turn` (seconds): `angle` in degrees, positive = right turn, 180 = U-turn.
pub fn turn_penalty(angle: f64, roads: usize, light: bool, u_turn: bool) -> f64 {
    let mut d = if light { TRAFFIC_LIGHT_PENALTY } else { 0.0 };
    if roads > 2 || u_turn {
        let e = if angle >= 0.0 {
            -((13.0 / TURN_BIAS) * angle / 180.0 - 6.5 * TURN_BIAS)
        } else {
            -((13.0 * TURN_BIAS) * (-angle) / 180.0 - 6.5 / TURN_BIAS)
        };
        d += TURN_PENALTY / (1.0 + e.exp());
        if u_turn {
            d += U_TURN_PENALTY;
        }
    }
    d
}

#[cfg(test)]
mod tests {
    use super::*;

    fn w(pairs: &[(&str, &str)]) -> Option<Way> {
        way(&Tags::new(pairs), false)
    }

    #[test]
    fn residential_two_way() {
        let x = w(&[("highway", "residential")]).unwrap();
        assert_eq!(x.fwd.speed, 25.0);
        assert_eq!(x.bwd.speed, 25.0);
        assert_eq!(x.fwd.rate, 1.0);
        assert!(!x.fwd.restricted);
    }

    #[test]
    fn oneway_and_motorway_are_one_way() {
        assert_eq!(w(&[("highway", "primary"), ("oneway", "yes")]).unwrap().bwd.speed, 0.0);
        assert_eq!(w(&[("highway", "primary"), ("oneway", "-1")]).unwrap().fwd.speed, 0.0);
        assert_eq!(w(&[("highway", "motorway")]).unwrap().bwd.speed, 0.0);
    }

    #[test]
    fn maxspeed_is_reduced() {
        let x = w(&[("highway", "primary"), ("maxspeed", "50")]).unwrap();
        assert!((x.fwd.speed - 40.0).abs() < 1e-4);
    }

    #[test]
    fn private_is_restricted_not_blocked_destination_too() {
        assert!(
            w(&[("highway", "service"), ("access", "private")])
                .unwrap()
                .fwd
                .restricted
        );
        assert!(
            w(&[("highway", "residential"), ("motor_vehicle", "destination")])
                .unwrap()
                .fwd
                .restricted
        );
        assert!(w(&[("highway", "residential"), ("access", "no")]).is_none());
        assert!(w(&[("highway", "service"), ("access", "private")]).unwrap().fwd.speed > 0.0);
        assert!(!w(&[("highway", "service"), ("access", "private")]).unwrap().startpoint);
    }

    #[test]
    fn service_roads_are_penalised() {
        let x = w(&[("highway", "service"), ("service", "driveway")]).unwrap();
        assert_eq!(x.fwd.rate, 0.5);
        assert_eq!(w(&[("highway", "service")]).unwrap().fwd.rate, 1.0);
        assert!(w(&[("highway", "service"), ("service", "emergency_access")]).is_none());
    }

    #[test]
    fn ferry_with_duration() {
        let x = w(&[("route", "ferry"), ("duration", "00:20")]).unwrap();
        assert_eq!(x.duration, Some(1200.0));
        assert!(x.fwd.speed > 0.0 && x.bwd.speed > 0.0);
        assert!(!x.startpoint);
        let s = w(&[("route", "shuttle_train"), ("duration", "PT15M")]).unwrap();
        assert_eq!(s.duration, Some(900.0));
        assert_eq!(w(&[("route", "ferry")]).unwrap().duration, None);
        assert!(w(&[("route", "ferry"), ("motor_vehicle", "no")]).is_none());
        assert!(w(&[("route", "bus")]).is_none());
    }

    #[test]
    fn toll_ways() {
        let t = Tags::new(&[("highway", "motorway"), ("toll", "yes")]);
        assert!(car_way(&t, false).is_some());
        assert!(car_way(&t, true).is_none());
        assert!(car_way(&Tags::new(&[("highway", "motorway"), ("toll", "no")]), true).is_some());
        assert!(is_toll(&Tags::new(&[
            ("highway", "motorway"),
            ("toll:motorcar", "yes")
        ])));
    }

    fn car_way(t: &Tags, no_toll: bool) -> Option<Way> {
        way(t, no_toll)
    }

    #[test]
    fn barriers() {
        let n = |p: &[(&str, &str)]| node(p.iter().copied());
        assert_eq!(n(&[("barrier", "bollard")]), NODE_BARRIER);
        assert_eq!(n(&[("barrier", "gate")]), NODE_GATE);
        assert_eq!(n(&[("barrier", "gate"), ("access", "yes")]), 0);
        assert_eq!(n(&[("highway", "traffic_signals")]), NODE_SIGNALS);
        assert_eq!(n(&[("barrier", "cattle_grid")]), 0);
    }

    #[test]
    fn right_cheaper_than_left() {
        assert!(turn_penalty(90.0, 4, false, false) < turn_penalty(-90.0, 4, false, false));
        assert_eq!(turn_penalty(0.0, 2, false, false), 0.0);
        assert!(turn_penalty(180.0, 1, false, true) > 25.0);
    }
}
