//! Pedestrian profile, adapted from OSRM `foot.lua` (BSD-2-Clause) for a node-based graph: weight =
//! duration, 5 km/h, only `oneway:foot` is honoured, no turn restrictions (as in `foot.lua`).

use super::*;

const WALKING_SPEED: f32 = 5.0;
const WHITELIST: &[&str] = &["yes", "foot", "permissive", "designated"];
const BLACKLIST: &[&str] = &["no", "agricultural", "forestry", "private", "delivery", "use_sidepath"];
const HIERARCHY: &[&str] = &["foot", "access"];

fn highway_speed(h: &str) -> Option<f32> {
    matches!(
        h,
        "primary"
            | "primary_link"
            | "secondary"
            | "secondary_link"
            | "tertiary"
            | "tertiary_link"
            | "unclassified"
            | "residential"
            | "living_street"
            | "service"
            | "track"
            | "path"
            | "steps"
            | "pedestrian"
            | "platform"
            | "footway"
            | "pier"
    )
    .then_some(WALKING_SPEED)
}

fn surface_speed(s: &str) -> Option<f32> {
    Some(match s {
        "fine_gravel" | "gravel" | "pebblestone" => WALKING_SPEED * 0.75,
        "mud" | "sand" => WALKING_SPEED * 0.5,
        _ => return None,
    })
}

pub fn way(t: &Tags) -> Option<Way> {
    let highway = t.get("highway").filter(|h| !h.is_empty());
    let route = t.get("route").filter(|h| !h.is_empty());
    let platform = t.is("railway", "platform") || t.is("public_transport", "platform");
    let amenity = t
        .get("amenity")
        .filter(|a| matches!(*a, "parking" | "parking_entrance"));
    let pier = t.is("man_made", "pier");
    if highway.is_none() && route.is_none() && !platform && amenity.is_none() && !pier && t.get("bridge").is_none() {
        return None;
    }
    if t.is("impassable", "yes")
        || t.is("status", "impassable")
        || t.is("motorroad", "yes")
        || (t.get("proposed").is_some() && highway.is_none_or(|h| h == "proposed"))
    {
        return None;
    }
    let access = find_access(t, HIERARCHY, WHITELIST, &[]);
    if access.is_some_and(|a| BLACKLIST.contains(&a)) {
        return None;
    }
    if [
        t.get("sidewalk"),
        t.get("sidewalk:both"),
        t.get("sidewalk:left"),
        t.get("sidewalk:right"),
    ]
    .contains(&Some("separate"))
        && !access.is_some_and(|a| !BLACKLIST.contains(&a))
    {
        return None;
    }
    let (mut f, mut b) = (true, true);
    match t.get("oneway:foot") {
        Some("-1") => f = false,
        Some("yes" | "1" | "true") => b = false,
        _ => {}
    }
    let (mut speed, mut duration, mut startpoint) = (None::<f32>, None::<f32>, true);
    if route == Some("ferry") {
        duration = t.get("duration").and_then(parse_duration);
        speed = Some(5.0);
        startpoint = false;
    }
    let speed = match speed {
        Some(s) => s,
        None => {
            if highway.and_then(highway_speed).is_some()
                || platform
                || amenity.is_some()
                || pier
                || access.is_some_and(|a| WHITELIST.contains(&a) || !BLACKLIST.contains(&a))
            {
                WALKING_SPEED
            } else {
                return None;
            }
        }
    };
    let speed = if duration.is_none() {
        t.get("surface").and_then(surface_speed).map_or(speed, |s| s.min(speed))
    } else {
        speed
    };
    let dir = |ok: bool| Dir {
        speed: if ok { speed } else { 0.0 },
        rate: 1.0,
        restricted: false,
    };
    if !f && !b {
        return None;
    }
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
    let (mut barrier, mut highway, mut sensory, mut bollard) = (None, None, None, None);
    let mut acc: [Option<&str>; 2] = [None; 2];
    for (k, v) in tags {
        match k {
            "highway" => highway = Some(v),
            "barrier" => barrier = Some(v),
            "sensory" => sensory = Some(v),
            "bollard" => bollard = Some(v),
            "foot" => acc[0] = Some(v),
            "access" => acc[1] = Some(v),
            _ => {}
        }
    }
    if highway == Some("traffic_signals") {
        f |= NODE_SIGNALS;
    }
    if let Some(a) = acc.into_iter().flatten().next() {
        if BLACKLIST.contains(&a) {
            f |= NODE_BARRIER;
        }
    } else if let Some(b) = barrier
        && matches!(b, "yes" | "wall" | "fence")
        && bollard != Some("rising")
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
    fn walking() {
        assert_eq!(w(&[("highway", "residential")]).unwrap().fwd.speed, 5.0);
        assert_eq!(w(&[("highway", "steps")]).unwrap().bwd.speed, 5.0);
        assert!(w(&[("highway", "motorway")]).is_none());
        assert!(w(&[("highway", "cycleway")]).is_none());
        assert_eq!(w(&[("highway", "cycleway"), ("foot", "yes")]).unwrap().fwd.speed, 5.0);
        assert!(w(&[("highway", "footway"), ("foot", "no")]).is_none());
    }

    #[test]
    fn plain_oneway_is_ignored_but_oneway_foot_is_not() {
        assert_eq!(
            w(&[("highway", "residential"), ("oneway", "yes")]).unwrap().bwd.speed,
            5.0
        );
        assert_eq!(
            w(&[("highway", "footway"), ("oneway:foot", "yes")]).unwrap().bwd.speed,
            0.0
        );
    }

    #[test]
    fn surfaces_and_ferries() {
        assert_eq!(w(&[("highway", "path"), ("surface", "sand")]).unwrap().fwd.speed, 2.5);
        let x = w(&[("route", "ferry"), ("duration", "PT5M")]).unwrap();
        assert_eq!(x.duration, Some(300.0));
        assert!(!x.startpoint);
    }

    #[test]
    fn nodes() {
        let n = |p: &[(&str, &str)]| node(p.iter().copied());
        assert_eq!(n(&[("barrier", "fence")]), NODE_BARRIER);
        assert_eq!(n(&[("barrier", "gate")]), 0);
        assert_eq!(n(&[("barrier", "gate"), ("foot", "private")]), NODE_BARRIER);
    }
}
