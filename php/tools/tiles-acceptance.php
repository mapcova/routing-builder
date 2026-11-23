#!/usr/bin/env php
<?php

declare(strict_types=1);

use MapCova\GraphBuilder\Query\Geo;
use MapCova\GraphBuilder\Query\Router;
use MapCova\GraphBuilder\Query\Search;
use MapCova\GraphBuilder\Query\SingleFile;
use MapCova\GraphBuilder\Query\TileFile;
use MapCova\GraphBuilder\Tiles\TileSet;

require __DIR__ . '/../vendor/autoload.php';

/**
 * Acceptance of the tiles and links against the whole graph.
 *
 *   php tools/tiles-acceptance.php --tiles tiles.json --dir <tiles, links, transit> --ref <whole.mcr> [--flat tiles-flat.json]
 *       [--pairs 1000] [--seed 1] [--geometry 300] [--latency 200] [--flat-pairs 150] [--json report.json]
 *
 * Pairs (fixed seed): a fifth in one tile close to the Belgium - Luxembourg border near Arlon and Martelange (the route may
 * leave the tile and come back), a fifth between Luxembourg and Belgium, the rest random points of the network. Each pair is
 * routed on the whole graph and on the tiles: the weight must be within 0.1 % (and is counted when identical), the duration
 * within a second, no route of the whole graph may be missed. Then the geometry of some routes (continuity at the joints,
 * length), the latency of the same-tile and Belgium - Luxembourg routes (warm: the files and their caches already used by the
 * same route; cold: a new reader per route, the files opened again and no cache, the pages of the files still in the system's
 * cache), the nodes settled and the files opened per route, and, with `--flat`, the same pairs through one link over all the
 * tiles. `--pairs-out` writes the pairs (and the weight the tiles found) for `tools/merged-check.php`.
 */
$o = getopt('', ['tiles:', 'dir:', 'ref:', 'flat:', 'pairs:', 'seed:', 'geometry:', 'latency:', 'flat-pairs:', 'json:', 'pairs-out:']);
if (!is_array($o) || !isset($o['tiles'], $o['dir'], $o['ref']) || !is_string($o['tiles']) || !is_string($o['dir']) || !is_string($o['ref'])) {
    fwrite(STDERR, "usage: php tools/tiles-acceptance.php --tiles <tiles.json> --dir <dir> --ref <whole.mcr> [--flat <tiles-flat.json>] [--pairs 1000] [--seed 1] [--geometry 300] [--latency 200] [--flat-pairs 150] [--json <file>] [--pairs-out <file>]\n");
    exit(2);
}
ini_set('memory_limit', '4G');
$opt = static fn (string $k, int $d): int => isset($o[$k]) && is_string($o[$k]) ? (int) $o[$k] : $d;
$nPairs = $opt('pairs', 1000);
$seed = $opt('seed', 1);
$nGeom = $opt('geometry', 300);
$nLat = $opt('latency', 200);
$nFlat = $opt('flat-pairs', 150);

/**
 * @param list<array{float,float}> $g (lat, lon) in degrees
 * @return list<array{int,int}> in 1e-6 degree
 */
function micro(array $g): array
{
    $out = [];
    foreach ($g as $q) {
        $out[] = [(int) round($q[0] * 1e6), (int) round($q[1] * 1e6)];
    }

    return $out;
}

/** @param list<float|int> $v */
function pct(array $v, float $p): float
{
    if ($v === []) {
        return 0.0;
    }
    sort($v);

    return (float) $v[(int) min(\count($v) - 1, floor($p * \count($v)))];
}

$ts = TileSet::load($o['tiles']);
$router = new Router($ts, $o['dir']);
$ref = new TileFile($o['ref']);
$single = new SingleFile($ref);
$luIdx = $ts->index('lu');

/** @return array{float,float} a random point on the network of the whole graph, in a region, as (lat, lon) */
$point = static function (callable $accept) use ($ref, $ts): array {
    for ($i = 0; $i < 2_000_000; $i++) {
        $s = mt_rand(0, $ref->segments - 1);
        if (!$ref->usable($s)) {
            continue;
        }
        $p = $ref->geom($s);
        $k = mt_rand(0, \count($p) - 1);
        $la = $p[$k][0] + mt_rand(-150, 150);
        $lo = $p[$k][1] + mt_rand(-200, 200);
        if ($ts->covers($la, $lo) && $accept($la, $lo)) {
            return [$la / 1e6, $lo / 1e6];
        }
    }
    throw new RuntimeException('no point in the region');
};

mt_srand($seed);
$near = static function (int $la, int $lo): bool {
    foreach ([[49.683, 5.816], [49.834, 5.738]] as [$cla, $clo]) {
        if (hypot(($la / 1e6 - $cla) * 111.2, ($lo / 1e6 - $clo) * 111.2 * cos(deg2rad($cla))) <= 10.0) {
            return true;
        }
    }

    return false;
};
$pairs = [];
$nA = intdiv($nPairs, 5);
$nB = intdiv($nPairs, 5);
for ($i = 0; $i < $nA; $i++) {
    // both ends in the same Belgian tile, near the border with Luxembourg
    $a = $point(static fn (int $la, int $lo): bool => $near($la, $lo) && $ts->ownerOf($la, $lo) !== $luIdx);
    $tile = $ts->ownerOf((int) round($a[0] * 1e6), (int) round($a[1] * 1e6));
    $b = $point(static fn (int $la, int $lo): bool => $near($la, $lo) && $ts->ownerOf($la, $lo) === $tile);
    $pairs[] = ['border', $a, $b];
}
for ($i = 0; $i < $nB; $i++) {
    $a = $point(static fn (int $la, int $lo): bool => $ts->ownerOf($la, $lo) === $luIdx && $lo > 5_700_000 && $lo < 6_550_000 && $la > 49_400_000 && $la < 50_200_000);
    $b = $point(static fn (int $la, int $lo): bool => $ts->ownerOf($la, $lo) !== $luIdx && $lo < 6_400_000 && $la > 49_480_000 && $la < 51_520_000 && $lo > 2_500_000);
    $pairs[] = $i % 2 === 0 ? ['be-lu', $a, $b] : ['be-lu', $b, $a];
}
while (\count($pairs) < $nPairs) {
    $a = $point(static fn (int $la, int $lo): bool => $lo > 2_500_000 && $lo < 6_450_000 && $la > 49_480_000 && $la < 51_520_000);
    $b = $point(static fn (int $la, int $lo): bool => $lo > 2_500_000 && $lo < 6_450_000 && $la > 49_480_000 && $la < 51_520_000);
    $pairs[] = ['random', $a, $b];
}

$stats = [];
$bump = static function (string $cat, string $k, int $n = 1) use (&$stats): void {
    $stats[$cat][$k] = ($stats[$cat][$k] ?? 0) + $n;
    $stats['all'][$k] = ($stats['all'][$k] ?? 0) + $n;
};
$bad = [];
$levels = [];
$files = [];
$refLenErr = [];
$tileLenErr = [];
$gaps = [];
$ids = 0;
$refTime = 0.0;
$found = [];
$tilesTime = 0.0;
foreach ($pairs as $i => [$cat, $a, $b]) {
    $bump($cat, 'pairs');
    $t0 = hrtime(true);
    $x = $single->route($a[0], $a[1], $b[0], $b[1], $i < $nGeom);
    $t1 = hrtime(true);
    $y = $router->route($a[0], $a[1], $b[0], $b[1], $i < $nGeom);
    $t2 = hrtime(true);
    $found[$i] = $y === null ? null : $y['weight'];
    $refTime += ($t1 - $t0) / 1e9;
    $tilesTime += ($t2 - $t1) / 1e9;
    if ($x === null || $y === null) {
        if ($x === null && $y === null) {
            $bump($cat, 'unroutable');
        } elseif ($x !== null) {
            $bump($cat, 'missed_by_tiles');
            $bad[] = ['pair' => $i, 'cat' => $cat, 'why' => 'missed by the tiles', 'ref' => $x['weight'], 'from' => $a, 'to' => $b];
        } else {
            $bump($cat, 'found_only_by_tiles');
            $bad[] = ['pair' => $i, 'cat' => $cat, 'why' => 'only the tiles found it', 'tiles' => $y['weight'], 'from' => $a, 'to' => $b];
        }
        continue;
    }
    $bump($cat, 'both');
    $same = abs($x['weight'] - $y['weight']) <= 0.001 * max($x['weight'], 1);
    if ($x['weight'] === $y['weight']) {
        $bump($cat, 'weight_identical');
    }
    if ($same) {
        $bump($cat, 'weight_within_0.1pct');
    }
    $durOk = abs($x['duration'] - $y['duration']) <= 1.0;
    if ($durOk) {
        $bump($cat, 'duration_within_1s');
    }
    if (abs($x['distance'] - $y['distance']) <= 1.0) {
        $bump($cat, 'distance_within_1m');
    }
    if (!$same || !$durOk) {
        $bad[] = ['pair' => $i, 'cat' => $cat, 'ref' => [$x['weight'], $x['duration'], $x['distance']], 'tiles' => [$y['weight'], $y['duration'], $y['distance']], 'level' => $y['level'], 'from' => $a, 'to' => $b];
    }
    $levels[$y['level']] = ($levels[$y['level']] ?? 0) + 1;
    $files[] = $y['files'];
    if (isset($y['geometry'], $x['geometry'])) {
        $bump($cat, 'geometry');
        $lt = Geo::length(micro($y['geometry']));
        $lr = Geo::length(micro($x['geometry']));
        $tileLenErr[] = abs($lt - $y['distance']);
        $refLenErr[] = abs($lr - $x['distance']);
        $gaps[] = $y['gap'] ?? 0.0;
        if (($y['gap'] ?? 0.0) <= 1.0) {
            $bump($cat, 'gap_within_1m');
        }
        if (abs($lt - $y['distance']) <= 1.0) {
            $bump($cat, 'length_within_1m');
        }
        if (abs($lt - $lr) <= 1.0) {
            $bump($cat, 'length_as_whole_graph_within_1m');
        }
    }
    if ($i % 100 === 99) {
        fprintf(STDERR, "%d pairs\n", $i + 1);
    }
}

// latency: the same-tile pairs near the border and the Belgium - Luxembourg pairs
/**
 * @param list<float> $v
 * @return array{p50:float,p95:float,max:float}
 */
function row(array $v): array
{
    return ['p50' => round(pct($v, 0.5), 1), 'p95' => round(pct($v, 0.95), 1), 'max' => round($v === [] ? 0.0 : max($v), 1)];
}

/**
 * Latency, settled nodes and files per route of a set of pairs.
 *
 * @param list<array{string,array{float,float},array{float,float}}> $set
 * @return array<string,mixed>
 */
function timings(array $set, Router $router, TileSet $ts, string $dir): array
{
    // warm: the routes of the set once, then each again with the files and their caches as the first pass left them
    foreach ($set as [, $a, $b]) {
        $router->route($a[0], $a[1], $b[0], $b[1], true);
    }
    $warm = [];
    $warmGeometry = [];
    $settled = [];
    $opened = [];
    foreach ($set as [, $a, $b]) {
        $s0 = Search::$settled;
        $t = hrtime(true);
        $r = $router->route($a[0], $a[1], $b[0], $b[1], false);
        $warm[] = (hrtime(true) - $t) / 1e6;
        $settled[] = Search::$settled - $s0;
        $opened[] = $r['files'] ?? 0;
    }
    foreach ($set as [, $a, $b]) {
        $t = hrtime(true);
        $router->route($a[0], $a[1], $b[0], $b[1], true);
        $warmGeometry[] = (hrtime(true) - $t) / 1e6;
    }
    // cold: a new reader for each route
    $cold = [];
    $coldGeometry = [];
    foreach ($set as [, $a, $b]) {
        $t = hrtime(true);
        (new Router($ts, $dir))->route($a[0], $a[1], $b[0], $b[1], false);
        $cold[] = (hrtime(true) - $t) / 1e6;
    }
    foreach ($set as [, $a, $b]) {
        $t = hrtime(true);
        (new Router($ts, $dir))->route($a[0], $a[1], $b[0], $b[1], true);
        $coldGeometry[] = (hrtime(true) - $t) / 1e6;
    }

    return [
        'routes' => \count($set),
        'warm' => row($warm),
        'warm_geometry' => row($warmGeometry),
        'cold' => row($cold),
        'cold_geometry' => row($coldGeometry),
        'settled_nodes' => ['mean' => $settled === [] ? 0 : round(array_sum($settled) / \count($settled)), 'p95' => pct($settled, 0.95), 'max' => $settled === [] ? 0 : max($settled)],
        'files_per_route' => ['mean' => $opened === [] ? 0 : round(array_sum($opened) / \count($opened), 1), 'max' => $opened === [] ? 0 : max($opened)],
    ];
}

$latency = [];
foreach (['border' => 'same_tile_near_border', 'be-lu' => 'be_lu'] as $cat => $name) {
    $set = array_values(array_filter($pairs, static fn (array $p): bool => $p[0] === $cat));
    $latency[$name] = timings(\array_slice($set, 0, $nLat), $router, $ts, $o['dir']);
}

$report = [
    'pairs' => $nPairs,
    'seed' => $seed,
    'stats' => $stats,
    'mismatches' => \array_slice($bad, 0, 40),
    'mismatch_count' => \count($bad),
    'levels' => $levels,
    'files_per_route' => ['mean' => $files === [] ? 0 : array_sum($files) / \count($files), 'max' => $files === [] ? 0 : max($files)],
    'geometry' => [
        'gap_max_m' => $gaps === [] ? 0 : max($gaps),
        'tile_length_error_m' => ['median' => pct($tileLenErr, 0.5), 'p95' => pct($tileLenErr, 0.95), 'max' => $tileLenErr === [] ? 0 : max($tileLenErr)],
        'whole_graph_length_error_m' => ['median' => pct($refLenErr, 0.5), 'p95' => pct($refLenErr, 0.95), 'max' => $refLenErr === [] ? 0 : max($refLenErr)],
    ],
    'seconds' => ['whole_graph' => round($refTime, 1), 'tiles' => round($tilesTime, 1)],
    'latency_ms' => $latency,
];

if (isset($o['flat']) && is_string($o['flat'])) {
    $flat = new Router(TileSet::load($o['flat']), $o['dir']);
    $n = 0;
    $same = 0;
    $diff = [];
    foreach (\array_slice($pairs, 0, $nFlat) as $i => [, $a, $b]) {
        $x = $router->route($a[0], $a[1], $b[0], $b[1]);
        $y = $flat->route($a[0], $a[1], $b[0], $b[1]);
        if ($x === null && $y === null) {
            continue;
        }
        ++$n;
        if ($x !== null && $y !== null && $x['weight'] === $y['weight'] && abs($x['duration'] - $y['duration']) <= 0.05 && abs($x['distance'] - $y['distance']) <= 0.5) {
            ++$same;
        } else {
            $diff[] = ['pair' => $i, 'layered' => $x === null ? null : [$x['weight'], $x['duration'], $x['distance']], 'flat' => $y === null ? null : [$y['weight'], $y['duration'], $y['distance']]];
        }
    }
    $report['flat_vs_layered'] = ['pairs' => $n, 'identical' => $same, 'differences' => $diff];
}

if (isset($o['pairs-out']) && is_string($o['pairs-out'])) {
    $out = [];
    foreach ($pairs as $i => [$cat, $a, $b]) {
        $out[] = ['cat' => $cat, 'from' => $a, 'to' => $b, 'tiles' => $found[$i] ?? null];
    }
    file_put_contents($o['pairs-out'], json_encode($out, JSON_THROW_ON_ERROR) . "\n");
}

$json = json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
if (isset($o['json']) && is_string($o['json'])) {
    file_put_contents($o['json'], $json . "\n");
}
echo $json, "\n";
