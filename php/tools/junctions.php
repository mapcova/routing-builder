#!/usr/bin/env php
<?php

declare(strict_types=1);

use MapCova\GraphBuilder\Osm\WaysPass;
use MapCova\GraphBuilder\Pbf\Block;
use MapCova\GraphBuilder\Pbf\PbfReader;
use MapCova\GraphBuilder\Profile\Profile;
use MapCova\GraphBuilder\Support\Bin;

require __DIR__ . '/../vendor/autoload.php';

/**
 * The nodes that are junctions in a whole extract: `php tools/junctions.php <extract.osm.pbf> <out.bin> [profile]`.
 *
 * A node is a junction when the ways the profile keeps pass through it, or end at it, at least twice (a ring counts twice).
 * The builder cuts the roads into segments at the junctions; a tile built from a partial extract lacks the roads that meet
 * far from the tile, so it is given this list (`--junctions`) and cuts the roads where the whole network does. The file is
 * the sorted node ids, u64 little-endian each. The tool keeps every reference in memory (about 100 bytes per way node), so it
 * is for the data set of a country, run once per extract.
 */
$argv = [];
foreach (is_array($_SERVER['argv'] ?? null) ? $_SERVER['argv'] : [] as $a) {
    if (is_string($a)) {
        $argv[] = $a;
    }
}
if (!isset($argv[1], $argv[2])) {
    fwrite(STDERR, "usage: php tools/junctions.php <extract.osm.pbf> <out.bin> [car|car-notoll|bike|foot]\n");
    exit(2);
}
ini_set('memory_limit', '6G');
$profile = Profile::parse($argv[3] ?? 'car');
if ($profile === null) {
    fwrite(STDERR, "unknown profile\n");
    exit(2);
}
$pass = new WaysPass($profile);
$refs = [];
$ways = 0;
foreach (PbfReader::blobs($argv[1], 0) as $blob) {
    if ($blob['type'] !== 'OSMData') {
        continue;
    }
    $r = $pass->process(Block::decode($blob['data']));
    $raw = $r['ways'];
    for ($o = 0, $e = strlen($raw); $o < $e;) {
        $n = Bin::u32($raw, $o + 8);
        $o += WaysPass::WAY_HEADER;
        foreach ($n > 0 ? Bin::list('P' . $n, $raw, $o) : [] as $id) {
            $refs[] = $id;
        }
        $o += $n * 8;
        ++$ways;
    }
}
sort($refs);
$out = '';
$prev = -1;
$count = 0;
$dup = false;
foreach ($refs as $id) {
    if ($id === $prev) {
        if (!$dup) {
            $out .= pack('P', $id);
            ++$count;
            $dup = true;
        }
    } else {
        $dup = false;
    }
    $prev = $id;
}
file_put_contents($argv[2] . '.part', $out);
rename($argv[2] . '.part', $argv[2]);
fprintf(STDERR, "%d ways, %d node references, %d junctions -> %s (peak %.0f MB)\n", $ways, count($refs), $count, $argv[2], memory_get_peak_usage(true) / 1048576);
