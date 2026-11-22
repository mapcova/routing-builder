<?php

declare(strict_types=1);

namespace MapCova\GraphBuilder\Tests\Support;

/** A small synthetic town as a PBF file: a grid of streets with a one-way street, signals, a barrier and restrictions. */
final class GridMap
{
    public const SIZE = 7;

    public static function write(string $path): void
    {
        $w = new PbfWriter();
        $n = static fn (int $r, int $c): int => 1000 + $r * 100 + $c;
        for ($r = 0; $r < self::SIZE; $r++) {
            for ($c = 0; $c < self::SIZE; $c++) {
                $tags = [];
                if ($r === 3 && $c === 3) {
                    $tags = ['highway' => 'traffic_signals'];
                } elseif ($r === 5 && $c === 2) {
                    $tags = ['barrier' => 'bollard'];
                }
                // streets are a few hundred metres long; the corner rows are slightly bent
                $w->node($n($r, $c), 49.60 + $r * 0.0025 + ($c % 2) * 0.0003, 6.10 + $c * 0.004, $tags);
            }
        }
        $id = 1;
        $ways = [];
        for ($r = 0; $r < self::SIZE; $r++) {
            $refs = [];
            for ($c = 0; $c < self::SIZE; $c++) {
                $refs[] = $n($r, $c);
            }
            $tags = ['highway' => $r === 3 ? 'primary' : 'residential', 'name' => "row $r"];
            if ($r === 1) {
                $tags['oneway'] = 'yes';
            }
            if ($r === 4) {
                $tags['maxspeed'] = '30';
            }
            $w->way($id, $refs, $tags);
            $ways["r$r"] = $id++;
        }
        for ($c = 0; $c < self::SIZE; $c++) {
            $refs = [];
            for ($r = 0; $r < self::SIZE; $r++) {
                $refs[] = $n($r, $c);
            }
            $tags = ['highway' => $c === 3 ? 'secondary' : 'residential'];
            if ($c === 5) {
                $tags['highway'] = 'service';
                $tags['access'] = 'private';
            }
            $w->way($id, $refs, $tags);
            $ways["c$c"] = $id++;
        }
        // a footpath across the middle and a cycleway
        $w->way($id++, [$n(0, 0), $n(1, 1), $n(2, 2)], ['highway' => 'footway']);
        $w->way($id++, [$n(6, 6), $n(5, 5)], ['highway' => 'cycleway']);
        // a short connector between two junctions, the via way of a restriction
        $connector = $id++;
        $w->way($connector, [$n(2, 2), $n(3, 3)], ['highway' => 'residential']);
        $w->relation(1, ['type' => 'restriction', 'restriction' => 'no_left_turn'], [['from', 1, $ways['r2']], ['via', 0, $n(2, 3)], ['to', 1, $ways['c3']]]);
        $w->relation(2, ['type' => 'restriction', 'restriction' => 'no_u_turn'], [['from', 1, $ways['c1']], ['via', 0, $n(4, 1)], ['to', 1, $ways['c1']]]);
        $w->relation(3, ['type' => 'restriction', 'restriction' => 'only_straight_on'], [['from', 1, $ways['r4']], ['via', 0, $n(4, 4)], ['to', 1, $ways['r4']]]);
        $w->relation(4, ['type' => 'restriction', 'restriction' => 'no_right_turn'], [['from', 1, $ways['r2']], ['via', 1, $connector], ['to', 1, $ways['c3']]]);
        $w->relation(5, ['type' => 'restriction', 'restriction' => 'no_left_turn', 'except' => 'bicycle'], [['from', 1, $ways['c4']], ['via', 0, $n(3, 4)], ['to', 1, $ways['r3']]]);
        $w->write($path);
    }
}
