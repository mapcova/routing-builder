#!/usr/bin/env php
<?php

declare(strict_types=1);

use MapCova\GraphBuilder\Link\TransitBuilder;

require __DIR__ . '/../vendor/autoload.php';

/** Writes the transit file of a tile: `php bin/transit.php <tile.mcr> <tile.mct> [tolerance in metres]`. */
$argv = [];
foreach (is_array($_SERVER['argv'] ?? null) ? $_SERVER['argv'] : [] as $a) {
    if (is_string($a)) {
        $argv[] = $a;
    }
}
if (count($argv) < 3) {
    fwrite(STDERR, "usage: php bin/transit.php <tile.mcr> <tile.mct> [tolerance, default 0.5]\n");
    exit(2);
}
try {
    $r = TransitBuilder::build($argv[1], $argv[2], isset($argv[3]) ? (float) $argv[3] : 0.5);
} catch (Throwable $e) {
    fwrite(STDERR, 'transit.php: ' . $e->getMessage() . "\n");
    exit(1);
}
fprintf(STDERR, "%s: %d vertices, %d core arcs, %d cross arcs, %d points, %.1f MB, %.1fs, peak %.0f MB\n", $argv[2], $r['vertices'], $r['core_arcs'], $r['cross_arcs'], $r['points'], $r['bytes'] / 1048576, $r['seconds'], memory_get_peak_usage(true) / 1048576);
