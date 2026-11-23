<?php

declare(strict_types=1);

namespace MapCova\GraphBuilder\Query;

use MapCova\GraphBuilder\Link\LinkBuilder;
use MapCova\GraphBuilder\Net\Geom;
use MapCova\GraphBuilder\Tiles\TileSet;
use RuntimeException;

/**
 * The reference multi-level reader: a route goes up through the levels from the tile of each end, across at the lowest link
 * that holds both tiles, and down; it goes on upward while the way out of the current group could still be cheaper. The
 * files are `<dir>/<id>.mcr` (tiles), `<dir>/<id>.mco` (links) and `<dir>/<id>.mct` (transit, for the geometry).
 *
 * At every level the same step: a multi-source upward search over the hierarchy of the file (a tile stops at its border
 * vertices, a link at its outer border, which it leaves uncontracted; the top link finishes with a Dijkstra over its
 * remainder), a meeting of the two searches, and the labels at the exits, which are the sources of the level above. A path
 * that touches the border of the group is therefore found one level up, where the core of the group is a set of arcs; a
 * route whose cost beats the cheapest possible way out of the group needs no other level. Weights are exact: the result is
 * the one of the single graph.
 *
 * @phpstan-type Res array{w:array<int,int>,t:array<int,int>,d:array<int,int>,p:array<int,int>,m:array<int,int>}
 * @phpstan-type Labels array<string,array{int,int,int}>
 */
final class Router
{
    /** @var array<string,TileFile> */
    private array $tiles = [];
    /** @var array<string,LinkFile> */
    private array $links = [];
    /** @var array<string,Transit> */
    private array $transits = [];
    /** @var array<string,true> */
    private array $used = [];
    /** Link searches made so far (levels the routes went up through). */
    public int $levels = 0;

    public function __construct(private readonly TileSet $ts, private readonly string $dir)
    {
    }

    public function tile(string $id): TileFile
    {
        return $this->tiles[$id] ??= new TileFile($this->dir . "/$id.mcr");
    }

    public function link(string $id): LinkFile
    {
        return $this->links[$id] ??= new LinkFile($this->dir . "/$id.mco");
    }

    private function transit(string $id): Transit
    {
        return $this->transits[$id] ??= new Transit($this->dir . "/$id.mct");
    }

    /** Drops what the files cached (arcs, segments), to measure with a cold PHP side. */
    public function clearCaches(): void
    {
        foreach ($this->tiles as $f) {
            $f->clearCaches();
        }
        foreach ($this->links as $f) {
            $f->clearCaches();
        }
    }

    /** @return list<string> the files the last route used */
    public function usedFiles(): array
    {
        return array_keys($this->used);
    }

    /** @return array{string,array{seg:int,dist:float,f:float}}|null tile and snap of the nearest usable segment */
    public function snap(float $lat, float $lon, float $radius = 200.0): ?array
    {
        $la = (int) round($lat * 1e6);
        $lo = (int) round($lon * 1e6);
        $cands = $this->ts->near($la, $lo, 3000);
        $own = $this->ts->ownerOf($la, $lo);
        if (!\in_array($own, $cands, true)) {
            $cands[] = $own;
        }
        $best = null;
        foreach ($cands as $ti) {
            $id = $this->ts->ids[$ti];
            $s = $this->tile($id)->snap($lat, $lon, $radius);
            if ($s !== null && ($best === null || $s['dist'] < $best[1]['dist'] || ($s['dist'] === $best[1]['dist'] && $s['seg'] < $best[1]['seg']))) {
                $best = [$id, $s];
            }
        }

        return $best;
    }

    /**
     * Labels at the exits of a file (tile: its border; link: its outer border, none at the top) reached by a search.
     *
     * @param Res $r
     * @return Labels
     */
    private function exits(ChFile $f, array $r): array
    {
        $out = [];
        if ($f instanceof TileFile) {
            $b = $f->border();
        } elseif ($f instanceof LinkFile) {
            if ($f->remainder) {
                return [];
            }
            $b = $f->border();
        } else {
            return [];
        }
        foreach ($b['ch'] as $i => $node) {
            if (isset($r['w'][$node])) {
                $out[$b['keys'][$i]] = [$r['w'][$node], $r['t'][$node], $r['d'][$node]];
            }
        }

        return $out;
    }

    /**
     * @param Labels $labels
     * @return array<int,array{int,int,int}>
     */
    private function toNodes(LinkFile $f, array $labels): array
    {
        $out = [];
        foreach ($labels as $k => $l) {
            $out[$f->idOfKey($k)] = $l;
        }

        return $out;
    }

    /** @return array<int,true> the border nodes of a tile: where its searches stop */
    private function stopSet(TileFile $f): array
    {
        $s = [];
        foreach ($f->border()['ch'] as $n) {
            $s[$n] = true;
        }

        return $s;
    }

    private function lca(string $a, string $b): ?string
    {
        $anc = array_flip($this->ts->ancestors($a));
        foreach ($this->ts->ancestors($b) as $x) {
            if (isset($anc[$x])) {
                return $x;
            }
        }

        return null;
    }

    private function keyOfNode(ChFile $f, int $node): string
    {
        if ($f instanceof LinkFile) {
            return $f->keyOfId($node);
        }
        if ($f instanceof TileFile) {
            $i = $f->borderOfNode()[$node] ?? throw new RuntimeException('a node that is not on the border');

            return $f->border()['keys'][$i];
        }
        throw new RuntimeException('unknown file');
    }

    private function nodeOfKey(ChFile $f, string $key): int
    {
        if ($f instanceof LinkFile) {
            return $f->idOfKey($key);
        }
        if ($f instanceof TileFile) {
            $i = $f->borderIndex($key) ?? throw new RuntimeException('a key that is not on the border');

            return $f->border()['ch'][$i];
        }
        throw new RuntimeException('unknown file');
    }

    /**
     * `gap` is, with the geometry, the widest gap in metres between two polylines that follow each other (a joint).
     *
     * @return array{duration:float,distance:float,weight:int,geometry?:list<array{float,float}>,gap?:float,files:int,level:string}|null
     */
    public function route(float $lat1, float $lon1, float $lat2, float $lon2, bool $geometry = false, float $radius = 200.0): ?array
    {
        $this->used = [];
        $sa = $this->snap($lat1, $lon1, $radius);
        $sb = $this->snap($lat2, $lon2, $radius);
        if ($sa === null || $sb === null) {
            return null;
        }
        [$ta, $a] = $sa;
        [$tb, $b] = $sb;
        $fa = $this->tile($ta);
        $fb = $this->tile($tb);
        $this->used[$fa->path] = $this->used[$fb->path] = true;
        $initF = $fa->sourceLabels($a);
        $initB = $fb->targetLabels($b);
        if ($initF === [] || $initB === []) {
            return null;
        }
        /** @var list<array{f:ChFile,res:Res}> $sSt */
        $sSt = [];
        /** @var list<array{f:ChFile,res:Res}> $tSt */
        $tSt = [];
        $best = PHP_INT_MAX;
        /** @var array{s:int,t:int,node:int}|null $win */
        $win = null;
        $level = $ta;
        if ($ta === $tb) {
            $fw = Search::up($fa, $initF, true, $this->stopSet($fa));
            $bw = Search::up($fa, $initB, false, $this->stopSet($fa));
            $sSt[] = ['f' => $fa, 'res' => $fw];
            $tSt[] = ['f' => $fa, 'res' => $bw];
            $m = Search::meet($fw, $bw);
            if ($m !== null) {
                $best = $m['best'];
                $win = ['s' => 1, 't' => 1, 'node' => $m['node']];
            }
            $fwF = $this->exits($fa, $fw);
            $bwF = $this->exits($fa, $bw);
            $cur = $ta;
        } else {
            $lca = $this->lca($ta, $tb) ?? throw new RuntimeException('the tiles have no common link');
            $fw = Search::up($fa, $initF, true, $this->stopSet($fa));
            $bw = Search::up($fb, $initB, false, $this->stopSet($fb));
            $sSt[] = ['f' => $fa, 'res' => $fw];
            $tSt[] = ['f' => $fb, 'res' => $bw];
            $fwF = $this->exits($fa, $fw);
            $bwF = $this->exits($fb, $bw);
            for ($c = $this->ts->parentOf($ta); $c !== null && $c !== $lca; $c = $this->ts->parentOf($c)) {
                $lf = $this->link($c);
                $this->used[$lf->path] = true;
                $fw = Search::up($lf, $this->toNodes($lf, $fwF), true);
                $sSt[] = ['f' => $lf, 'res' => $fw];
                $fwF = $this->exits($lf, $fw);
            }
            for ($c = $this->ts->parentOf($tb); $c !== null && $c !== $lca; $c = $this->ts->parentOf($c)) {
                $lf = $this->link($c);
                $this->used[$lf->path] = true;
                $bw = Search::up($lf, $this->toNodes($lf, $bwF), false);
                $tSt[] = ['f' => $lf, 'res' => $bw];
                $bwF = $this->exits($lf, $bw);
            }
            $cur = $lca;
            $step = $this->levelStep($lca, $fwF, $bwF, $sSt, $tSt);
            if ($step['meet'] !== null) {
                $best = $step['meet']['best'];
                $win = ['s' => \count($sSt), 't' => \count($tSt), 'node' => $step['meet']['node']];
            }
            [$fwF, $bwF] = [$step['fwF'], $step['bwF']];
            $level = $lca;
        }
        while (($p = $this->ts->parentOf($cur)) !== null && $fwF !== [] && $bwF !== []) {
            $bound = min(array_column($fwF, 0)) + min(array_column($bwF, 0));
            if ($best !== PHP_INT_MAX && $bound >= $best) {
                break;
            }
            $step = $this->levelStep($p, $fwF, $bwF, $sSt, $tSt, $best);
            if ($step['meet'] !== null && $step['meet']['best'] < $best) {
                $best = $step['meet']['best'];
                $win = ['s' => \count($sSt), 't' => \count($tSt), 'node' => $step['meet']['node']];
                $level = $p;
            }
            [$fwF, $bwF] = [$step['fwF'], $step['bwF']];
            $cur = $p;
        }
        if ($win === null) {
            return null;
        }
        $sRes = $sSt[$win['s'] - 1]['res'];
        $tRes = $tSt[$win['t'] - 1]['res'];
        $n = $win['node'];
        $res = [
            'duration' => ($sRes['t'][$n] + $tRes['t'][$n]) / 10,
            'distance' => (float) ($sRes['d'][$n] + $tRes['d'][$n]),
            'weight' => $best,
            'files' => \count($this->used),
            'level' => $level,
        ];
        if ($geometry) {
            $res['geometry'] = $this->geometry($sSt, $tSt, $win, $a, $b, $fa, $fb, $gap);
            $res['gap'] = $gap;
            $res['files'] = \count($this->used);
        }

        return $res;
    }

    /**
     * The search at link `$id` from the labels of the level below: both directions, the remainder Dijkstra, the meeting, and the
     * labels at the exits.
     *
     * @param Labels $fwF
     * @param Labels $bwF
     * @param list<array{f:ChFile,res:Res}> $sSt
     * @param list<array{f:ChFile,res:Res}> $tSt
     * @return array{meet:array{best:int,node:int}|null,fwF:Labels,bwF:Labels}
     */
    private function levelStep(string $id, array $fwF, array $bwF, array &$sSt, array &$tSt, int $best = PHP_INT_MAX): array
    {
        $lf = $this->link($id);
        $this->used[$lf->path] = true;
        // a total through a node of this level is its label plus at least the smallest label of the other side: past the best known
        // total minus that, a search cannot improve it
        $limFw = $best === PHP_INT_MAX || $bwF === [] ? PHP_INT_MAX : $best - min(array_column($bwF, 0));
        $limBw = $best === PHP_INT_MAX || $fwF === [] ? PHP_INT_MAX : $best - min(array_column($fwF, 0));
        $fw = Search::up($lf, $this->toNodes($lf, $fwF), true, null, $limFw);
        if ($lf->remainder) {
            Search::remainder($lf, $fw, $limFw);
        }
        $bw = Search::up($lf, $this->toNodes($lf, $bwF), false, null, $limBw);
        $sSt[] = ['f' => $lf, 'res' => $fw];
        $tSt[] = ['f' => $lf, 'res' => $bw];
        ++$this->levels;

        return ['meet' => Search::meet($fw, $bw), 'fwF' => $this->exits($lf, $fw), 'bwF' => $this->exits($lf, $bw)];
    }

    /**
     * @param list<array{f:ChFile,res:Res}> $sSt
     * @param list<array{f:ChFile,res:Res}> $tSt
     * @param array{s:int,t:int,node:int} $win
     * @param array{seg:int,dist:float,f:float} $a
     * @param array{seg:int,dist:float,f:float} $b
     * @param-out float $gap
     * @return list<array{float,float}>
     */
    private function geometry(array $sSt, array $tSt, array $win, array $a, array $b, TileFile $fa, TileFile $fb, ?float &$gap = null): array
    {
        // forward side, from the meeting node down the stages to the tile of the start
        $node = $win['node'];
        $parts = [];
        $first = 0;
        for ($j = $win['s'] - 1; $j >= 0; $j--) {
            $res = $sSt[$j]['res'];
            $arcs = [];
            $v = $node;
            while (isset($res['p'][$v])) {
                $arcs[] = [$res['p'][$v], $v, $res['m'][$v]];
                $v = $res['p'][$v];
            }
            $parts[] = ['f' => $sSt[$j]['f'], 'arcs' => array_reverse($arcs)];
            if ($j > 0) {
                $node = $this->nodeOfKey($sSt[$j - 1]['f'], $this->keyOfNode($sSt[$j]['f'], $v));
            } else {
                $first = $v;
            }
        }
        $parts = array_reverse($parts);
        // backward side: from the meeting node up the stages to the tile of the end, in travel order
        $node = $win['node'];
        $last = 0;
        for ($j = $win['t'] - 1; $j >= 0; $j--) {
            $res = $tSt[$j]['res'];
            $arcs = [];
            $v = $node;
            while (isset($res['p'][$v])) {
                $arcs[] = [$v, $res['p'][$v], $res['m'][$v]];
                $v = $res['p'][$v];
            }
            $parts[] = ['f' => $tSt[$j]['f'], 'arcs' => $arcs];
            if ($j > 0) {
                $node = $this->nodeOfKey($tSt[$j - 1]['f'], $this->keyOfNode($tSt[$j]['f'], $v));
            } else {
                $last = $v;
            }
        }
        /** @var list<list<array{int,int}>> $lines polylines in travel order */
        $lines = [];
        foreach ($parts as $part) {
            $f = $part['f'];
            foreach ($part['arcs'] as [$u, $v, $mid]) {
                if ($f instanceof TileFile) {
                    $f->unpackArc($u, $v, $mid, function (int $x) use ($f, &$lines): void {
                        $lines[] = $this->road($f, $f->nodeSegDir($x));
                    });
                } elseif ($f instanceof LinkFile) {
                    $f->unpackArc($u, $v, $mid, function (int $x, int $y, int $o) use ($f, &$lines): void {
                        $this->expand($f, $o & 0x7FFFFFFF, $lines);
                    });
                }
            }
        }
        $lines[] = $this->road($fb, $fb->nodeSegDir($last));
        $gap = 0.0;
        for ($i = 1, $n = \count($lines); $i < $n; $i++) {
            $p0 = $lines[$i - 1][\count($lines[$i - 1]) - 1];
            $p1 = $lines[$i][0];
            $gap = max($gap, Geom::haversine($p0[0], $p0[1], $p1[0], $p1[1]));
        }
        $pts = [];
        foreach ($lines as $l) {
            foreach ($l as $q) {
                if ($pts === [] || $pts[\count($pts) - 1] !== $q) {
                    $pts[] = $q;
                }
            }
        }
        // the road of the first node is whole in the lines, as is the last one: cut them back to the snapped points
        $sdA = $fa->nodeSegDir($first);
        $sdB = $fb->nodeSegDir($last);
        $lenA = Geo::length($this->road($fa, $sdA));
        $lenB = Geo::length($this->road($fb, $sdB));
        $pts = self::trim($pts, TileFile::along($a, $sdA) * $lenA, (1.0 - TileFile::along($b, $sdB)) * $lenB);

        return array_map(static fn (array $q): array => [$q[0] / 1e6, $q[1] / 1e6], $pts);
    }

    /** @return list<array{int,int}> */
    private function road(TileFile $f, int $sd): array
    {
        $p = $f->geom($sd >> 1);

        return ($sd & 1) !== 0 ? array_reverse($p) : $p;
    }

    /**
     * The polylines of an original arc of a link: a cross arc, or a core arc of a child, down to the transit of a tile.
     *
     * @param list<list<array{int,int}>> $lines
     */
    private function expand(LinkFile $f, int $idx, array &$lines): void
    {
        $o = $f->orig($idx);
        $from = $f->keyOfVertex($o['src']);
        $to = $f->keyOfVertex($o['dst']);
        if (($o['kind'] & LinkBuilder::CROSS) !== 0) {
            $tile = $this->ts->ids[$o['kind'] & 0x7FFFFFFF];
            $t = $this->transit($tile);
            $this->used[$t->path] = true;
            $lines[] = $t->cross($from, $to);

            return;
        }
        $link = $f->meta()['link'] ?? null;
        $child = $this->ts->childrenOf(\is_string($link) ? $link : '')[$o['kind']] ?? throw new RuntimeException('a link without that child');
        if ($this->ts->isTile($child)) {
            $t = $this->transit($child);
            $this->used[$t->path] = true;
            $lines[] = $t->core($from, $to);

            return;
        }
        $cf = $this->link($child);
        $this->used[$cf->path] = true;
        $i = $cf->idOfKey($from);
        $j = $cf->idOfKey($to);
        $arc = $cf->coreArc($i, $j) ?? throw new RuntimeException('the child link has no such core arc');
        $cf->unpackArc($i, $j, $arc[3], function (int $x, int $y, int $om) use ($cf, &$lines): void {
            $this->expand($cf, $om & 0x7FFFFFFF, $lines);
        });
    }

    /**
     * Cuts `$head` metres off the start and `$tail` metres off the end of a polyline.
     *
     * @param list<array{int,int}> $pts
     * @return list<array{int,int}>
     */
    public static function trim(array $pts, float $head, float $tail): array
    {
        $total = Geo::length($pts);
        if ($total <= 0.0 || ($head <= 0.0 && $tail <= 0.0)) {
            return $pts;
        }
        $from = min(max($head, 0.0), $total);
        $to = max($total - max($tail, 0.0), $from);
        $out = [];
        $cum = 0.0;
        $started = false;
        for ($k = 0, $n = \count($pts); $k < $n; $k++) {
            $seg = $k > 0 ? Geom::haversine($pts[$k - 1][0], $pts[$k - 1][1], $pts[$k][0], $pts[$k][1]) : 0.0;
            $next = $cum + $seg;
            if (!$started && $next >= $from && $k > 0) {
                $out[] = $seg > 0 ? Geo::lerp($pts[$k - 1], $pts[$k], ($from - $cum) / $seg) : $pts[$k];
                $started = true;
            } elseif (!$started && $k === 0 && $from <= 0.0) {
                $out[] = $pts[0];
                $started = true;
            }
            if ($started && $k > 0) {
                if ($next >= $to) {
                    $q = $seg > 0 ? Geo::lerp($pts[$k - 1], $pts[$k], ($to - $cum) / $seg) : $pts[$k];
                    if ($out === [] || $out[\count($out) - 1] !== $q) {
                        $out[] = $q;
                    }
                    break;
                }
                if ($next > $from) {
                    $out[] = $pts[$k];
                }
            }
            $cum = $next;
        }

        return $out !== [] ? $out : [$pts[0]];
    }
}
