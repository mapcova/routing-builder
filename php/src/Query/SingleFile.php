<?php

declare(strict_types=1);

namespace MapCova\GraphBuilder\Query;

/** The route of one whole `.mcr` file (a hierarchy over the whole zone): the reference the tiles are compared with. */
final class SingleFile
{
    public function __construct(public readonly TileFile $f)
    {
    }

    /**
     * @return array{duration:float,distance:float,weight:int,geometry?:list<array{float,float}>}|null
     */
    public function route(float $lat1, float $lon1, float $lat2, float $lon2, bool $geometry = false, float $radius = 200.0): ?array
    {
        $a = $this->f->snap($lat1, $lon1, $radius);
        $b = $this->f->snap($lat2, $lon2, $radius);
        if ($a === null || $b === null) {
            return null;
        }
        $fw = Search::up($this->f, $this->f->sourceLabels($a), true);
        $bw = Search::up($this->f, $this->f->targetLabels($b), false);
        $m = Search::meet($fw, $bw);
        if ($m === null) {
            return null;
        }
        $n = $m['node'];
        $res = ['duration' => ($fw['t'][$n] + $bw['t'][$n]) / 10, 'distance' => (float) ($fw['d'][$n] + $bw['d'][$n]), 'weight' => $m['best']];
        if ($geometry) {
            $lines = [];
            $f = $this->f;
            $arcs = [];
            for ($v = $n; isset($fw['p'][$v]); $v = $fw['p'][$v]) {
                $arcs[] = [$fw['p'][$v], $v, $fw['m'][$v]];
            }
            $arcs = array_reverse($arcs);
            for ($v = $n; isset($bw['p'][$v]); $v = $bw['p'][$v]) {
                $arcs[] = [$v, $bw['p'][$v], $bw['m'][$v]];
            }
            $last = $n;
            while (isset($bw['p'][$last])) {
                $last = $bw['p'][$last];
            }
            $first = $n;
            while (isset($fw['p'][$first])) {
                $first = $fw['p'][$first];
            }
            foreach ($arcs as [$u, $x, $mid]) {
                $f->unpackArc($u, $x, $mid, static function (int $node) use ($f, &$lines): void {
                    $sd = $f->nodeSegDir($node);
                    $p = $f->geom($sd >> 1);
                    $lines[] = ($sd & 1) !== 0 ? array_reverse($p) : $p;
                });
            }
            $sd = $f->nodeSegDir($last);
            $p = $f->geom($sd >> 1);
            $lines[] = ($sd & 1) !== 0 ? array_reverse($p) : $p;
            $pts = [];
            foreach ($lines as $l) {
                foreach ($l as $q) {
                    if ($pts === [] || $pts[\count($pts) - 1] !== $q) {
                        $pts[] = $q;
                    }
                }
            }
            $sdA = $f->nodeSegDir($first);
            $sdB = $f->nodeSegDir($last);
            $pa = $f->geom($sdA >> 1);
            $pb = $f->geom($sdB >> 1);
            $pts = Router::trim($pts, TileFile::along($a, $sdA) * Geo::length($pa), (1.0 - TileFile::along($b, $sdB)) * Geo::length($pb));
            $res['geometry'] = array_map(static fn (array $q): array => [$q[0] / 1e6, $q[1] / 1e6], $pts);
        }

        return $res;
    }
}
