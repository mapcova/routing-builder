#!/usr/bin/env php
<?php

declare(strict_types=1);

use MapCova\GraphBuilder\Build\BuilderBusy;
use MapCova\GraphBuilder\Link\LinkBuilder;
use MapCova\GraphBuilder\Link\LinkOptions;
use MapCova\GraphBuilder\Tiles\TileSet;

require __DIR__ . '/../vendor/autoload.php';

/**
 * Builds the link `.mco` of a node of the tiling tree over the files of its children, in resumable slices (exit codes as
 * `build.php`: 0 done, 10 more to do, 11 busy, 1 error, 2 bad arguments).
 */
$opts = getopt('', ['tiles:', 'link:', 'dir:', 'out:', 'slice:', 'memory:', 'work:', 'remainder:', 'export-interior:', 'built-at:', 'profile:']);
foreach (['tiles', 'link', 'dir'] as $required) {
    if (!is_array($opts) || !isset($opts[$required]) || !is_string($opts[$required])) {
        fwrite(STDERR, "usage: php bin/build-link.php --tiles <tiles.json> --link <id> --dir <files> [--out <file.mco>] [--slice 240] [--memory 256M] [--work <dir>] [--export-interior 1000] [--remainder 300] [--profile car] [--built-at <unix time>]\n");
        exit(2);
    }
}
$str = static fn (string $k): ?string => isset($opts[$k]) && is_string($opts[$k]) ? $opts[$k] : null;
$memory = 0;
if ($str('memory') !== null && preg_match('/^(\d+)\s*([KMG]?)B?$/i', (string) $str('memory'), $m) === 1) {
    $memory = (int) $m[1] * ['' => 1, 'K' => 1 << 10, 'M' => 1 << 20, 'G' => 1 << 30][strtoupper($m[2])];
    ini_set('memory_limit', (string) $memory);
}
try {
    $tiles = TileSet::load((string) $str('tiles'));
    $link = (string) $str('link');
    $out = $str('out') ?? (string) $str('dir') . "/$link.mco";
    $builder = new LinkBuilder(new LinkOptions(
        tiles: $tiles,
        link: $link,
        dir: (string) $str('dir'),
        output: $out,
        workDir: $str('work') ?? dirname($out) . '/.' . basename($out) . '.work',
        slice: (float) ($str('slice') ?? 240),
        remainder: (int) ($str('remainder') ?? 300),
        exportInterior: (int) ($str('export-interior') ?? 1000),
        builtAt: $str('built-at') !== null ? (int) $str('built-at') : null,
        memoryLimit: $memory,
        profile: $str('profile') ?? 'car',
    ), static function (string $m): void {
        fprintf(STDERR, "[%s] %s\n", date('H:i:s'), $m);
    });
    if ($builder->isDone()) {
        fwrite(STDERR, "already built: $out\n");
        exit(0);
    }
    $r = $builder->runSlice();
} catch (BuilderBusy $e) {
    fwrite(STDERR, 'build-link.php: ' . $e->getMessage() . "\n");
    exit(11);
} catch (Throwable $e) {
    fwrite(STDERR, 'build-link.php: ' . $e->getMessage() . "\n");
    exit(1);
}
fprintf(STDERR, "slice: %.1fs, peak %.1f MB%s, phases %s, %s\n", $r->seconds, $r->peakBytes / 1048576, $memory > 0 ? sprintf(' (%.0f%% of the memory limit)', 100 * $r->peakBytes / $memory) : '', implode(',', $r->phases), $r->done ? 'done' : 'next phase ' . $r->phase);
exit($r->done ? 0 : 10);
