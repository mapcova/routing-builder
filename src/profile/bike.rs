//! Bicycle profile, adapted from OSRM `bicycle.lua` (BSD-2-Clause) for a node-based graph: weight =
//! duration. Not modelled (an edge-based graph would be needed): turn penalties, U-turn penalty and the
//! 30 s mode-change penalty when switching between riding and pushing the bike. No elevation.

use super::*;

const DEFAULT_SPEED: f32 = 15.0;
const WALKING_SPEED: f32 = 4.0;
const MAX_SPEED: f32 = 21.0;
const WHITELIST: &[&str] = &["yes", "permissive", "designated"];
const BLACKLIST: &[&str] = &["no", "private", "agricultural", "forestry", "delivery", "use_sidepath"];
const HIERARCHY: &[&str] = &["bicycle", "vehicle", "access"];
const CYCLEWAY: &[&str] = &["track", "lane", "share_busway", "sharrow", "shared", "shared_lane"];
const OPPOSITE: &[&str] = &["opposite", "opposite_lane", "opposite_track"];

fn bicycle_speed(h: &str) -> Option<f32> {
    Some(match h {
        "cycleway" | "primary" | "primary_link" | "secondary" | "secondary_link" | "tertiary" | "tertiary_link"
        | "residential" | "unclassified" | "living_street" | "service" => DEFAULT_SPEED,
        "track" => 12.0,
        "path" => 13.0,
        _ => return None,
    })
}

fn surface_speed(s: &str) -> Option<f32> {
    Some(match s {
        "asphalt" | "chipseal" | "concrete" | "concrete_lanes" => DEFAULT_SPEED,
        "wood" | "metal" | "cobblestone:flattened" | "paving_stones" | "compacted" | "fine_gravel" | "ground" => 10.0,
        "cobblestone" => 7.0,
        "unpaved" | "gravel" | "pebblestone" | "grass_paver" | "earth" | "grass" => 6.0,
        "dirt" => 8.0,
        "laterite" => 5.0,
        "mud" | "sand" | "woodchips" => 3.0,
        "sett" => 9.0,
        _ => return None,
    })
}

#[derive(Clone, Copy)]
struct D {
    ok: bool,
    /// km/h, -1 = not set
    speed: f32,
}

pub fn way(t: &Tags) -> Option<Way> {
    let highway = t.get("highway").filter(|h| !h.is_empty());
    let route = t.get("route").filter(|h| !h.is_empty());
    let platform = t.is("railway", "platform") || t.is("public_transport", "platform");
    let amenity = t
        .get("amenity")
        .filter(|a| matches!(*a, "parking" | "parking_entrance"));
    let man_made = t.get("man_made");
    let movable = t.is("bridge", "movable");
    if highway.is_none()
        && route.is_none()
        && !platform
        && amenity.is_none()
        && !man_made.is_some_and(|m| m == "pier")
        && !movable
        && t.get("railway").is_none()
    {
        return None;
    }
    // blocked ways
    if t.is("motorroad", "yes") || t.is("impassable", "yes") || t.is("status", "impassable") {
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
    let access = find_access(t, HIERARCHY, WHITELIST, &[]);
    if access.is_some_and(|a| BLACKLIST.contains(&a)) {
        return None;
    }
    let (mut speed, mut duration, mut startpoint, mut allows_push) = (None::<f32>, None::<f32>, true, false);
    if movable {
        duration = t.get("duration").and_then(parse_duration);
        speed = Some(5.0);
        allows_push = true;
    } else if route == Some("ferry") {
        duration = t.get("duration").and_then(parse_duration);
        speed = Some(5.0);
        startpoint = false;
    } else if platform {
        speed = Some(WALKING_SPEED);
        allows_push = true;
    } else if amenity.is_some() {
        speed = Some(10.0);
        allows_push = true;
    } else if let Some(s) = highway.and_then(bicycle_speed) {
        speed = Some(s);
        allows_push = true;
    } else if access.is_some_and(|a| WHITELIST.contains(&a)) {
        speed = Some(DEFAULT_SPEED);
        allows_push = true;
    }
    let mut f = D {
        ok: true,
        speed: speed.unwrap_or(-1.0),
    };
    let mut b = f;
    // oneway
    let junction = t.get("junction");
    let implied = matches!(junction, Some("roundabout" | "circular")) || highway == Some("motorway");
    let mut reverse = false;
    let ow_bike = t.get("oneway:bicycle");
    let ow = t.get("oneway");
    if is_yes(ow_bike) {
        b.ok = false;
    } else if matches!(ow_bike, Some("no" | "0" | "false")) {
    } else if ow_bike == Some("-1") {
        f.ok = false;
        reverse = true;
    } else if is_yes(ow) {
        b.ok = false;
    } else if matches!(ow, Some("no" | "0" | "false")) {
    } else if ow == Some("-1") {
        f.ok = false;
        reverse = true;
    } else if implied {
        b.ok = false;
    }
    // cycleways on normal roads
    let cw = t.get("cycleway").or_else(|| t.get("cycleway:both"));
    let (cl, cr) = (t.get("cycleway:left"), t.get("cycleway:right"));
    let has = |v: Option<&str>, set: &[&str]| v.is_some_and(|x| set.contains(&x));
    let (mut cf, mut cb) = (false, false);
    if f.ok && b.ok && !implied {
        if has(cw, CYCLEWAY) {
            cf = true;
            cb = true;
        }
        if has(cr, CYCLEWAY) || has(cl, OPPOSITE) {
            cf = true;
        }
        if has(cl, CYCLEWAY) || has(cr, OPPOSITE) {
            cb = true;
        }
    } else {
        let twoway = has(cw, OPPOSITE) || has(cr, OPPOSITE) || has(cl, OPPOSITE);
        let opposite = has(cl, OPPOSITE) || has(cr, OPPOSITE);
        let oneway_cw = has(cw, CYCLEWAY) || has(cr, CYCLEWAY) || has(cl, CYCLEWAY);
        if twoway {
            cf = true;
            cb = true;
        } else if opposite {
            if reverse {
                cf = true;
            } else {
                cb = true;
            }
        } else if oneway_cw {
            if reverse {
                cb = true;
            } else {
                cf = true;
            }
        }
    }
    if cb {
        b = D {
            ok: true,
            speed: DEFAULT_SPEED,
        };
    }
    if cf {
        f = D {
            ok: true,
            speed: DEFAULT_SPEED,
        };
    }
    // pushing the bike where riding is not possible
    let foot = t.get("foot");
    let (mut pf, mut pb) = (None::<f32>, None::<f32>);
    if foot != Some("no") && (!f.ok || !b.ok || f.speed < 0.0 || b.speed < 0.0) {
        if let Some(p) = match highway {
            Some("footway" | "pedestrian") => Some(WALKING_SPEED),
            Some("steps") => Some(2.0),
            _ => None,
        } {
            pf = Some(p);
            pb = Some(p);
        } else if man_made == Some("pier") {
            pf = Some(WALKING_SPEED);
            pb = Some(WALKING_SPEED);
        } else if foot == Some("yes") {
            pf = Some(WALKING_SPEED);
            if !implied {
                pb = Some(WALKING_SPEED);
            }
        } else if t.is("foot:forward", "yes") {
            pf = Some(WALKING_SPEED);
        } else if t.is("foot:backward", "yes") {
            pb = Some(WALKING_SPEED);
        } else if allows_push {
            pf = Some(WALKING_SPEED);
            if !implied {
                pb = Some(WALKING_SPEED);
            }
        }
    }
    if let Some(p) = pf.filter(|_| !f.ok || f.speed < 0.0) {
        f = D { ok: true, speed: p };
    }
    if let Some(p) = pb.filter(|_| !b.ok || b.speed < 0.0) {
        b = D { ok: true, speed: p };
    }
    if t.is("bicycle", "dismount") {
        f = D {
            ok: true,
            speed: WALKING_SPEED,
        };
        b = f;
    }
    // cycleway mapped as a separate way
    if [cw, t.get("cycleway:both"), cl, cr].contains(&Some("separate"))
        && !matches!(
            t.get("bicycle"),
            Some("yes" | "permissive" | "designated" | "destination")
        )
    {
        return None;
    }
    // legal speed limit, surface, cap
    let limit = |d: &mut D, dir: &str| {
        if d.speed > 0.0 && duration.is_none() {
            let m = parse_maxspeed(t.get(&format!("maxspeed:{dir}")).unwrap_or(""));
            let m = if m > 0.0 {
                m
            } else {
                parse_maxspeed(t.get("maxspeed").unwrap_or(""))
            };
            if m > 0.0 {
                d.speed = d.speed.min(m);
            }
            if let Some(s) = t.get("surface").and_then(surface_speed) {
                d.speed = d.speed.min(s);
            }
            d.speed = d.speed.min(MAX_SPEED);
        }
        if d.speed <= 0.0 && duration.is_none() {
            d.ok = false;
        }
    };
    limit(&mut f, "forward");
    limit(&mut b, "backward");
    if !f.ok && !b.ok {
        return None;
    }
    let dir = |d: D| Dir {
        speed: if d.ok { d.speed.max(0.1) } else { 0.0 },
        rate: 1.0,
        restricted: false,
    };
    Some(Way {
        fwd: dir(f),
        bwd: dir(b),
        duration,
        startpoint,
        toll: false,
    })
}

pub fn node<'a>(tags: impl Iterator<Item = (&'a str, &'a str)>) -> u8 {
    let mut f = 0u8;
    let (mut barrier, mut highway, mut sensory) = (None, None, None);
    let mut acc: [Option<&str>; 3] = [None; 3];
    for (k, v) in tags {
        match k {
            "highway" => highway = Some(v),
            "barrier" => barrier = Some(v),
            "sensory" => sensory = Some(v),
            "bicycle" => acc[0] = Some(v),
            "vehicle" => acc[1] = Some(v),
            "access" => acc[2] = Some(v),
            _ => {}
        }
    }
    if highway == Some("traffic_signals") {
        f |= NODE_SIGNALS;
    }
    if let Some(a) = acc.into_iter().flatten().find(|a| !a.is_empty()) {
        if BLACKLIST.contains(&a) && highway != Some("crossing") {
            f |= NODE_BARRIER;
        }
    } else if let Some(b) = barrier
        && matches!(b, "yes" | "wall" | "fence")
        && !(b == "fence" && matches!(sensory, Some("audible" | "audio")))
    {
        f |= NODE_BARRIER;
    }
    f
}

#[cfg(test)]
mod tests {
    use super::*;

    fn w(pairs: &[(&str, &str)]) -> Option<Way> {
        way(&Tags::new(pairs))
    }

    #[test]
    fn cycling_speeds() {
        assert_eq!(w(&[("highway", "residential")]).unwrap().fwd.speed, 15.0);
        assert_eq!(w(&[("highway", "track")]).unwrap().fwd.speed, 12.0);
        assert!(w(&[("highway", "motorway")]).is_none());
        assert!(w(&[("highway", "trunk")]).is_none());
    }

    #[test]
    fn against_oneway_the_bike_is_pushed() {
        let x = w(&[("highway", "residential"), ("oneway", "yes")]).unwrap();
        assert_eq!(x.fwd.speed, 15.0);
        assert_eq!(x.bwd.speed, 4.0);
        let y = w(&[("highway", "residential"), ("oneway", "yes"), ("oneway:bicycle", "no")]).unwrap();
        assert_eq!(y.bwd.speed, 15.0);
        let z = w(&[("highway", "residential"), ("oneway", "yes"), ("cycleway", "opposite")]).unwrap();
        assert_eq!(z.bwd.speed, 15.0);
        let n = w(&[("highway", "residential"), ("oneway", "yes"), ("foot", "no")]).unwrap();
        assert_eq!(n.bwd.speed, 0.0);
    }

    #[test]
    fn footways_and_steps_are_pushed() {
        assert_eq!(w(&[("highway", "footway")]).unwrap().fwd.speed, 4.0);
        assert_eq!(w(&[("highway", "steps")]).unwrap().fwd.speed, 2.0);
        assert!(w(&[("highway", "footway"), ("bicycle", "no")]).is_none());
        assert_eq!(
            w(&[("highway", "footway"), ("bicycle", "yes")]).unwrap().fwd.speed,
            15.0
        );
    }

    #[test]
    fn surface_and_maxspeed_limit() {
        assert_eq!(
            w(&[("highway", "residential"), ("surface", "gravel")])
                .unwrap()
                .fwd
                .speed,
            6.0
        );
        assert_eq!(
            w(&[("highway", "residential"), ("maxspeed", "10")]).unwrap().fwd.speed,
            10.0
        );
    }

    #[test]
    fn ferry_is_not_a_start_point() {
        let x = w(&[("route", "ferry"), ("duration", "00:10")]).unwrap();
        assert_eq!(x.duration, Some(600.0));
        assert!(!x.startpoint);
    }

    #[test]
    fn nodes() {
        let n = |p: &[(&str, &str)]| node(p.iter().copied());
        assert_eq!(n(&[("barrier", "wall")]), NODE_BARRIER);
        assert_eq!(n(&[("barrier", "bollard")]), 0);
        assert_eq!(n(&[("barrier", "gate"), ("bicycle", "no")]), NODE_BARRIER);
        assert_eq!(n(&[("highway", "traffic_signals")]), NODE_SIGNALS);
    }
}
