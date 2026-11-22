<?php

declare(strict_types=1);

namespace MapCova\GraphBuilder\Net;

/** Largest strongly connected component (iterative Tarjan): routes snap only onto it. Port of `src/scc.rs`. */
final class Scc
{
    /** @return list<bool> per node: in the largest component */
    public static function largest(int $n, Arcs $arcs): array
    {
        $first = array_fill(0, $n + 1, 0);
        foreach ($arcs->rows() as [$s]) {
            $first[$s + 1]++;
        }
        for ($i = 0; $i < $n; $i++) {
            $first[$i + 1] += $first[$i];
        }
        $fill = $first;
        $adj = array_fill(0, $arcs->count(), 0);
        foreach ($arcs->rows() as [$s, $d]) {
            $adj[$fill[$s]++] = $d;
        }
        unset($fill);
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

        return $out;
    }
}
