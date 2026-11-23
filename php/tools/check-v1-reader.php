#!/usr/bin/env php
<?php

declare(strict_types=1);

use MapCova\GraphBuilder\Query\SingleFile;
use MapCova\GraphBuilder\Query\TileFile;

require __DIR__ . '/../vendor/autoload.php';

/**
 * A tile is a version 1 file with two more sections: the reader of the MapCova `local-routing` extension (version 1,
 * unchanged) must read it and route inside it as the reference reader of this repository does.
 *
 *   php tools/check-v1-reader.php <McrGraph.php of plus-local-routing> <tile.mcr> [pairs, default 300]
 *
 * Random points in the bounding box of the tile; the pairs that both readers can route must have the same duration (1 s) and
 * distance (1 m).
 */
$argv = [];
foreach (is_array($_SERVER['argv'] ?? null) ? $_SERVER['argv'] : [] as $a) {
    if (is_string($a)) {
        $argv[] = $a;
    }
}
if (count($argv) < 3) {
    fwrite(STDERR, "usage: php tools/check-v1-reader.php <McrGraph.php> <tile.mcr> [pairs]\n");
    exit(2);
}
ini_set('memory_limit', '1G');
require $argv[1];
$name = implode('\\', ['MapCovaPlus', 'LocalRouting', 'Graph', 'McrGraph']);
if (!class_exists($name)) {
    fwrite(STDERR, "$argv[1] does not define $name\n");
    exit(2);
}
$external = new $name($argv[2]);
$route = [$external, 'route'];
if (!is_callable($route)) {
    fwrite(STDERR, "$name has no route()\n");
    exit(2);
}
$mine = new TileFile($argv[2]);
$meta = $mine->meta();
$bbox = is_array($meta['bbox'] ?? null) ? $meta['bbox'] : [];
if (count($bbox) !== 4) {
    fwrite(STDERR, "no bounding box in the tile\n");
    exit(1);
}
[$la0, $lo0, $la1, $lo1] = array_map(static fn (mixed $v): float => is_numeric($v) ? (float) $v : 0.0, array_values($bbox));
$single = new SingleFile($mine);
mt_srand(3);
$n = 0;
$same = 0;
$pairs = (int) ($argv[3] ?? 300);
for ($i = 0; $i < $pairs; $i++) {
    $p = [$la0 + ($la1 - $la0) * mt_rand(0, 10000) / 10000, $lo0 + ($lo1 - $lo0) * mt_rand(0, 10000) / 10000, $la0 + ($la1 - $la0) * mt_rand(0, 10000) / 10000, $lo0 + ($lo1 - $lo0) * mt_rand(0, 10000) / 10000];
    $x = $route(...$p);
    $y = $single->route(...$p);
    if (!is_array($x) || $y === null) {
        continue;
    }
    ++$n;
    $dx = is_numeric($x['duration'] ?? null) ? (float) $x['duration'] : -1.0;
    $dd = is_numeric($x['distance'] ?? null) ? (float) $x['distance'] : -1.0;
    if (abs($dx - $y['duration']) <= 1.0 && abs($dd - $y['distance']) <= 1.0) {
        ++$same;
    }
}
printf("%s (format level %s): %d routes found by both readers, %d the same\n", $argv[2], is_scalar($meta['format_level'] ?? null) ? (string) $meta['format_level'] : '1', $n, $same);
exit($same === $n && $n > 0 ? 0 : 1);
