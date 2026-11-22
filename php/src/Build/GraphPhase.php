<?php

declare(strict_types=1);

namespace MapCova\GraphBuilder\Build;

use MapCova\GraphBuilder\Net\Arcs;
use MapCova\GraphBuilder\Net\EdgeGraph;
use MapCova\GraphBuilder\Net\Geom;
use MapCova\GraphBuilder\Net\Net;
use MapCova\GraphBuilder\Net\NodeGraph;
use MapCova\GraphBuilder\Net\Scc;
use MapCova\GraphBuilder\Profile\Profile;
use MapCova\GraphBuilder\Support\Bin;

/**
 * Phase `graph`: from the way and node files, the network, the graph to contract, and everything of the `.mcr` file that
 * does not depend on the order of the contraction. Port of the first half of `build_data` in `src/lib.rs`.
 *
 * Files written: `arcs.bin` (the arcs before contraction, six u32 each: src, dst, weight, duration, distance, road),
 * `segments.bin` (the 40-byte segment records, graph node fields still in uncontracted ids), `geometry.bin`,
 * `grid_dir.bin`, `grid_lists.bin`, `shadows.bin` (u32 pairs), `segdir.bin` (u32 per graph node), and `graph.json`.
 */
final class GraphPhase
{
    /** @return array<string,int|string> counts for the log */
    public static function run(Workdir $w, Profile $profile): array
    {
        $net = Net::build($w->path('ways.bin'), $w->path('nodes.bin'), $w->path('restrictions.bin'), $profile);
        $nseg = $net->nseg();
        $log = ['segments' => $nseg, 'junctions' => $net->nj()];

        $shadows = [];
        $cid = [];
        $segdir = [];
        if ($profile->edgeBased()) {
            $g = EdgeGraph::build($net);
            $arcs = $g->arcs;
            $n = \count($g->cnodes);
            $inScc = Scc::largest($n, $arcs);
            $segdir = $g->cnodes;
            // a segment whose own node cannot be entered any more (every way in goes through a copy made for a
            // via-way restriction) is on the main network when one of its copies is
            $reachable = $inScc;
            foreach ($g->shadows as [$orig, $copy]) {
                if ($inScc[$copy]) {
                    $reachable[$orig] = true;
                }
            }
            $snapDirs = [];
            for ($d = 0; $d < $nseg * 2; $d++) {
                $c = $g->cid[$d];
                $snapDirs[] = $c !== Net::NONE && $reachable[$c];
            }
            $shadows = $g->shadows;
            $cid = $g->cid;
            $log['graph'] = $g->stats;
        } else {
            $arcs = NodeGraph::build($net, $profile);
            $n = $net->nj();
            $inScc = Scc::largest($n, $arcs);
            $snapDirs = [];
            for ($s = 0; $s < $nseg; $s++) {
                $ja = $net->junc($net->segA[$s]);
                $jb = $net->junc($net->segB[$s]);
                $ok = $ja !== Net::NONE && $jb !== Net::NONE && $inScc[$ja] && $inScc[$jb];
                $snapDirs[] = $ok && $net->dur[$s * 2] > 0;
                $snapDirs[] = $ok && $net->dur[$s * 2 + 1] > 0;
            }
            $log['graph'] = "node-based graph: $n nodes, " . $arcs->count() . ' arcs';
        }
        $log['nodes'] = $n;
        $log['scc'] = \count(array_filter($inScc));
        unset($inScc);

        self::writeArcs($w, $arcs);
        if ($segdir !== []) {
            $w->writeAtomic('segdir.bin', pack('V*', ...$segdir));
        } else {
            $w->writeAtomic('segdir.bin', '');
        }
        unset($segdir);

        // segments and geometry
        $segBuf = '';
        $geom = '';
        [$minla, $minlo, $maxla, $maxlo] = [PHP_INT_MAX, PHP_INT_MAX, PHP_INT_MIN, PHP_INT_MIN];
        $snappable = [];
        $edgeBased = $profile->edgeBased();
        for ($s = 0; $s < $nseg; $s++) {
            $off = \strlen($geom);
            $pla = 0;
            $plo = 0;
            foreach ($net->points($s) as $k => [$la, $lo]) {
                if ($k === 0) {
                    $geom .= pack('VV', $la & 0xFFFFFFFF, $lo & 0xFFFFFFFF);
                } else {
                    $geom .= Geom::varint(Geom::zigzag($la - $pla)) . Geom::varint(Geom::zigzag($lo - $plo));
                }
                $pla = $la;
                $plo = $lo;
                $minla = min($minla, $la);
                $maxla = max($maxla, $la);
                $minlo = min($minlo, $lo);
                $maxlo = max($maxlo, $lo);
            }
            $startOk = ($net->segFlags[$s] & Net::SEG_NO_START) === 0;
            $flags = ($net->dur[$s * 2] > 0 ? 1 : 0) | ($net->dur[$s * 2 + 1] > 0 ? 2 : 0);
            for ($d = 0; $d < 2; $d++) {
                if ($snapDirs[$s * 2 + $d] && $startOk) {
                    $flags |= 4 << $d;
                    $snappable[$s] = true;
                }
            }
            if (($net->segFlags[$s] & Net::SEG_RESTRICTED_FWD) !== 0) {
                $flags |= 16;
            }
            if (($net->segFlags[$s] & Net::SEG_RESTRICTED_BWD) !== 0) {
                $flags |= 32;
            }
            if ($edgeBased) {
                $nodes = [$cid[$s * 2], $cid[$s * 2 + 1]];
            } else {
                $nodes = [$net->junc($net->segA[$s]), $net->junc($net->segB[$s])];
            }
            $segBuf .= pack(
                'V10',
                $off,
                \strlen($geom) - $off,
                $net->lenM[$s],
                $flags,
                $net->dur[$s * 2],
                $net->dur[$s * 2 + 1],
                $nodes[0],
                $nodes[1],
                $net->w[$s * 2],
                $net->w[$s * 2 + 1],
            );
        }
        $w->writeAtomic('segments.bin', $segBuf);
        unset($segBuf);
        $w->writeAtomic('geometry.bin', $geom);
        unset($geom);

        // snapping grid
        /** @var array<int,list<int>> $cells */
        $cells = [];
        /** @var array<int,int> $lastIn the segment added last to each cell */
        $lastIn = [];
        foreach (array_keys($snappable) as $s) {
            $p = $net->points($s);
            $add = static function (int $la, int $lo) use (&$cells, &$lastIn, $s): void {
                $key = Geom::cellKey($la, $lo);
                if (($lastIn[$key] ?? -1) !== $s) {
                    $cells[$key][] = $s;
                    $lastIn[$key] = $s;
                }
            };
            $add($p[0][0], $p[0][1]);
            for ($k = 1, $m = \count($p); $k < $m; $k++) {
                [$a, $b] = [$p[$k - 1], $p[$k]];
                $steps = max(intdiv(max(abs($b[0] - $a[0]), abs($b[1] - $a[1])), 500), 1);
                for ($t = 1; $t <= $steps; $t++) {
                    $add(
                        $a[0] + intdiv(($b[0] - $a[0]) * $t, $steps),
                        $a[1] + intdiv(($b[1] - $a[1]) * $t, $steps),
                    );
                }
            }
        }
        ksort($cells);
        $gdir = '';
        $glist = '';
        foreach ($cells as $key => $v) {
            sort($v);
            $v = array_values(array_unique($v));
            $gdir .= pack('VV', $key, \strlen($glist));
            $glist .= pack('V', \count($v)) . pack('V*', ...$v);
        }
        $w->writeAtomic('grid_dir.bin', $gdir);
        $w->writeAtomic('grid_lists.bin', $glist);
        $cellCount = \count($cells);
        unset($cells, $lastIn, $gdir, $glist);

        $sh = '';
        foreach ($shadows as [$o, $s]) {
            $sh .= pack('VV', $o, $s);
        }
        $w->writeAtomic('shadows.bin', $sh);

        $w->writeJson('graph.json', [
            'nodes' => $n,
            'segments' => $nseg,
            'cells' => $cellCount,
            'toll_segments' => $net->tollSegments,
            'bbox' => [$minla, $minlo, $maxla, $maxlo],
            'edge_based' => $edgeBased,
        ]);
        $log['cells'] = $cellCount;
        $log['arcs'] = $arcs->count();

        return $log;
    }

    private static function writeArcs(Workdir $w, Arcs $arcs): void
    {
        $w->writeAtomic('arcs.bin', $arcs->buffer());
    }

    /** Writes the sorted `src dst weight duration distance` lines of `arcs.bin` (what `mcr build --dump-arcs` writes). */
    public static function dumpArcs(Workdir $w, string $outPath): void
    {
        $keys = [];
        $f = fopen($w->path('arcs.bin'), 'rb');
        if ($f === false) {
            throw new \RuntimeException('cannot read arcs.bin');
        }
        while (($raw = fread($f, Arcs::BYTES * 8192)) !== false && $raw !== '') {
            for ($o = 0, $e = \strlen($raw); $o + Arcs::BYTES <= $e; $o += Arcs::BYTES) {
                $keys[] = pack('N5', ...Bin::u32s($raw, 5, $o));
            }
        }
        fclose($f);
        sort($keys, SORT_STRING);
        $out = fopen($outPath . '.part', 'wb');
        if ($out === false) {
            throw new \RuntimeException("cannot write $outPath");
        }
        $buf = '';
        foreach ($keys as $k) {
            $buf .= implode(' ', Bin::list('N5', $k)) . "\n";
            if (\strlen($buf) >= 1 << 20) {
                fwrite($out, $buf);
                $buf = '';
            }
        }
        fwrite($out, $buf);
        fclose($out);
        rename($outPath . '.part', $outPath);
    }
}
