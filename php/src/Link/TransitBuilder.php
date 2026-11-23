<?php

declare(strict_types=1);

namespace MapCova\GraphBuilder\Link;

use MapCova\GraphBuilder\Query\Geo;
use MapCova\GraphBuilder\Query\Transit;
use MapCova\GraphBuilder\Query\TileFile;
use MapCova\GraphBuilder\Support\Io;

/**
 * Writes the transit file (`.mct`) of a tile: for each core arc, the roads of the path inside the tile from one border
 * vertex to the next (the road of the first vertex and all the following ones but the last, which belongs to the next arc), as
 * one polyline simplified to `$tolerance` metres; for each outgoing cross arc, the road of its source. The durations and
 * distances are those of the arcs, which the links carry.
 */
final class TransitBuilder
{
    /** @return array{vertices:int,core_arcs:int,cross_arcs:int,bytes:int,points:int,seconds:float} */
    public static function build(string $tilePath, string $outPath, float $tolerance = 0.5): array
    {
        $t0 = hrtime(true);
        $f = new TileFile($tilePath);
        $b = $f->border();
        $core = $f->core();
        $nv = \count($b['keys']);
        $road = static function (int $sd) use ($f): array {
            $p = $f->geom($sd >> 1);

            return ($sd & 1) !== 0 ? array_reverse($p) : $p;
        };
        $lines = [];
        $dst = '';
        $first = '';
        $na = 0;
        $points = 0;
        for ($i = 0; $i < $nv; $i++) {
            $first .= pack('V', $na);
            for ($a = $core['first'][$i]; $a < $core['first'][$i + 1]; $a++) {
                $j = $core['arcs'][$a * 5];
                $mid = $core['arcs'][$a * 5 + 4];
                $pts = [];
                $f->unpackArc($b['ch'][$i], $b['ch'][$j], $mid, static function (int $u) use ($f, $road, &$pts): void {
                    foreach ($road($f->nodeSegDir($u)) as $q) {
                        if ($pts === [] || $pts[\count($pts) - 1] !== $q) {
                            $pts[] = $q;
                        }
                    }
                });
                $pts = Geo::simplify($pts, $tolerance);
                $points += \count($pts);
                $lines[] = Geo::encode($pts);
                $dst .= pack('V', $j);
                ++$na;
            }
        }
        $first .= pack('V', $na);
        $cross = '';
        $nc = 0;
        foreach ($b['cross'] as $x) {
            if ($x['kind'] !== 0) {
                continue;
            }
            $pts = Geo::simplify($road($f->nodeSegDir($b['ch'][$x['idx']])), $tolerance);
            $points += \count($pts);
            $lines[] = Geo::encode($pts);
            $cross .= pack('V', $x['idx']) . $x['other'];
            ++$nc;
        }
        $off = '';
        $data = '';
        foreach ($lines as $l) {
            $off .= pack('V', \strlen($data));
            $data .= $l;
        }
        $off .= pack('V', \strlen($data));
        $head = Transit::MAGIC . pack('VVVV', 1, $nv, $na, $nc);
        $head = str_pad($head, 64, "\0");
        $body = $head . implode('', $b['keys']) . $first . $dst . $cross . $off . $data;
        Io::putAtomic($outPath, $body);

        return ['vertices' => $nv, 'core_arcs' => $na, 'cross_arcs' => $nc, 'bytes' => \strlen($body), 'points' => $points, 'seconds' => (hrtime(true) - $t0) / 1e9];
    }
}
