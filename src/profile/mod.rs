//! Routing profiles, adapted from the OSRM v26 `car.lua`, `bicycle.lua` and `foot.lua`
//! (BSD-2-Clause, Project OSRM contributors; notice kept in `NOTICE`).

pub mod bike;
pub mod car;
pub mod foot;

/// Which graph a file holds.
#[derive(Clone, Copy, PartialEq, Eq, Debug)]
pub enum Profile {
    Car,
    /// Car without the ways tagged as toll roads (an exact graph of its own).
    CarNoToll,
    Bike,
    Foot,
}

impl Profile {
    pub fn parse(s: &str) -> Option<Self> {
        Some(match s {
            "car" => Self::Car,
            "car-notoll" => Self::CarNoToll,
            "bike" => Self::Bike,
            "foot" => Self::Foot,
            _ => return None,
        })
    }

    pub fn name(self) -> &'static str {
        match self {
            Self::Car => "car",
            Self::CarNoToll => "car-notoll",
            Self::Bike => "bike",
            Self::Foot => "foot",
        }
    }

    /// Numeric id stored in the header.
    pub fn id(self) -> u32 {
        match self {
            Self::Car => 0,
            Self::Bike => 1,
            Self::Foot => 2,
            Self::CarNoToll => 3,
        }
    }

    /// Car graphs are edge-based (turn penalties, restrictions); bike and foot are node-based.
    pub fn edge_based(self) -> bool {
        matches!(self, Self::Car | Self::CarNoToll)
    }

    pub fn way(self, t: &Tags) -> Option<Way> {
        match self {
            Self::Car => car::way(t, false),
            Self::CarNoToll => car::way(t, true),
            Self::Bike => bike::way(t),
            Self::Foot => foot::way(t),
        }
    }

    /// Flags of a node from its tags, see the `NODE_*` constants.
    pub fn node<'a>(self, tags: impl Iterator<Item = (&'a str, &'a str)>) -> u8 {
        match self {
            Self::Car | Self::CarNoToll => car::node(tags),
            Self::Bike => bike::node(tags),
            Self::Foot => foot::node(tags),
        }
    }

    /// Whether `except=` of a restriction relation exempts this profile.
    pub fn exempt(self, except: &str) -> bool {
        except.split(';').any(|x| {
            let x = x.trim();
            match self {
                Self::Car | Self::CarNoToll => matches!(x, "motorcar" | "motor_vehicle"),
                Self::Bike => x == "bicycle",
                Self::Foot => x == "foot",
            }
        })
    }
}

pub const NODE_SIGNALS: u8 = 1;
pub const NODE_BARRIER: u8 = 2;
/// Via node of a turn restriction: always a junction.
pub const NODE_RESTRICTION: u8 = 4;
/// Gate or lift gate: a fixed delay to pass.
pub const NODE_GATE: u8 = 8;

/// Tags of a way, only the keys a profile may read.
#[derive(Default, Debug, Clone)]
pub struct Tags(pub Vec<(String, String)>);

impl Tags {
    pub fn new(pairs: &[(&str, &str)]) -> Self {
        Self(pairs.iter().map(|(k, v)| (k.to_string(), v.to_string())).collect())
    }

    pub fn get(&self, k: &str) -> Option<&str> {
        self.0.iter().find(|(a, _)| a == k).map(|(_, v)| v.as_str())
    }

    pub fn is(&self, k: &str, v: &str) -> bool {
        self.get(k) == Some(v)
    }

    pub fn first<'a>(&'a self, keys: &[&str]) -> Option<&'a str> {
        keys.iter().find_map(|k| self.get(k))
    }
}

/// Keys the profiles look at: a way with none of them is skipped without allocating.
pub fn way_key_of_interest(k: &str) -> bool {
    matches!(
        k,
        "highway" | "route" | "bridge" | "railway" | "amenity" | "man_made" | "public_transport"
    )
}

/// One direction of a way.
#[derive(Clone, Copy, Default, Debug, PartialEq)]
pub struct Dir {
    /// km/h; 0 = the direction cannot be used.
    pub speed: f32,
    /// Routing weight = duration / rate (1.0 = no penalty).
    pub rate: f32,
    /// Local access only (destination, private, ...): entering it from the open network is
    /// penalised in the weight, never in the duration.
    pub restricted: bool,
}

#[derive(Clone, Debug, Default, PartialEq)]
pub struct Way {
    pub fwd: Dir,
    pub bwd: Dir,
    /// Fixed duration of the whole way in seconds (ferries, shuttle trains).
    pub duration: Option<f32>,
    /// May a route start or end on this way (snapping)? False for ferries and private service roads.
    pub startpoint: bool,
    /// Tagged as a toll road (only the car profiles tell; `car-notoll` drops these ways).
    pub toll: bool,
}

/// `PT1H30M`, `P1DT2H`, `01:30`, `01:30:00`, or minutes. Seconds, at least 1.
pub fn parse_duration(s: &str) -> Option<f32> {
    let s = s.trim();
    if let Some(rest) = s.strip_prefix('P') {
        let (mut total, mut num, mut in_time) = (0.0f32, String::new(), false);
        for c in rest.chars() {
            match c {
                'T' => in_time = true,
                '0'..='9' | '.' => num.push(c),
                'Y' | 'W' | 'D' | 'H' | 'M' | 'S' => {
                    let v: f32 = num.parse().ok()?;
                    num.clear();
                    total += v * match (c, in_time) {
                        ('Y', _) => 365.0 * 86400.0,
                        ('W', _) => 7.0 * 86400.0,
                        ('D', _) => 86400.0,
                        ('H', true) => 3600.0,
                        ('M', true) => 60.0,
                        ('M', false) => 30.0 * 86400.0,
                        ('S', true) => 1.0,
                        _ => return None,
                    };
                }
                _ => return None,
            }
        }
        return (num.is_empty() && total > 0.0).then_some(total.max(1.0));
    }
    if s.contains(':') {
        let parts: Vec<f32> = s.split(':').map(|p| p.parse().ok()).collect::<Option<_>>()?;
        let t = match parts.as_slice() {
            [h, m] => h * 3600.0 + m * 60.0,
            [h, m, sec] => h * 3600.0 + m * 60.0 + sec,
            _ => return None,
        };
        return (t > 0.0).then_some(t.max(1.0));
    }
    let m: f32 = s.parse().ok()?;
    (m > 0.0).then_some((m * 60.0).max(1.0))
}

fn maxspeed_table(s: &str) -> Option<f32> {
    Some(match s {
        "at:rural" | "at:trunk" => 100.0,
        "ar:urban" => 40.0,
        "ar:rural" => 110.0,
        "be:motorway" => 120.0,
        "be-bru:rural" | "be-vlg:rural" => 70.0,
        "be-bru:urban" => 30.0,
        "bg:motorway" => 140.0,
        "ch:rural" => 80.0,
        "ch:trunk" => 100.0,
        "ch:motorway" => 120.0,
        "de:living_street" => 7.0,
        "de:rural" => 100.0,
        "de:motorway" => 0.0,
        "dk:rural" | "fr:rural" | "nl:rural" | "no:rural" => 80.0,
        "es:trunk" => 90.0,
        "gb:nsl_single" | "uk:nsl_single" => 60.0 * 1609.0 / 1000.0,
        "gb:nsl_dual" | "gb:motorway" | "uk:nsl_dual" | "uk:motorway" => 70.0 * 1609.0 / 1000.0,
        "nl:trunk" => 100.0,
        "pl:rural" => 100.0,
        "none" => 140.0,
        _ => return None,
    })
}

fn maxspeed_default(kind: &str) -> Option<f32> {
    Some(match kind {
        "urban" => 50.0,
        "rural" => 90.0,
        "trunk" => 110.0,
        "motorway" => 130.0,
        _ => return None,
    })
}

/// Parsed km/h, or 0.0 when absent or unparseable (as in OSRM).
pub fn parse_maxspeed(s: &str) -> f32 {
    let digits: String = s.chars().take_while(|c| c.is_ascii_digit()).collect();
    if !digits.is_empty() {
        let n: f32 = digits.parse().unwrap_or(0.0);
        if s.contains("mph") || s.contains("mp/h") {
            return n * 1609.0 / 1000.0;
        }
        return n;
    }
    let lower = s.to_ascii_lowercase();
    if let Some(v) = maxspeed_table(&lower) {
        return v;
    }
    let b = lower.as_bytes();
    if b.len() > 3
        && b[2] == b':'
        && let Some(v) = maxspeed_default(&lower[3..])
    {
        return v;
    }
    0.0
}

pub fn is_yes(v: Option<&str>) -> bool {
    matches!(v, Some("yes") | Some("1") | Some("true"))
}

/// First access tag of the hierarchy that is set; `a;b` keeps the most permissive part.
pub fn find_access<'a>(t: &'a Tags, hierarchy: &[&str], whitelist: &[&str], restricted: &[&str]) -> Option<&'a str> {
    let v = hierarchy.iter().find_map(|k| t.get(k))?;
    if !v.contains(';') {
        return Some(v);
    }
    let rank = |p: &str| {
        if whitelist.contains(&p) {
            3
        } else if restricted.contains(&p) {
            2
        } else {
            0
        }
    };
    v.split(';').map(str::trim).max_by_key(|p| rank(p)).or(Some(v))
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn durations() {
        assert_eq!(parse_duration("PT1H30M"), Some(5400.0));
        assert_eq!(parse_duration("PT45M"), Some(2700.0));
        assert_eq!(parse_duration("01:30"), Some(5400.0));
        assert_eq!(parse_duration("90"), Some(5400.0));
        assert_eq!(parse_duration("P1DT2H"), Some(93600.0));
        assert_eq!(parse_duration("soon"), None);
    }

    #[test]
    fn maxspeeds() {
        assert_eq!(parse_maxspeed("50"), 50.0);
        assert!((parse_maxspeed("30 mph") - 48.27).abs() < 0.1);
        assert_eq!(parse_maxspeed("ch:rural"), 80.0);
        assert_eq!(parse_maxspeed("LU:urban"), 50.0);
        assert_eq!(parse_maxspeed("signals"), 0.0);
    }

    #[test]
    fn profile_names_round_trip() {
        for p in [Profile::Car, Profile::CarNoToll, Profile::Bike, Profile::Foot] {
            assert_eq!(Profile::parse(p.name()), Some(p));
        }
    }
}
