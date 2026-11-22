<?php

declare(strict_types=1);

namespace MapCova\GraphBuilder\Net;

use MapCova\GraphBuilder\Profile\Car;
use MapCova\GraphBuilder\Profile\Profile;
use MapCova\GraphBuilder\Support\F32;

/**
 * Edge-based graph (car): a node is a directed segment, an arc A -> B costs the time along A plus the penalty of
 * the turn from A into B. Turn restrictions (via a node or via one or more ways), signals, gates, U-turns, and the
 * weight penalty for entering local-access-only roads live here. Port of `src/edge.rs`.
 */
final class EdgeGraph
{
    /** @var list<int> graph node -> directed segment (shadow nodes repeat the segment they copy) */
    public array $cnodes = [];
    public Arcs $arcs;
    /** @var list<array{int,int}> (original node, shadow node): the shadow stands for "arrived through the restricted sequence" */
    public array $shadows = [];
    /** @var array<int,int> directed segment -> graph node, `NONE` when not allowed */
    public array $cid = [];
    public string $stats = '';

    /**
     * Directed segments of a via-way restriction, when the geometry fits.
     *
     * @param array{from:int,via:list<int>,to:int,only:bool} $r
     * @return list<array{a:int,chain:list<int>,outs:list<int>,only:bool}>
     */
    private static function resolveVia(Net $net, array $r): array
    {
        $found = [];
        $s0 = $net->wayFirst[$r['via'][0]];
        $c0 = $net->wayCnt[$r['via'][0]];
        if ($c0 === 0) {
            return $found;
        }
        $first = $net->junc($net->segA[$s0]);
        $last = $net->junc($net->segB[$s0 + $c0 - 1]);
        foreach ([$first, $last] as $start) {
            $ins = [];
            foreach ($net->incident($start) as $x) {
                if ($net->segWay[$x >> 1] === $r['from']) {
                    $ins[] = ($x & ~1) | (1 - ($x & 1));
                }
            }
            if ($ins === []) {
                continue;
            }
            $cur = $start;
            $chain = [];
            $ok = true;
            foreach ($r['via'] as $w) {
                $s = $net->wayFirst[$w];
                $c = $net->wayCnt[$w];
                if ($c === 0) {
                    $ok = false;
                    break;
                }
                $head = $net->junc($net->segA[$s]);
                $tail = $net->junc($net->segB[$s + $c - 1]);
                if ($head === $cur) {
                    for ($x = $s; $x < $s + $c; $x++) {
                        $chain[] = $x * 2;
                    }
                    $cur = $tail;
                } elseif ($tail === $cur) {
                    for ($x = $s + $c - 1; $x >= $s; $x--) {
                        $chain[] = $x * 2 + 1;
                    }
                    $cur = $head;
                } else {
                    $ok = false;
                    break;
                }
            }
            if (!$ok) {
                continue;
            }
            $outs = [];
            foreach ($net->incident($cur) as $x) {
                if ($net->segWay[$x >> 1] === $r['to']) {
                    $outs[] = $x;
                }
            }
            if ($outs === []) {
                continue;
            }
            foreach ($ins as $a) {
                $found[] = ['a' => $a, 'chain' => $chain, 'outs' => $outs, 'only' => $r['only']];
            }
            break;
        }

        return $found;
    }

    public static function build(Net $net): self
    {
        $g = new self();
        $nseg = $net->nseg();
        $nj = $net->nj();
        $none = Net::NONE;

        // bearing of each directed segment, towards a point about 20 m along (stored as f32, as Rust does)
        $brg = array_fill(0, $nseg * 2, 0.0);
        for ($s = 0; $s < $nseg; $s++) {
            $p = $net->points($s);
            for ($dir = 0; $dir < 2; $dir++) {
                $seq = $dir === 0 ? $p : array_reverse($p);
                $acc = 0.0;
                $target = $seq[\count($seq) - 1];
                for ($k = 1, $m = \count($seq); $k < $m; $k++) {
                    $acc += Geom::haversine($seq[$k - 1][0], $seq[$k - 1][1], $seq[$k][0], $seq[$k][1]);
                    $target = $seq[$k];
                    if ($acc >= 20.0) {
                        break;
                    }
                }
                $brg[$s * 2 + $dir] = F32::r(Geom::bearing($seq[0][0], $seq[0][1], $target[0], $target[1]));
            }
        }

        // node restrictions at the directed-segment level
        $banned = [];
        /** @var array<int,list<int>> $only */
        $only = [];
        $nRes = 0;
        foreach ($net->nodeRestr as [$fw, $via, $tw, $isOnly]) {
            $j = $net->junc($via);
            if ($j === $none) {
                continue;
            }
            $ins = [];
            $outs = [];
            foreach ($net->incident($j) as $x) {
                $way = $net->segWay[$x >> 1];
                if ($way === $fw) {
                    $ins[] = ($x & ~1) | (1 - ($x & 1));
                }
                if ($way === $tw) {
                    $outs[] = $x;
                }
            }
            foreach ($ins as $e) {
                if ($isOnly) {
                    $only[$e] = array_merge($only[$e] ?? [], $outs);
                } else {
                    foreach ($outs as $f) {
                        $banned[($e << 32) | $f] = true;
                    }
                }
                $nRes++;
            }
        }

        // graph nodes: the allowed directed segments
        $cid = array_fill(0, $nseg * 2, $none);
        $cnodes = [];
        for ($d = 0; $d < $nseg * 2; $d++) {
            if ($net->dur[$d] > 0) {
                $cid[$d] = \count($cnodes);
                $cnodes[] = $d;
            }
        }

        $arcs = new Arcs();
        $uturnRestrictedEntry = (int) (Car::RESTRICTED_ENTRY_WEIGHT * 10.0);
        for ($j = 0; $j < $nj; $j++) {
            $es = $net->incident($j);
            $flags = $net->flags($net->juncNode[$j]);
            $light = ($flags & Profile::NODE_SIGNALS) !== 0;
            $barrier = ($flags & Profile::NODE_BARRIER) !== 0;
            $gate = ($flags & Profile::NODE_GATE) !== 0;
            $roads = \count($es);
            $outs = [];
            $ins = [];
            foreach ($es as $x) {
                if ($net->dur[$x] > 0) {
                    $outs[] = $x;
                }
                $r = ($x & ~1) | (1 - ($x & 1));
                if ($net->dur[$r] > 0) {
                    $ins[] = $r;
                }
            }
            $nOuts = \count($outs);
            foreach ($ins as $e) {
                $onlyE = $only[$e] ?? null;
                foreach ($outs as $f) {
                    $uturn = $f === ($e ^ 1);
                    if ($uturn) {
                        if (!($barrier || $nOuts === 1)) {
                            continue;
                        }
                    } elseif ($barrier) {
                        continue;
                    }
                    if (isset($banned[($e << 32) | $f])) {
                        continue;
                    }
                    if ($onlyE !== null && !\in_array($f, $onlyE, true)) {
                        continue;
                    }
                    $bIn = $brg[$e ^ 1] + 180.0;
                    $a = $brg[$f] - $bIn;
                    while ($a > 180.0) {
                        $a -= 360.0;
                    }
                    while ($a <= -180.0) {
                        $a += 360.0;
                    }
                    $pen = Car::turnPenalty($a, $roads, $light, $uturn);
                    if ($gate) {
                        $pen += Car::GATE_PENALTY;
                    }
                    $tp = (int) round($pen * 10.0);
                    $w = $net->w[$e] + $tp;
                    if (!$net->restricted($e) && $net->restricted($f)) {
                        $w += $uturnRestrictedEntry;
                    }
                    $arcs->add($cid[$e], $cid[$f], $w, $net->dur[$e] + $tp, $net->lenM[$e >> 1], $none);
                }
            }
        }

        // via-way restrictions: a copy of the restricted sequence of segments, reachable only from the segment the
        // restriction starts at, whose last copy lacks (or is limited to) the restricted exits
        $chains = [];
        $seen = [];
        foreach ($net->viaRestr as $r) {
            foreach (self::resolveVia($net, $r) as $c) {
                $ok = $cid[$c['a']] !== $none;
                foreach ($c['chain'] as $d) {
                    $ok = $ok && $cid[$d] !== $none;
                }
                $key = ($c['a'] << 32) | $c['chain'][0];
                if ($ok && !isset($seen[$key])) {
                    $seen[$key] = true;
                    $chains[] = $c;
                }
            }
        }
        $nChains = \count($chains);
        $shadows = [];
        if ($chains !== []) {
            $members = [];
            foreach ($chains as $c) {
                foreach ($c['chain'] as $d) {
                    $members[$cid[$d]] = true;
                }
            }
            /** @var array<int,list<array{int,int,int,int,int,int}>> $outOf arcs by source node, as before the filter below */
            $outOf = [];
            $replace = [];
            foreach ($chains as $c) {
                $replace[($cid[$c['a']] << 32) | $cid[$c['chain'][0]]] = true;
            }
            $keep = new Arcs();
            /** @var list<array{int,int,int,int,int,int}> $redirected */
            $redirected = [];
            foreach ($arcs->rows() as $row) {
                if (isset($members[$row[0]])) {
                    $outOf[$row[0]][] = $row;
                }
                if (isset($replace[($row[0] << 32) | $row[1]])) {
                    $redirected[] = $row;
                } else {
                    $keep->add(...$row);
                }
            }
            $arcs = $keep;
            foreach ($chains as $c) {
                $ids = [];
                foreach ($c['chain'] as $d) {
                    $ids[] = $cid[$d];
                }
                $base = \count($cnodes);
                $sh = [];
                foreach ($ids as $i => $id) {
                    $sh[$i] = $base + $i;
                    $cnodes[] = $c['chain'][$i];
                    $shadows[] = [$id, $base + $i];
                }
                // A -> first copy
                foreach ($redirected as [$rs, $rd, $rw, $rt, $rdist, $rsd]) {
                    if ($rs === $cid[$c['a']] && $rd === $ids[0]) {
                        $arcs->add($rs, $sh[0], $rw, $rt, $rdist, $rsd);
                    }
                }
                $outs = [];
                foreach ($c['outs'] as $d) {
                    $outs[$cid[$d]] = true;
                }
                $n = \count($ids);
                for ($i = 0; $i < $n; $i++) {
                    foreach ($outOf[$ids[$i]] ?? [] as [, $ad, $aw, $at, $adist, $asd]) {
                        if ($i + 1 < $n) {
                            if ($ad === $ids[$i + 1]) {
                                $arcs->add($sh[$i], $sh[$i + 1], $aw, $at, $adist, $asd);
                            } else {
                                $arcs->add($sh[$i], $ad, $aw, $at, $adist, $asd);
                            }
                        } else {
                            $isOut = isset($outs[$ad]);
                            if ($c['only'] !== $isOut) {
                                continue;
                            }
                            $arcs->add($sh[$i], $ad, $aw, $at, $adist, $asd);
                        }
                    }
                }
            }
        }
        $g->stats = \count($cnodes) . ' nodes (' . \count($shadows) . " copies for $nChains via-way restrictions), "
            . $arcs->count() . " arcs, $nRes restrictions at nodes";
        $g->cnodes = $cnodes;
        $g->arcs = $arcs;
        $g->shadows = $shadows;
        $g->cid = $cid;

        return $g;
    }
}
