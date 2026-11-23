<?php

declare(strict_types=1);

namespace MapCova\GraphBuilder\Query;

use MapCova\GraphBuilder\Support\Bin;

/** Polylines of (lat, lon) in 1e-6 degree: reading a segment's points, clipping, length. */
final class Geo
{
    /**
     * Points of a segment record as stored in `geometry` (first point two i32, then zigzag varint deltas).
     *
     * @return list<array{int,int}>
     */
    public static function decode(string $bin): array
    {
        if (\strlen($bin) < 8) {
            return [];
        }
        $p = Bin::u32s($bin, 2);
        $la = self::s32($p[0]);
        $lo = self::s32($p[1]);
        $pts = [[$la, $lo]];
        $vals = [];
        $cur = 0;
        $shift = 0;
        $bytes = \strlen($bin) > 8 ? Bin::list('C*', $bin, 8) : [];
        foreach ($bytes as $b) {
            $cur |= ($b & 0x7f) << $shift;
            if (($b & 0x80) !== 0) {
                $shift += 7;
                continue;
            }
            $vals[] = ($cur >> 1) ^ -($cur & 1);
            $cur = 0;
            $shift = 0;
        }
        for ($k = 0, $c = \count($vals); $k + 1 < $c; $k += 2) {
            $la += $vals[$k];
            $lo += $vals[$k + 1];
            $pts[] = [$la, $lo];
        }

        return $pts;
    }

    /** @param list<array{int,int}> $pts */
    public static function encode(array $pts): string
    {
        $out = '';
        $pla = 0;
        $plo = 0;
        foreach ($pts as $k => [$la, $lo]) {
            if ($k === 0) {
                $out .= pack('VV', $la & 0xFFFFFFFF, $lo & 0xFFFFFFFF);
            } else {
                $out .= \MapCova\GraphBuilder\Net\Geom::varint(\MapCova\GraphBuilder\Net\Geom::zigzag($la - $pla)) . \MapCova\GraphBuilder\Net\Geom::varint(\MapCova\GraphBuilder\Net\Geom::zigzag($lo - $plo));
            }
            $pla = $la;
            $plo = $lo;
        }

        return $out;
    }

    public static function s32(int $v): int
    {
        return $v >= 0x80000000 ? $v - 0x100000000 : $v;
    }

    /** @param list<array{int,int}> $pts length in metres */
    public static function length(array $pts): float
    {
        $s = 0.0;
        for ($k = 1, $n = \count($pts); $k < $n; $k++) {
            $s += \MapCova\GraphBuilder\Net\Geom::haversine($pts[$k - 1][0], $pts[$k - 1][1], $pts[$k][0], $pts[$k][1]);
        }

        return $s;
    }

    /**
     * The part of a polyline between the fractions `$f0` and `$f1` of its length.
     *
     * @param list<array{int,int}> $pts
     * @return list<array{int,int}>
     */
    public static function clip(array $pts, float $f0, float $f1): array
    {
        if ($f0 <= 0.0 && $f1 >= 1.0) {
            return $pts;
        }
        if ($f1 < $f0) {
            $f1 = $f0;
        }
        $len = [0.0];
        $kx = cos(deg2rad($pts[0][0] / 1e6));
        for ($k = 1, $n = \count($pts); $k < $n; $k++) {
            $len[$k] = $len[$k - 1] + hypot($pts[$k][0] - $pts[$k - 1][0], ($pts[$k][1] - $pts[$k - 1][1]) * $kx);
        }
        $total = $len[\count($len) - 1];
        $s0 = $f0 * $total;
        $s1 = $f1 * $total;
        $out = [];
        for ($k = 0, $n = \count($pts); $k < $n; $k++) {
            if ($k > 0 && $len[$k] > $s0 && $len[$k - 1] < $s0 && $out === []) {
                $out[] = self::lerp($pts[$k - 1], $pts[$k], ($s0 - $len[$k - 1]) / ($len[$k] - $len[$k - 1]));
            } elseif ($k === 0 && $s0 <= 0.0) {
                $out[] = $pts[0];
            }
            if ($len[$k] > $s0 && $len[$k] < $s1) {
                $out[] = $pts[$k];
            }
            if ($k > 0 && $len[$k] >= $s1 && $len[$k - 1] < $s1) {
                $out[] = self::lerp($pts[$k - 1], $pts[$k], ($s1 - $len[$k - 1]) / ($len[$k] - $len[$k - 1]));
                break;
            }
        }

        return $out !== [] ? $out : [$pts[0]];
    }

    /**
     * @param array{int,int} $a
     * @param array{int,int} $b
     * @return array{int,int}
     */
    public static function lerp(array $a, array $b, float $t): array
    {
        return [(int) round($a[0] + ($b[0] - $a[0]) * $t), (int) round($a[1] + ($b[1] - $a[1]) * $t)];
    }

    /**
     * Douglas-Peucker: drops the points closer than `$tolerance` metres to the line between the ends of their stretch.
     *
     * @param list<array{int,int}> $pts
     * @return list<array{int,int}>
     */
    public static function simplify(array $pts, float $tolerance): array
    {
        $n = \count($pts);
        if ($n < 3) {
            return $pts;
        }
        $kx = cos(deg2rad($pts[0][0] / 1e6)) * 0.111195;
        $ky = 0.111195;
        $keep = array_fill(0, $n, false);
        $keep[0] = $keep[$n - 1] = true;
        $stack = [[0, $n - 1]];
        while ($stack !== []) {
            [$a, $b] = array_pop($stack);
            $dmax = 0.0;
            $imax = -1;
            $ax = $pts[$a][1] * $kx;
            $ay = $pts[$a][0] * $ky;
            $dx = $pts[$b][1] * $kx - $ax;
            $dy = $pts[$b][0] * $ky - $ay;
            $l2 = $dx * $dx + $dy * $dy;
            for ($i = $a + 1; $i < $b; $i++) {
                $px = $pts[$i][1] * $kx - $ax;
                $py = $pts[$i][0] * $ky - $ay;
                $t = $l2 > 0 ? max(0.0, min(1.0, ($px * $dx + $py * $dy) / $l2)) : 0.0;
                $d = hypot($px - $t * $dx, $py - $t * $dy);
                if ($d > $dmax) {
                    $dmax = $d;
                    $imax = $i;
                }
            }
            if ($imax >= 0 && $dmax > $tolerance) {
                $keep[$imax] = true;
                $stack[] = [$a, $imax];
                $stack[] = [$imax, $b];
            }
        }
        $out = [];
        foreach ($pts as $i => $p) {
            if ($keep[$i]) {
                $out[] = $p;
            }
        }

        return $out;
    }
}
