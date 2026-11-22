<?php

declare(strict_types=1);

namespace MapCova\GraphBuilder\Build;

use MapCova\GraphBuilder\Ch\Contractor;
use MapCova\GraphBuilder\Mcr\Format;
use MapCova\GraphBuilder\Net\Net;
use MapCova\GraphBuilder\Profile\Profile;
use MapCova\GraphBuilder\Support\Bin;
use MapCova\GraphBuilder\Support\Io;
use MapCova\GraphBuilder\Support\Json;
use RuntimeException;

/**
 * Phase `finish`: gives the nodes their final ids (rank from the top of the hierarchy), writes the node and arc
 * sections from the contraction records, joins the sections prepared by the earlier phases, and writes the `.mcr`
 * file (to `<out>.part`, then renamed: the file is whole or absent). Second half of `build_data` and `ch::contract`.
 */
final class FinishPhase
{
    /**
     * @param list<list<array{int,int}>> $polygon rings of (lat, lon) in 1e-6 degree, already simplified
     * @return array<string,int> sizes for the log
     */
    public static function run(Workdir $w, Profile $profile, string $zone, int $nn, int $osmTimestamp, int $builtAt, array $polygon, string $outPath): array
    {
        $graph = $w->readJson('graph.json');
        if ($graph === null) {
            throw new RuntimeException('graph.json is missing');
        }
        $n = Json::int($graph, 'nodes');
        $nseg = Json::int($graph, 'segments');
        [$bboxLa0, $bboxLo0, $bboxLa1, $bboxLo1] = Json::ints($graph, 'bbox');
        $bbox = [$bboxLa0, $bboxLo0, $bboxLa1, $bboxLo1];

        // order of the contraction -> new ids
        $order = Bin::allU32((string) file_get_contents($w->path('order.bin')));
        $idx = Bin::allU64((string) file_get_contents($w->path('upidx.bin')));
        if (\count($order) !== $nn) {
            throw new RuntimeException('the contraction is incomplete');
        }
        $newId = array_fill(0, $n, 0);
        for ($r = 0; $r < $nn; $r++) {
            $newId[$order[$r]] = $nn - 1 - $r;
        }
        for ($i = $nn; $i < $n; $i++) {
            $newId[$i] = $i;
        }
        $segdir = Bin::allU32((string) file_get_contents($w->path('segdir.bin')));
        clearstatcache(); // sizes are read again: the contraction wrote these files in this very process
        $upSize = (int) filesize($w->path('up.bin'));

        // node and arc sections, nodes from the top of the hierarchy
        $fNodes = self::out($w, 'sec-nodes.bin');
        $fFwd = self::out($w, 'sec-up_fwd.bin');
        $fBwd = self::out($w, 'sec-up_bwd.bin');
        $up = fopen($w->path('up.bin'), 'rb');
        $nFwd = 0;
        $nBwd = 0;
        try {
            if ($up === false) {
                throw new RuntimeException('cannot open up.bin');
            }
            $bufN = '';
            $bufF = '';
            $bufB = '';
            for ($ni = 0; $ni < $n; $ni++) {
                $old = $ni < $nn ? $order[$nn - 1 - $ni] : $ni;
                $bufN .= pack('VVV', $nFwd, $nBwd, $segdir[$old] ?? 0);
                if ($ni < $nn) {
                    $r = $nn - 1 - $ni;
                    $len = ($r + 1 < $nn ? $idx[$r + 1] : $upSize) - $idx[$r];
                    fseek($up, $idx[$r]);
                    $raw = $len > 0 ? (string) fread($up, $len) : '';
                    if (\strlen($raw) !== $len) {
                        throw new RuntimeException('up.bin is shorter than its index');
                    }
                    $u = Bin::allU32($raw);
                    $cf = $u[1];
                    $cb = $u[2];
                    $p = 3;
                    for ($k = 0; $k < $cf + $cb; $k++, $p += 5) {
                        $mid = $u[$p + 2];
                        $rec = pack('VVVVV', $newId[$u[$p]], $u[$p + 1], $u[$p + 3], $u[$p + 4], ($mid & Contractor::ORIGINAL) !== 0 ? $mid : $newId[$mid]);
                        if ($k < $cf) {
                            $bufF .= $rec;
                            $nFwd++;
                        } else {
                            $bufB .= $rec;
                            $nBwd++;
                        }
                    }
                }
                if (\strlen($bufN) > 1 << 20 || \strlen($bufF) > 1 << 20 || \strlen($bufB) > 1 << 20) {
                    Io::write($fNodes, $bufN, 'sec-nodes.bin');
                    Io::write($fFwd, $bufF, 'sec-up_fwd.bin');
                    Io::write($fBwd, $bufB, 'sec-up_bwd.bin');
                    $bufN = $bufF = $bufB = '';
                }
            }
            Io::write($fNodes, $bufN . pack('VVV', $nFwd, $nBwd, 0), 'sec-nodes.bin');
            Io::write($fFwd, $bufF, 'sec-up_fwd.bin');
            Io::write($fBwd, $bufB, 'sec-up_bwd.bin');
            Io::close($fNodes, 'sec-nodes.bin');
            Io::close($fFwd, 'sec-up_fwd.bin');
            Io::close($fBwd, 'sec-up_bwd.bin');
        } finally {
            foreach ([$fNodes, $fFwd, $fBwd, $up] as $h) {
                if (\is_resource($h)) {
                    fclose($h);
                }
            }
        }
        unset($order, $idx, $u);

        // segments: graph node fields in final ids, a few thousand at a time
        $segIn = fopen($w->path('segments.bin'), 'rb');
        $fSeg = self::out($w, 'sec-segments.bin');
        try {
            if ($segIn === false) {
                throw new RuntimeException('cannot open segments.bin');
            }
            for ($s = 0; $s < $nseg; $s += 4096) {
                $m = min(4096, $nseg - $s);
                $seg = (string) fread($segIn, max(1, $m * Format::SEG_BYTES));
                if (\strlen($seg) !== $m * Format::SEG_BYTES) {
                    throw new RuntimeException('segments.bin is shorter than graph.json says');
                }
                $segOut = '';
                for ($k = 0; $k < $m; $k++) {
                    $r = Bin::u32s($seg, 10, $k * Format::SEG_BYTES);
                    $r[6] = $r[6] === Net::NONE ? Net::NONE : $newId[$r[6]];
                    $r[7] = $r[7] === Net::NONE ? Net::NONE : $newId[$r[7]];
                    $segOut .= pack('V10', ...$r);
                }
                Io::write($fSeg, $segOut, 'sec-segments.bin');
            }
            Io::close($fSeg, 'sec-segments.bin');
        } finally {
            foreach ([$segIn, $fSeg] as $h) {
                if (\is_resource($h)) {
                    fclose($h);
                }
            }
        }
        unset($seg, $segOut);

        // shadows, sorted by original node
        $shRaw = (string) file_get_contents($w->path('shadows.bin'));
        $pairs = [];
        for ($o = 0, $e = \strlen($shRaw); $o + 8 <= $e; $o += 8) {
            [$a, $b] = Bin::u32s($shRaw, 2, $o);
            $pairs[] = pack('NN', $newId[$a], $newId[$b]);
        }
        sort($pairs, SORT_STRING);
        $sh = '';
        foreach ($pairs as $pr) {
            $r = Bin::ints('Na/Nb', $pr);
            $sh .= pack('VV', $r['a'], $r['b']);
        }
        $w->writeAtomic('sec-shadows.bin', $sh);

        // metadata
        $poly = [];
        $zb = [PHP_INT_MAX, PHP_INT_MAX, PHP_INT_MIN, PHP_INT_MIN];
        $any = false;
        foreach ($polygon as $ring) {
            $pts = [];
            foreach ($ring as [$la, $lo]) {
                $any = true;
                $zb = [min($zb[0], $la), min($zb[1], $lo), max($zb[2], $la), max($zb[3], $lo)];
                $pts[] = sprintf('[%.6F,%.6F]', $la * 1e-6, $lo * 1e-6);
            }
            $poly[] = '[' . implode(',', $pts) . ']';
        }
        if ($any) {
            $bbox = $zb;
        }
        $meta = sprintf(
            '{"format":%d,"zone":%s,"profile":%s,"graph":%s,"toll_segments":%d,"osm_timestamp":%d,"built_at":%d,"builder":%s,"bbox":[%.6F,%.6F,%.6F,%.6F],"polygon":[%s],"license":%s,"attribution":%s}',
            Format::VERSION,
            Format::jsonStr($zone),
            Format::jsonStr($profile->name()),
            Format::jsonStr($profile->edgeBased() ? 'edge' : 'node'),
            Json::int($graph, 'toll_segments'),
            $osmTimestamp,
            $builtAt,
            Format::jsonStr(Format::BUILDER),
            $bbox[0] * 1e-6,
            $bbox[1] * 1e-6,
            $bbox[2] * 1e-6,
            $bbox[3] * 1e-6,
            implode(',', $poly),
            Format::jsonStr(Format::LICENSE),
            Format::jsonStr(Format::ATTRIBUTION),
        );
        $w->writeAtomic('sec-meta.bin', $meta);

        $sections = ['sec-nodes.bin', 'sec-up_fwd.bin', 'sec-up_bwd.bin', 'sec-segments.bin', 'geometry.bin', 'grid_dir.bin', 'grid_lists.bin', 'sec-shadows.bin', 'sec-meta.bin'];
        $lengths = [];
        foreach ($sections as $name) {
            $lengths[] = (int) filesize($w->path($name));
        }
        [$head] = Format::header([
            'profile' => $profile->id(),
            'edgeBased' => $profile->edgeBased(),
            'nodes' => $n,
            'upFwd' => $nFwd,
            'upBwd' => $nBwd,
            'segments' => $nseg,
            'cells' => Json::int($graph, 'cells'),
            'bbox' => $bbox,
            'osmTimestamp' => $osmTimestamp,
            'builtAt' => $builtAt,
            'builder' => Format::BUILDER,
            'zone' => $zone,
        ], $lengths);

        $part = $outPath . '.part';
        $f = fopen($part, 'wb');
        if ($f === false) {
            throw new RuntimeException("cannot write $part");
        }
        try {
            Io::write($f, $head, $part);
            $total = \strlen($head);
            foreach ($sections as $i => $name) {
                $in = fopen($w->path($name), 'rb');
                if ($in === false) {
                    throw new RuntimeException("cannot read $name");
                }
                try {
                    Io::copy($in, $f, $lengths[$i], $name);
                } finally {
                    fclose($in);
                }
                $pad = (4096 - $lengths[$i] % 4096) % 4096;
                Io::write($f, str_repeat("\0", $pad), $part);
                $total += $lengths[$i] + $pad;
            }
            Io::close($f, $part);
            clearstatcache(true, $part);
            if (filesize($part) !== $total) {
                throw new RuntimeException("$part has " . filesize($part) . " bytes, expected $total");
            }
            if (!rename($part, $outPath)) {
                throw new RuntimeException("cannot rename $part to $outPath");
            }
        } catch (\Throwable $e) {
            if (\is_resource($f)) {
                fclose($f);
            }
            @unlink($part);
            throw $e;
        }

        return ['nodes' => $n, 'up_fwd' => $nFwd, 'up_bwd' => $nBwd, 'segments' => $nseg, 'bytes' => (int) filesize($outPath)];
    }

    /** @return resource */
    private static function out(Workdir $w, string $name)
    {
        $f = fopen($w->path($name), 'wb');
        if ($f === false) {
            throw new RuntimeException("cannot write $name");
        }

        return $f;
    }
}
