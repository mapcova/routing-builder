#!/usr/bin/env php
<?php

declare(strict_types=1);

use MapCova\GraphBuilder\Query\Router;
use MapCova\GraphBuilder\Support\Bin;
use MapCova\GraphBuilder\Tiles\TileSet;

require __DIR__ . '/../vendor/autoload.php';

/**
 * The merged graph of all the tiles, and the pairs of an acceptance run, for `tools/merged-dijkstra.c`:
 *
 *   php tools/merged-export.php export <tiles.json> <dir> <pairs.json> <out dir>
 *   cc -O2 -o merged-dijkstra tools/merged-dijkstra.c && merged-dijkstra <out>/graph.bin <out>/queries.bin <out>/results.bin
 *   php tools/merged-export.php compare <pairs.json> <out dir>
 *
 * The graph is made of the arcs the tiles were built from (`arcs.bin` of each work directory, kept with `--keep-work`: the
 * graph before any contraction) and of their cross arcs (`cross.bin`, the other end named by its key in the `border.bin` of the
 * other tile): the graph of one whole PHP build. A route is the cheapest way from the labels the reader puts at the snapped
 * start to the labels at the snapped end; the labels are carried back to the node numbers of the graph. `compare` checks that
 * the layered reader (the weights `tools/tiles-acceptance.php --pairs-out` recorded) found the same weight, or none, for every pair.
 * The work directories are `<dir>/.<tile>.work`.
 */
$argv = [];
foreach (is_array($_SERVER['argv'] ?? null) ? $_SERVER['argv'] : [] as $a) {
    if (is_string($a)) {
        $argv[] = $a;
    }
}
ini_set('memory_limit', '4G');
$mode = $argv[1] ?? '';
if ($mode === 'compare' && count($argv) >= 4) {
    $pairs = json_decode((string) file_get_contents($argv[2]), true);
    $res = (string) file_get_contents($argv[3] . '/results.bin');
    if (!is_array($pairs) || strlen($res) !== 8 * count($pairs)) {
        fwrite(STDERR, "results.bin does not match the pairs\n");
        exit(1);
    }
    $same = 0;
    $bothNone = 0;
    $bad = [];
    foreach (array_values($pairs) as $i => $p) {
        $w = unpack('q', $res, $i * 8)[1] ?? -1;
        $w = $w < 0 ? null : $w;
        $t = is_array($p) && isset($p['tiles']) && is_int($p['tiles']) ? $p['tiles'] : null;
        if ($w === $t) {
            ++$same;
            $bothNone += $w === null ? 1 : 0;
        } else {
            $bad[] = ['pair' => $i, 'merged' => $w, 'tiles' => $t];
        }
    }
    printf("%d pairs: %d identical (%d of them without a route in both), %d different\n", count($pairs), $same, $bothNone, count($bad));
    foreach (array_slice($bad, 0, 20) as $b) {
        echo json_encode($b), "\n";
    }
    exit($bad === [] ? 0 : 1);
}
if ($mode !== 'export' || count($argv) < 6) {
    fwrite(STDERR, "usage: php tools/merged-export.php export <tiles.json> <dir> <pairs.json> <out dir> | compare <pairs.json> <out dir>\n");
    exit(2);
}
[, , $tilesFile, $dir, $pairsFile, $out] = $argv;
if (!is_dir($out)) {
    mkdir($out, 0777, true);
}
$ts = TileSet::load($tilesFile);

// node numbers: the graph of each tile (before the contraction) side by side
$off = [];
$total = 0;
foreach ($ts->ids as $i => $id) {
    $g = json_decode((string) file_get_contents("$dir/.$id.work/graph.json"), true);
    if (!is_array($g) || !is_int($g['nodes'] ?? null)) {
        throw new RuntimeException("$id has no graph.json in its work directory");
    }
    $off[$i] = $total;
    $total += $g['nodes'];
}
/** @var array<int,array<string,int>> $nodeOfKey tile => key => graph node, of its border vertices */
$nodeOfKey = [];
foreach ($ts->ids as $i => $id) {
    $raw = (string) file_get_contents("$dir/.$id.work/border.bin");
    for ($o = 0; $o + 36 <= strlen($raw); $o += 36) {
        $nodeOfKey[$i][substr($raw, $o, 28)] = Bin::u32($raw, $o + 28);
    }
}
$f = fopen("$out/graph.bin", 'wb');
if ($f === false) {
    throw new RuntimeException('cannot write graph.bin');
}
fwrite($f, pack('VV', $total, 0));
$arcs = 0;
foreach ($ts->ids as $i => $id) {
    $h = fopen("$dir/.$id.work/arcs.bin", 'rb');
    if ($h === false) {
        throw new RuntimeException("cannot read the arcs of $id");
    }
    while (($raw = fread($h, 24 * 8192)) !== false && $raw !== '') {
        $buf = '';
        for ($o = 0, $e = strlen($raw); $o + 24 <= $e; $o += 24) {
            [$s, $d, $w] = Bin::u32s($raw, 3, $o);
            if ($s === $d) {
                continue;
            }
            $buf .= pack('VVV', $off[$i] + $s, $off[$i] + $d, max($w, 1));
            ++$arcs;
        }
        fwrite($f, $buf);
    }
    fclose($h);
    $c = (string) file_get_contents("$dir/.$id.work/cross.bin");
    $buf = '';
    for ($o = 0; $o + 52 <= strlen($c); $o += 52) {
        [$node, $kind] = Bin::u32s($c, 2, $o);
        if ($kind !== 0) {
            continue; // the same arc is in the file of the tile it enters
        }
        $other = Bin::u32s($c, 2, $o + 36)[0];
        $to = $nodeOfKey[$other][substr($c, $o + 8, 28)] ?? null;
        if ($to === null) {
            throw new RuntimeException("a cross arc of $id has no twin in {$ts->ids[$other]}");
        }
        $buf .= pack('VVV', $off[$i] + $node, $off[$other] + $to, max(Bin::u32($c, $o + 40), 1));
        ++$arcs;
    }
    fwrite($f, $buf);
}
fseek($f, 4);
fwrite($f, pack('V', $arcs));
fclose($f);
printf("graph: %d nodes, %d arcs\n", $total, $arcs);

// the pairs: labels at the start and at the end, in graph node numbers
/** @var array<int,list<int>> $order */
$order = [];
$oldId = static function (int $tile, int $final) use (&$order, $dir, $ts): int {
    if (!isset($order[$tile])) {
        $order[$tile] = Bin::allU32((string) file_get_contents("$dir/.{$ts->ids[$tile]}.work/order.bin"));
    }
    $nn = count($order[$tile]);

    return $final < $nn ? $order[$tile][$nn - 1 - $final] : $final;
};
$router = new Router($ts, $dir);
$pairs = json_decode((string) file_get_contents($pairsFile), true);
if (!is_array($pairs)) {
    throw new RuntimeException('bad pairs file');
}
$q = fopen("$out/queries.bin", 'wb');
if ($q === false) {
    throw new RuntimeException('cannot write queries.bin');
}
fwrite($q, pack('V', count($pairs)));
$missing = 0;
foreach ($pairs as $p) {
    $a = is_array($p) && is_array($p['from'] ?? null) ? array_values($p['from']) : [];
    $b = is_array($p) && is_array($p['to'] ?? null) ? array_values($p['to']) : [];
    if (count($a) !== 2 || count($b) !== 2 || !is_numeric($a[0]) || !is_numeric($a[1]) || !is_numeric($b[0]) || !is_numeric($b[1])) {
        throw new RuntimeException('bad pair');
    }
    $sa = $router->snap((float) $a[0], (float) $a[1]);
    $sb = $router->snap((float) $b[0], (float) $b[1]);
    $src = [];
    $dst = [];
    if ($sa !== null && $sb !== null) {
        $ta = $ts->index($sa[0]);
        $tb = $ts->index($sb[0]);
        foreach ($router->tile($sa[0])->sourceLabels($sa[1]) as $n => $l) {
            $src[$off[$ta] + $oldId($ta, $n)] = $l[0];
        }
        foreach ($router->tile($sb[0])->targetLabels($sb[1]) as $n => $l) {
            $dst[$off[$tb] + $oldId($tb, $n)] = $l[0];
        }
    } else {
        ++$missing;
    }
    $buf = pack('V', count($src));
    foreach ($src as $n => $w) {
        $buf .= pack('VV', $n, $w & 0xFFFFFFFF);
    }
    $buf .= pack('V', count($dst));
    foreach ($dst as $n => $w) {
        $buf .= pack('VV', $n, $w & 0xFFFFFFFF);
    }
    fwrite($q, $buf);
}
fclose($q);
printf("%d pairs written (%d without a snap)\n", count($pairs), $missing);
