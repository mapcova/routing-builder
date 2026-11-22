<?php

declare(strict_types=1);

use MapCova\GraphBuilder\Support\Bin;

require __DIR__ . '/../vendor/autoload.php';

/**
 * Compares two `.mcr` files built from the same extract (the Rust builder's and the PHP builder's): what must be
 * identical is identical. Usage: php tools/compare-mcr.php <rust.mcr> <php.mcr> [--strict]
 *
 * Checked: the header (except the builder name, the build time and what depends on the hierarchy), the sections
 * `segments` (graph node ids masked), `geometry`, `grid_dir`, `grid_lists`, `shadows` (renumbered to directed
 * segments) and `meta` (without `builder` and `built_at`). With `--strict` the hierarchy (`nodes`, `up_fwd`, `up_bwd`)
 * must be equal too, which holds between two builds by the same builder.
 */
const SECTIONS = ['nodes', 'up_fwd', 'up_bwd', 'segments', 'geometry', 'grid_dir', 'grid_lists', 'shadows', 'meta'];

/** @return array{head:string,sec:array<string,array{int,int}>} */
function open_mcr(string $path): array
{
    $f = fopen($path, 'rb');
    if ($f === false) {
        fwrite(STDERR, "cannot open $path\n");
        exit(2);
    }
    $head = (string) fread($f, 4096);
    fclose($f);
    $sec = [];
    foreach (SECTIONS as $i => $name) {
        $u = Bin::ints('Poff/Plen', $head, 128 + $i * 16);
        $sec[$name] = [$u['off'], $u['len']];
    }

    return ['head' => $head, 'sec' => $sec];
}

/** @param array{head:string,sec:array<string,array{int,int}>} $info */
function section(string $path, array $info, string $name): string
{
    $f = fopen($path, 'rb');
    if ($f === false) {
        exit(2);
    }
    [$off, $len] = $info['sec'][$name];
    fseek($f, $off);
    $data = $len > 0 ? (string) fread($f, $len) : '';
    fclose($f);

    return $data;
}

/**
 * The shadow pairs (original, copy) renumbered to the directed segments the two nodes stand for.
 *
 * @param array{head:string,sec:array<string,array{int,int}>} $info
 * @return list<string>
 */
function shadow_pairs(string $path, array $info): array
{
    $nodes = Bin::allU32(section($path, $info, 'nodes'));
    $dirs = [];
    for ($i = 2, $n = count($nodes); $i < $n; $i += 3) {
        $dirs[] = $nodes[$i];
    }
    $sh = Bin::allU32(section($path, $info, 'shadows'));
    $out = [];
    for ($i = 0, $n = count($sh); $i + 1 < $n; $i += 2) {
        $out[] = $dirs[$sh[$i]] . ':' . $dirs[$sh[$i + 1]];
    }
    sort($out);

    return $out;
}

$argvList = [];
$raw = $_SERVER['argv'] ?? [];
if (is_array($raw)) {
    foreach ($raw as $a) {
        if (is_string($a)) {
            $argvList[] = $a;
        }
    }
}
$args = array_values(array_filter(array_slice($argvList, 1), static fn (string $a): bool => $a !== '--strict'));
$strict = in_array('--strict', $argvList, true);
if (count($args) !== 2) {
    fwrite(STDERR, "usage: php tools/compare-mcr.php <rust.mcr> <php.mcr> [--strict]\n");
    exit(2);
}
[$pa, $pb] = $args;
$a = open_mcr($pa);
$b = open_mcr($pb);
$fail = 0;
$check = static function (string $what, bool $ok, string $detail = '') use (&$fail): void {
    printf("%-34s %s%s\n", $what, $ok ? 'identical' : 'DIFFERENT', $detail !== '' ? " ($detail)" : '');
    if (!$ok) {
        $fail++;
    }
};

// header: magic, version, profile, graph type, node count, segments, cells, bbox, cell, OSM time, zone, licence
$headerOk = true;
foreach ([[0, 20], [28, 60], [96, 128], [512, 1536]] as [$from, $to]) {
    $headerOk = $headerOk && substr($a['head'], $from, $to - $from) === substr($b['head'], $from, $to - $from);
}
foreach (['nodes', 'segments', 'geometry', 'grid_dir', 'grid_lists', 'shadows'] as $name) {
    $headerOk = $headerOk && $a['sec'][$name][1] === $b['sec'][$name][1];
}
$check('header', $headerOk);

$sa = section($pa, $a, 'segments');
$sb = section($pb, $b, 'segments');
$mask = static function (string $s): string {
    $out = '';
    for ($o = 0, $e = strlen($s); $o + 40 <= $e; $o += 40) {
        $out .= substr($s, $o, 24) . "\0\0\0\0\0\0\0\0" . substr($s, $o + 32, 8);
    }

    return $out;
};
$check('segments (without node ids)', $mask($sa) === $mask($sb), (string) intdiv(strlen($sa), 40) . ' segments');
foreach (['geometry', 'grid_dir', 'grid_lists'] as $name) {
    $x = section($pa, $a, $name);
    $y = section($pb, $b, $name);
    $check($name, $x === $y, strlen($x) . ' bytes');
}

$pa2 = shadow_pairs($pa, $a);
$pb2 = shadow_pairs($pb, $b);
$check('shadows (renumbered)', $pa2 === $pb2, count($pa2) . ' pairs');

$ma = json_decode(section($pa, $a, 'meta'), true);
$mb = json_decode(section($pb, $b, 'meta'), true);
if (is_array($ma) && is_array($mb)) {
    unset($ma['builder'], $ma['built_at'], $mb['builder'], $mb['built_at']);
    $check('meta (without builder, built_at)', $ma === $mb);
} else {
    $check('meta', false, 'not JSON');
}

if ($strict) {
    // the hierarchy too: same builder, same extract, so even the contraction order must be the same
    foreach (['nodes', 'up_fwd', 'up_bwd'] as $name) {
        $check($name . ' (strict)', section($pa, $a, $name) === section($pb, $b, $name));
    }
}

exit($fail === 0 ? 0 : 1);
