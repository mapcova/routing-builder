//! Geometry helpers: coordinates are integers in 1e-6 degree.

pub const R_EARTH: f64 = 6_372_797.560856;
/// Snapping grid cell, in 1e-6 degree.
pub const CELL: i64 = 5000;

pub type Pt = (i32, i32);

pub fn haversine(a: Pt, b: Pt) -> f64 {
    let (la1, lo1) = (a.0 as f64 * 1e-6, a.1 as f64 * 1e-6);
    let (la2, lo2) = (b.0 as f64 * 1e-6, b.1 as f64 * 1e-6);
    let (p1, p2) = (la1.to_radians(), la2.to_radians());
    let dl = (lo2 - lo1).to_radians();
    let dp = p2 - p1;
    let h = (dp / 2.0).sin().powi(2) + p1.cos() * p2.cos() * (dl / 2.0).sin().powi(2);
    2.0 * R_EARTH * h.sqrt().asin()
}

/// Bearing in degrees, 0 = north, clockwise.
pub fn bearing(a: Pt, b: Pt) -> f64 {
    let dy = (b.0 - a.0) as f64;
    let dx = (b.1 - a.1) as f64 * (a.0 as f64 * 1e-6).to_radians().cos();
    let mut d = dx.atan2(dy).to_degrees();
    if d < 0.0 {
        d += 360.0;
    }
    d
}

pub fn zigzag(v: i32) -> u32 {
    ((v << 1) ^ (v >> 31)) as u32
}

pub fn varint(buf: &mut Vec<u8>, mut v: u32) {
    while v >= 0x80 {
        buf.push((v as u8 & 0x7f) | 0x80);
        v >>= 7;
    }
    buf.push(v as u8);
}

pub fn cell_key(lat: i32, lon: i32) -> u32 {
    let la = (lat as i64 + 90_000_000).div_euclid(CELL);
    let lo = (lon as i64 + 180_000_000).div_euclid(CELL);
    (la * 100_000 + lo) as u32
}

/// Douglas-Peucker on a ring, tolerance in 1e-6 degree.
pub fn simplify(ring: &[Pt], tol: f64) -> Vec<Pt> {
    if ring.len() < 4 {
        return ring.to_vec();
    }
    let mut keep = vec![false; ring.len()];
    keep[0] = true;
    keep[ring.len() - 1] = true;
    let mut stack = vec![(0usize, ring.len() - 1)];
    while let Some((a, b)) = stack.pop() {
        let (mut far, mut dmax) = (0usize, 0.0f64);
        for i in a + 1..b {
            let d = seg_dist(ring[i], ring[a], ring[b]);
            if d > dmax {
                dmax = d;
                far = i;
            }
        }
        if dmax > tol {
            keep[far] = true;
            stack.push((a, far));
            stack.push((far, b));
        }
    }
    ring.iter().zip(keep).filter(|x| x.1).map(|x| *x.0).collect()
}

fn seg_dist(p: Pt, a: Pt, b: Pt) -> f64 {
    let (px, py) = (p.1 as f64, p.0 as f64);
    let (ax, ay) = (a.1 as f64, a.0 as f64);
    let (bx, by) = (b.1 as f64, b.0 as f64);
    let (dx, dy) = (bx - ax, by - ay);
    let l2 = dx * dx + dy * dy;
    let t = if l2 > 0.0 {
        (((px - ax) * dx + (py - ay) * dy) / l2).clamp(0.0, 1.0)
    } else {
        0.0
    };
    ((px - ax - t * dx).powi(2) + (py - ay - t * dy).powi(2)).sqrt()
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn haversine_one_degree_of_latitude() {
        let d = haversine((0, 0), (1_000_000, 0));
        assert!((d - 111_226.0).abs() < 50.0, "{d}");
    }

    #[test]
    fn bearing_cardinals() {
        assert!((bearing((0, 0), (1000, 0)) - 0.0).abs() < 1e-6);
        assert!((bearing((0, 0), (0, 1000)) - 90.0).abs() < 1e-6);
    }

    #[test]
    fn simplify_drops_collinear_points() {
        let ring = vec![(0, 0), (0, 5000), (0, 10_000), (10_000, 10_000), (0, 0)];
        let s = simplify(&ring, 10.0);
        assert_eq!(s.len(), 4);
    }
}
