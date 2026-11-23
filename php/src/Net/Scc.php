<?php

declare(strict_types=1);

namespace MapCova\GraphBuilder\Net;

/** Largest strongly connected component (iterative Tarjan): routes snap only onto it. Port of `src/scc.rs`. */
final class Scc
{
    /**
     * A tile sees only part of the network, and a road that reaches the main network through another tile would look like an
     * island: `$exits` (the nodes where a cross arc leaves the tile) and `$entries` (where one enters it) are joined through one
     * more vertex, as if the world outside were a single strongly connected whole.
     *
     * @param list<int> $exits
     * @param list<int> $entries
     * @return list<bool> per node: in the largest component
     */
    public static function largest(int $n, Arcs $arcs, array $exits = [], array $entries = []): array
    {
        $hub = $exits !== [] || $entries !== [] ? $n : -1;
        $total = $hub >= 0 ? $n + 1 : $n;
        $first = array_fill(0, $total + 1, 0);
        foreach ($arcs->rows() as [$s]) {
            $first[$s + 1]++;
        }
        foreach ($exits as $x) {
            $first[$x + 1]++;
        }
        if ($hub >= 0) {
            $first[$hub + 1] += \count($entries);
        }
        for ($i = 0; $i < $total; $i++) {
            $first[$i + 1] += $first[$i];
        }
        $fill = $first;
        $adj = array_fill(0, $first[$total], 0);
        foreach ($arcs->rows() as [$s, $d]) {
            $adj[$fill[$s]++] = $d;
        }
        foreach ($exits as $x) {
            $adj[$fill[$x]++] = $hub;
        }
        foreach ($entries as $e) {
            $adj[$fill[$hub]++] = $e;
        }
        unset($fill);
        $n = $total;
        $none = Net::NONE;
        $index = array_fill(0, $n, $none);
        $low = array_fill(0, $n, 0);
        $on = array_fill(0, $n, false);
        $comp = array_fill(0, $n, $none);
        $stack = [];
        $call = [];
        $idx = 0;
        $ncomp = 0;
        $sizes = [];
        for ($root = 0; $root < $n; $root++) {
            if ($index[$root] !== $none) {
                continue;
            }
            $call[] = [$root, $first[$root]];
            $index[$root] = $idx;
            $low[$root] = $idx;
            $idx++;
            $stack[] = $root;
            $on[$root] = true;
            while ($call !== []) {
                $top = \count($call) - 1;
                [$v, $ei] = $call[$top];
                if ($ei < $first[$v + 1]) {
                    $call[$top][1]++;
                    $w = $adj[$ei];
                    if ($index[$w] === $none) {
                        $index[$w] = $idx;
                        $low[$w] = $idx;
                        $idx++;
                        $stack[] = $w;
                        $on[$w] = true;
                        $call[] = [$w, $first[$w]];
                    } elseif ($on[$w]) {
                        $low[$v] = min($low[$v], $index[$w]);
                    }
                } else {
                    array_pop($call);
                    if ($call !== []) {
                        $p = $call[\count($call) - 1][0];
                        $low[$p] = min($low[$p], $low[$v]);
                    }
                    if ($low[$v] === $index[$v]) {
                        $size = 0;
                        while (true) {
                            $w = array_pop($stack);
                            $on[$w] = false;
                            $comp[$w] = $ncomp;
                            $size++;
                            if ($w === $v) {
                                break;
                            }
                        }
                        $sizes[] = $size;
                        $ncomp++;
                    }
                }
            }
        }
        $best = 0;
        $bestSize = -1;
        foreach ($sizes as $c => $size) {
            if ($size >= $bestSize) { // `max_by_key` returns the last of equal maxima
                $bestSize = $size;
                $best = $c;
            }
        }
        $out = [];
        foreach ($comp as $c) {
            $out[] = $c === $best;
        }
        if ($hub >= 0) {
            array_pop($out);
        }

        return $out;
    }
}
