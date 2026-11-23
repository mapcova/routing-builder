#!/usr/bin/env php
<?php

declare(strict_types=1);

use MapCova\GraphBuilder\Query\LinkFile;
use MapCova\GraphBuilder\Query\TileFile;
use MapCova\GraphBuilder\Tiles\TileSet;

require __DIR__ . '/../vendor/autoload.php';

/**
 * Markdown tables of what the tiles and the links of a tiling cost (what `BENCHMARKS.md` quotes): per file the vertices, the
 * exported vertices and their arcs, the number of launches (slices), the longest slice, the peak memory against the limit, and the size on disk.
 *
 *   php tools/tiles-report.php <tiles.json> <dir> [memory limit in MB, default 256]
 */
$argv = [];
foreach (is_array($_SERVER['argv'] ?? null) ? $_SERVER['argv'] : [] as $a) {
    if (is_string($a)) {
        $argv[] = $a;
    }
}
if (count($argv) < 3) {
    fwrite(STDERR, "usage: php tools/tiles-report.php <tiles.json> <dir> [memory limit in MB]\n");
    exit(2);
}
$ts = TileSet::load($argv[1]);
$dir = $argv[2];
$limit = (float) ($argv[3] ?? 256) * 1048576;

/** @return array{launches:int,longest:float,total:float,peak:float} */
function slices(string $file): array
{
    $j = json_decode((string) @file_get_contents($file . '.slices.json'), true);
    $rows = is_array($j) && is_array($j['slices'] ?? null) ? $j['slices'] : [];
    $longest = 0.0;
    $total = 0.0;
    $peak = 0.0;
    foreach ($rows as $r) {
        if (!is_array($r)) {
            continue;
        }
        $s = is_numeric($r['seconds'] ?? null) ? (float) $r['seconds'] : 0.0;
        $p = is_numeric($r['peak_bytes'] ?? null) ? (float) $r['peak_bytes'] : 0.0;
        $longest = max($longest, $s);
        $total += $s;
        $peak = max($peak, $p);
    }

    return ['launches' => count($rows), 'longest' => $longest, 'total' => $total, 'peak' => $peak];
}

echo "| Tile | Nodes | Border vertices | Exported vertices | Cross arcs | Export arcs | Launches | Longest slice | Total | Peak memory | Size |\n|---|---|---|---|---|---|---|---|---|---|---|\n";
$sumNodes = 0;
foreach ($ts->ids as $id) {
    $f = new TileFile("$dir/$id.mcr");
    $s = slices("$dir/$id.mcr");
    $b = $f->border();
    $core = $f->core();
    $sumNodes += $f->nodes;
    printf(
        "| %s | %s | %d | %d | %d | %d | %d | %.0f s | %.0f s | %.0f MB (%.0f %%) | %.1f MB |\n",
        $id,
        number_format($f->nodes),
        count(array_filter($b['flags'], static fn (int $x): bool => $x !== 4)),
        count($b['keys']),
        count(array_filter($b['cross'], static fn (array $x): bool => $x['kind'] === 0)),
        intdiv(count($core['arcs']), 5),
        $s['launches'],
        $s['longest'],
        $s['total'],
        $s['peak'] / 1048576,
        100 * $s['peak'] / $limit,
        filesize("$dir/$id.mcr") / 1048576,
    );
}
printf("\nTiles: %d, %s nodes in all.\n\n", count($ts->ids), number_format($sumNodes));
echo "| Link | Children | Vertices | Outer border | Exported vertices | Remainder | Arcs (up fwd + bwd) | Export arcs | Launches | Longest slice | Total | Peak memory | Size |\n|---|---|---|---|---|---|---|---|---|---|---|---|---|\n";
foreach ($ts->links() as $id) {
    $path = "$dir/$id.mco";
    if (!is_file($path)) {
        continue;
    }
    $f = new LinkFile($path);
    $m = $f->meta();
    $s = slices($path);
    printf(
        "| %s | %d | %d | %d | %d | %d | %s + %s | %d | %d | %.1f s | %.1f s | %.0f MB (%.0f %%) | %.1f MB |\n",
        $id,
        count($ts->childrenOf($id)),
        $f->nodes,
        is_int($m['outer_border'] ?? null) ? $m['outer_border'] : 0,
        is_int($m['exported'] ?? null) ? $m['exported'] : 0,
        is_int($m['remainder'] ?? null) ? $m['remainder'] : 0,
        number_format($f->upFwd),
        number_format($f->upBwd),
        is_int($m['core_arcs'] ?? null) ? $m['core_arcs'] : 0,
        $s['launches'],
        $s['longest'],
        $s['total'],
        $s['peak'] / 1048576,
        100 * $s['peak'] / $limit,
        filesize($path) / 1048576,
    );
}

$longest = 0.0;
$peak = 0.0;
$launches = 0;
$files = 0;
foreach (array_merge($ts->ids, $ts->links()) as $id) {
    $path = $ts->isTile($id) ? "$dir/$id.mcr" : "$dir/$id.mco";
    if (!is_file($path)) {
        continue;
    }
    $s = slices($path);
    $longest = max($longest, $s['longest']);
    $peak = max($peak, $s['peak']);
    $launches += $s['launches'];
    ++$files;
}
printf("\nAll %d files: %d launches, longest slice %.0f s, highest peak %.0f MB (%.0f %% of %.0f MB).\n", $files, $launches, $longest, $peak / 1048576, 100 * $peak / $limit, $limit / 1048576);
