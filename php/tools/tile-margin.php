#!/usr/bin/env php
<?php

declare(strict_types=1);

use MapCova\GraphBuilder\Tiles\TileSet;

require __DIR__ . '/../vendor/autoload.php';

/**
 * Writes the Osmosis `.poly` of a tile grown by a margin, the region its extract is cut with (`osmium extract -p`):
 * `php tools/tile-margin.php <tiles.json> <tile> <margin metres> <out.poly>`.
 *
 * The margin follows the raster of the tiling (the cells of the tile, widened), so the polygon is made of straight runs of
 * cells; a hole is written `!name`.
 */
$argv = [];
foreach (is_array($_SERVER['argv'] ?? null) ? $_SERVER['argv'] : [] as $a) {
    if (is_string($a)) {
        $argv[] = $a;
    }
}
if (count($argv) < 5) {
    fwrite(STDERR, "usage: php tools/tile-margin.php <tiles.json> <tile> <margin metres> <out.poly>\n");
    exit(2);
}
$ts = TileSet::load($argv[1]);
$tile = $ts->index($argv[2]);
$metres = (float) $argv[3];
$lat = 50.0;
$latCells = (int) ceil($metres / (0.002 * 111195));
$lonCells = (int) ceil($metres / (0.002 * 111195 * cos(deg2rad($lat))));
$out = $argv[2] . "\n";
foreach ($ts->margin($tile, $latCells, $lonCells) as $i => $ring) {
    $area = 0.0;
    $n = count($ring);
    for ($k = 0; $k < $n; $k++) {
        [$a, $b] = [$ring[$k], $ring[($k + 1) % $n]];
        $area += $a[1] * $b[0] - $b[1] * $a[0];
    }
    $out .= ($area < 0 ? '!' : '') . ($i + 1) . "\n";
    foreach ($ring as [$la, $lo]) {
        $out .= sprintf("   %.6f %.6f\n", $lo, $la);
    }
    $out .= "END\n";
}
$out .= "END\n";
file_put_contents($argv[4], $out);
fprintf(STDERR, "%s: %d rings, margin %d x %d cells\n", $argv[4], substr_count($out, "END\n") - 1, $latCells, $lonCells);
