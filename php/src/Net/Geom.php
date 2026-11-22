<?php

declare(strict_types=1);

namespace MapCova\GraphBuilder\Net;

/** Geometry helpers (port of `src/geom.rs`): coordinates are integers in 1e-6 degree. */
final class Geom
{
    public const R_EARTH = 6372797.560856;
    /** Snapping grid cell, in 1e-6 degree. */
    public const CELL = 5000;
    /** Rust's `f64::to_radians` multiplies by this (not `/ 180 * pi`, which PHP's `deg2rad` does). */
    private const RADS_PER_DEG = M_PI / 180.0;
    /** Rust's `f64::to_degrees`. */
    private const DEGS_PER_RAD = 57.2957795130823208767981548141051703;

    /** Distance in metres between two points (lat, lon in 1e-6 degree). */
    public static function haversine(int $la1, int $lo1, int $la2, int $lo2): float
    {
        $a1 = $la1 * 1e-6;
        $b1 = $lo1 * 1e-6;
        $a2 = $la2 * 1e-6;
        $b2 = $lo2 * 1e-6;
        $p1 = $a1 * self::RADS_PER_DEG;
        $p2 = $a2 * self::RADS_PER_DEG;
        $dl = ($b2 - $b1) * self::RADS_PER_DEG;
        $dp = $p2 - $p1;
        $s1 = sin($dp / 2.0);
        $s2 = sin($dl / 2.0);
        $h = $s1 * $s1 + cos($p1) * cos($p2) * ($s2 * $s2);

        return 2.0 * self::R_EARTH * asin(sqrt($h));
    }

    /** Bearing in degrees, 0 = north, clockwise. */
    public static function bearing(int $la1, int $lo1, int $la2, int $lo2): float
    {
        $dy = (float) ($la2 - $la1);
        $dx = (float) ($lo2 - $lo1) * cos(($la1 * 1e-6) * self::RADS_PER_DEG);
        $d = atan2($dx, $dy) * self::DEGS_PER_RAD;
        if ($d < 0.0) {
            $d += 360.0;
        }

        return $d;
    }

    public static function zigzag(int $v): int
    {
        return (($v << 1) ^ ($v >> 63)) & 0xFFFFFFFF;
    }

    public static function varint(int $v): string
    {
        $out = '';
        while ($v >= 0x80) {
            $out .= \chr(($v & 0x7f) | 0x80);
            $v >>= 7;
        }

        return $out . \chr($v);
    }

    public static function floorDiv(int $a, int $b): int
    {
        $q = intdiv($a, $b);

        return ($a % $b !== 0 && ($a < 0) !== ($b < 0)) ? $q - 1 : $q;
    }

    public static function cellKey(int $lat, int $lon): int
    {
        return self::floorDiv($lat + 90000000, self::CELL) * 100000 + self::floorDiv($lon + 180000000, self::CELL);
    }

    /**
     * Douglas-Peucker on a ring, tolerance in 1e-6 degree.
     *
     * @param list<array{int,int}> $ring
     * @return list<array{int,int}>
     */
    public static function simplify(array $ring, float $tol): array
    {
        $n = \count($ring);
        if ($n < 4) {
            return $ring;
        }
        $keep = array_fill(0, $n, false);
        $keep[0] = true;
        $keep[$n - 1] = true;
        $stack = [[0, $n - 1]];
        while ($stack !== []) {
            [$a, $b] = array_pop($stack);
            $far = 0;
            $dmax = 0.0;
            for ($i = $a + 1; $i < $b; $i++) {
                $d = self::segDist($ring[$i], $ring[$a], $ring[$b]);
                if ($d > $dmax) {
                    $dmax = $d;
                    $far = $i;
                }
            }
            if ($dmax > $tol) {
                $keep[$far] = true;
                $stack[] = [$a, $far];
                $stack[] = [$far, $b];
            }
        }
        $out = [];
        foreach ($ring as $i => $p) {
            if ($keep[$i]) {
                $out[] = $p;
            }
        }

        return $out;
    }

    /**
     * @param array{int,int} $p
     * @param array{int,int} $a
     * @param array{int,int} $b
     */
    private static function segDist(array $p, array $a, array $b): float
    {
        $px = (float) $p[1];
        $py = (float) $p[0];
        $ax = (float) $a[1];
        $ay = (float) $a[0];
        $bx = (float) $b[1];
        $by = (float) $b[0];
        $dx = $bx - $ax;
        $dy = $by - $ay;
        $l2 = $dx * $dx + $dy * $dy;
        $t = $l2 > 0.0 ? max(0.0, min(1.0, (($px - $ax) * $dx + ($py - $ay) * $dy) / $l2)) : 0.0;
        $ex = $px - $ax - $t * $dx;
        $ey = $py - $ay - $t * $dy;

        return sqrt($ex * $ex + $ey * $ey);
    }
}
