<?php

declare(strict_types=1);

namespace MapCova\GraphBuilder\Query;

use SplMinHeap;

/** Upward Dijkstra on a hierarchy from multi-source labels, the meeting of two searches, and the Dijkstra of a link's remainder. */
final class Search
{
    /** Added to a weight before it goes into a heap key (source labels may be negative). */
    private const OFF = 1 << 28;

    /** Nodes settled by `up` and `remainder` since the program began (measures: a route's share is the difference). */
    public static int $settled = 0;

    /**
     * Upward search from the labels `$init` (node => weight, duration, distance), over the arcs leaving (`$fwd`) or entering the
     * nodes. A node of `$stop` is settled but its arcs are not followed. The search ends when the cheapest node left costs more
     * than `$limit`: the best known total minus the smallest label of the opposite side, since no weight is negative, a node
     * past it cannot be on a better path (and a node whose label would pass it is not added).
     *
     * @param array<int,array{int,int,int}> $init
     * @param array<int,mixed>|null $stop keys are the nodes
     * @return array{w:array<int,int>,t:array<int,int>,d:array<int,int>,p:array<int,int>,m:array<int,int>}
     */
    public static function up(ChFile $f, array $init, bool $fwd, ?array $stop = null, int $limit = PHP_INT_MAX): array
    {
        $w = [];
        $t = [];
        $d = [];
        $p = [];
        $m = [];
        $h = new SplMinHeap();
        foreach ($init as $n => [$iw, $it, $id]) {
            $w[$n] = $iw;
            $t[$n] = $it;
            $d[$n] = $id;
            $h->insert((($iw + self::OFF) << 32) | $n);
        }
        $closed = [];
        while (!$h->isEmpty()) {
            /** @var int $k */
            $k = $h->extract();
            $u = $k & 0xFFFFFFFF;
            if (isset($closed[$u])) {
                continue;
            }
            if ($w[$u] > $limit) {
                break;
            }
            $closed[$u] = true;
            ++self::$settled;
            if ($stop !== null && isset($stop[$u])) {
                continue;
            }
            $wu = $w[$u];
            $a = $f->arcs($u, $fwd);
            for ($i = 0, $c = \count($a); $i < $c; $i += 5) {
                $v = $a[$i];
                $nw = $wu + $a[$i + 1];
                if ($nw <= $limit && (!isset($w[$v]) || $nw < $w[$v])) {
                    $w[$v] = $nw;
                    $t[$v] = $t[$u] + $a[$i + 2];
                    $d[$v] = $d[$u] + $a[$i + 3];
                    $p[$v] = $u;
                    $m[$v] = $a[$i + 4];
                    $h->insert((($nw + self::OFF) << 32) | $v);
                }
            }
        }

        return ['w' => $w, 't' => $t, 'd' => $d, 'p' => $p, 'm' => $m];
    }

    /**
     * Continues a forward search of a link with a remainder (uncontracted top vertices, node ids below `$f->uncontracted`)
     * by Dijkstra over their core arcs.
     *
     * @param array{w:array<int,int>,t:array<int,int>,d:array<int,int>,p:array<int,int>,m:array<int,int>} $r
     */
    public static function remainder(LinkFile $f, array &$r, int $limit = PHP_INT_MAX): void
    {
        $core = $f->core();
        $h = new SplMinHeap();
        foreach ($r['w'] as $n => $wn) {
            if ($n < $f->uncontracted) {
                $h->insert((($wn + self::OFF) << 32) | $n);
            }
        }
        $closed = [];
        while (!$h->isEmpty()) {
            /** @var int $k */
            $k = $h->extract();
            $u = $k & 0xFFFFFFFF;
            if (isset($closed[$u])) {
                continue;
            }
            if ($r['w'][$u] > $limit) {
                break;
            }
            $closed[$u] = true;
            ++self::$settled;
            $wu = $r['w'][$u];
            for ($a = $core['first'][$u]; $a < $core['first'][$u + 1]; $a++) {
                $o = $a * 5;
                $v = $core['arcs'][$o];
                $nw = $wu + $core['arcs'][$o + 1];
                if ($nw <= $limit && (!isset($r['w'][$v]) || $nw < $r['w'][$v])) {
                    $r['w'][$v] = $nw;
                    $r['t'][$v] = $r['t'][$u] + $core['arcs'][$o + 2];
                    $r['d'][$v] = $r['d'][$u] + $core['arcs'][$o + 3];
                    $r['p'][$v] = $u;
                    $r['m'][$v] = $core['arcs'][$o + 4];
                    $h->insert((($nw + self::OFF) << 32) | $v);
                }
            }
        }
    }

    /**
     * The cheapest total (not below 0) at a node both searches reached.
     *
     * @param array{w:array<int,int>} $fw
     * @param array{w:array<int,int>} $bw
     * @return array{best:int,node:int}|null
     */
    public static function meet(array $fw, array $bw): ?array
    {
        $best = PHP_INT_MAX;
        $node = -1;
        foreach ($bw['w'] as $v => $wb) {
            if (isset($fw['w'][$v])) {
                $tot = $fw['w'][$v] + $wb;
                if ($tot >= 0 && $tot < $best) {
                    $best = $tot;
                    $node = $v;
                }
            }
        }

        return $node < 0 ? null : ['best' => $best, 'node' => $node];
    }
}
