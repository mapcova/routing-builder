#!/usr/bin/env php
<?php

declare(strict_types=1);

use MapCova\GraphBuilder\Build\Builder;
use MapCova\GraphBuilder\Build\BuilderBusy;
use MapCova\GraphBuilder\Build\Options;

require __DIR__ . '/../vendor/autoload.php';

/**
 * Builds a `.mcr` routing graph from an OSM extract, in resumable slices: each launch works for at most `--slice`
 * seconds, saves its state in the work directory and exits with 10; launch it again to go on. Exit 0: the file is
 * written. Exit 1: an error. Exit 2: bad arguments. Exit 11: another launch is running in the same work directory
 * (nothing was done: try again later).
 */
function usage(): never
{
    fwrite(STDERR, <<<'TXT'
usage: php bin/build.php --tile <zone> --profile car|car-notoll|bike|foot --input <extract.osm.pbf> --out <file.mcr>
                         [--slice 240] [--memory 256M] [--work <dir>] [--poly <zone.poly>] [--built-at <unix time>]
                         [--dump-arcs <file>] [--keep-work] [--tiles <tiles.json>] [--junctions <junctions.bin>]
                         [--export-interior 1000]

TXT);
    exit(2);
}

/** @return int bytes */
function bytes(string $v): int
{
    if (preg_match('/^(\d+)\s*([KMG]?)B?$/i', trim($v), $m) !== 1) {
        fwrite(STDERR, "bad size: $v\n");
        exit(2);
    }
    $mult = ['' => 1, 'K' => 1 << 10, 'M' => 1 << 20, 'G' => 1 << 30][strtoupper($m[2])];

    return (int) $m[1] * $mult;
}

$opts = getopt('', ['tile:', 'profile:', 'input:', 'out:', 'slice:', 'memory:', 'work:', 'poly:', 'built-at:', 'dump-arcs:', 'keep-work', 'tiles:', 'junctions:', 'export-interior:']);
if (!is_array($opts)) {
    usage();
}
foreach (['tile', 'profile', 'input', 'out'] as $required) {
    if (!isset($opts[$required]) || !is_string($opts[$required])) {
        usage();
    }
}
$str = static fn (string $k): ?string => isset($opts[$k]) && is_string($opts[$k]) ? $opts[$k] : null;
$memory = $str('memory');
if ($memory !== null) {
    ini_set('memory_limit', (string) bytes($memory));
}
$out = (string) $str('out');
$options = new Options(
    input: (string) $str('input'),
    output: $out,
    workDir: $str('work') ?? dirname($out) . '/.' . basename($out) . '.work',
    zone: (string) $str('tile'),
    profile: (string) $str('profile'),
    slice: (float) ($str('slice') ?? 240),
    poly: $str('poly'),
    builtAt: $str('built-at') !== null ? (int) $str('built-at') : null,
    dumpArcs: $str('dump-arcs'),
    keepWork: isset($opts['keep-work']),
    memoryLimit: $memory !== null ? bytes($memory) : 0,
    tiles: $str('tiles'),
    junctions: $str('junctions'),
    exportInterior: (int) ($str('export-interior') ?? 1000),
);

try {
    $builder = new Builder($options, static function (string $m): void {
        fprintf(STDERR, "[%s] %s\n", date('H:i:s'), $m);
    });
    if ($builder->isDone()) {
        fwrite(STDERR, "already built: $out\n");
        exit(0);
    }
    $r = $builder->runSlice();
} catch (BuilderBusy $e) {
    fwrite(STDERR, 'build.php: ' . $e->getMessage() . "\n");
    exit(11);
} catch (Throwable $e) {
    fwrite(STDERR, 'build.php: ' . $e->getMessage() . "\n");
    exit(1);
}
$limit = $options->memoryLimit > 0 ? $options->memoryLimit : (int) ini_parse_quantity((string) ini_get('memory_limit'));
fprintf(STDERR, "slice: %.1fs, peak %.1f MB%s, phases %s, %s\n", $r->seconds, $r->peakBytes / 1048576, $limit > 0 ? sprintf(' (%.0f%% of the memory limit)', 100 * $r->peakBytes / $limit) : '', implode(',', $r->phases), $r->done ? 'done' : 'next phase ' . $r->phase);
if ($limit > 0 && $r->peakBytes > 0.85 * $limit) {
    fwrite(STDERR, "warning: the peak is above 85% of the memory limit: the extract is too big for this limit, cut it into smaller zones\n");
}
exit($r->done ? 0 : 10);
