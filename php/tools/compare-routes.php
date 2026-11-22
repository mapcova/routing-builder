<?php

declare(strict_types=1);

/**
 * Routes the same random pairs of points on two `.mcr` files (the Rust builder's and the PHP builder's) with the
 * reader of the MapCova `local-routing` extension, and compares weight, duration and distance.
 *
 * Usage: php tools/compare-routes.php <rust.mcr> <php.mcr> --reader <path/to/McrGraph.php> [--pairs 1000] [--seed 1]
 *
 * The weight is what the shortest path minimises: it must be identical for every pair. The duration may differ
 * between paths of the same weight (tie-breaking between equal-weight paths): every pair more than 1 s apart is listed.
 */

function fail(string $msg): never
{
    fwrite(STDERR, $msg . "\n");
    exit(2);
}

function float_of(mixed $v): float
{
    if (!is_float($v) && !is_int($v)) {
        fail('unexpected value from the reader');
    }

    return (float) $v;
}

function int_of(mixed $v): int
{
    if (!is_int($v)) {
        fail('unexpected value from the reader');
    }

    return $v;
}

/** @return array<mixed> */
function arr_of(mixed $v): array
{
    if (!is_array($v)) {
        fail('unexpected value from the reader');
    }

    return $v;
}

/**
 * The cheapest route of `route()` of the reader, with its weight (which `route()` does not return).
 *
 * @return array{w:int,t:int,d:int}|null weight, duration in 1/10 s, distance in metres
 */
function route_cost(object $g, float $la1, float $lo1, float $la2, float $lo2): ?array
{
    $call = static function (string $name, mixed ...$args) use ($g): mixed {
        return (new ReflectionMethod($g, $name))->invoke($g, ...$args);
    };
    $a = $call('snap', $la1, $lo1, 200.0);
    $b = $call('snap', $la2, $lo2, 200.0);
    if ($a === null || $b === null) {
        return null;
    }
    $init = arr_of($call('sourceLabels', $a));
    $targets = arr_of($call('targetLabels', $b));
    $direct = $call('sameSegment', $a, $b);
    $minInit = 0;
    foreach ($init as $l) {
        $minInit = min($minInit, int_of(arr_of($l)[0]));
    }
    $best = PHP_INT_MAX;
    $meet = -1;
    $fw = ['w' => [], 't' => [], 'd' => []];
    $bw = $fw;
    if ($init !== [] && $targets !== []) {
        $fw = arr_of($call('up', $init, true));
        $bw = arr_of($call('up', $targets, false, $fw, $minInit));
        foreach (arr_of($bw['w']) as $v => $wb) {
            $fwW = arr_of($fw['w']);
            if (isset($fwW[$v])) {
                $tot = int_of($fwW[$v]) + int_of($wb);
                if ($tot >= 0 && $tot < $best) {
                    $best = $tot;
                    $meet = int_of($v);
                }
            }
        }
    }
    if ($direct !== null) {
        $dd = arr_of($direct);
        if ($meet < 0 || int_of($dd['w']) <= $best) {
            return ['w' => int_of($dd['w']), 't' => int_of($dd['t']), 'd' => int_of($dd['d'])];
        }
    }
    if ($meet < 0) {
        return null;
    }

    return [
        'w' => $best,
        't' => int_of(arr_of($fw['t'])[$meet]) + int_of(arr_of($bw['t'])[$meet]),
        'd' => int_of(arr_of($fw['d'])[$meet]) + int_of(arr_of($bw['d'])[$meet]),
    ];
}

$pos = [];
$opts = [];
$argvList = [];
$rawArgs = $_SERVER['argv'] ?? [];
if (is_array($rawArgs)) {
    foreach ($rawArgs as $a) {
        if (is_string($a)) {
            $argvList[] = $a;
        }
    }
}
for ($i = 1, $n = count($argvList); $i < $n; $i++) {
    if (str_starts_with($argvList[$i], '--') && isset($argvList[$i + 1])) {
        $opts[substr($argvList[$i], 2)] = $argvList[++$i];
    } else {
        $pos[] = $argvList[$i];
    }
}
$reader = $opts['reader'] ?? null;
if (count($pos) !== 2 || $reader === null) {
    fail('usage: php tools/compare-routes.php <rust.mcr> <php.mcr> --reader <path/to/McrGraph.php> [--pairs 1000] [--seed 1]');
}
$pairs = (int) ($opts['pairs'] ?? 1000);
$seed = (int) ($opts['seed'] ?? 1);
require $reader;
$class = 'MapCovaPlus\\LocalRouting\\Graph\\McrGraph';
if (!class_exists($class)) {
    fail("$class not found in $reader");
}
$ga = new $class($pos[0]);
$gb = new $class($pos[1]);
if (!is_object($ga) || !is_object($gb)) {
    fail('cannot open the graphs');
}
$rnd = new Random\Randomizer(new Random\Engine\Mt19937($seed));
$sameWeight = 0;
$within1s = 0;
$distOk = 0;
$bothFound = 0;
$bothMissing = 0;
$listed = [];
$distDiff = [];
$t0 = microtime(true);
for ($i = 0; $i < $pairs; $i++) {
    $p = arr_of((new ReflectionMethod($ga, 'randomPoint'))->invoke($ga, $rnd));
    $q = arr_of((new ReflectionMethod($ga, 'randomPoint'))->invoke($ga, $rnd));
    $la1 = float_of($p[0] ?? null);
    $lo1 = float_of($p[1] ?? null);
    $la2 = float_of($q[0] ?? null);
    $lo2 = float_of($q[1] ?? null);
    $ra = route_cost($ga, $la1, $lo1, $la2, $lo2);
    $rb = route_cost($gb, $la1, $lo1, $la2, $lo2);
    foreach ([$ga, $gb] as $g) {
        (new ReflectionMethod($g, 'clearCaches'))->invoke($g); // the reader caches what it reads
    }
    if ($ra === null && $rb === null) {
        $bothMissing++;
        $sameWeight++;
        $within1s++;
        $distOk++;
        continue;
    }
    if ($ra === null || $rb === null) {
        $listed[] = sprintf('pair %d (%.6f,%.6f -> %.6f,%.6f): route found by %s only', $i, $la1, $lo1, $la2, $lo2, $ra === null ? 'PHP' : 'Rust');
        continue;
    }
    $bothFound++;
    if ($ra['w'] === $rb['w']) {
        $sameWeight++;
    } else {
        $listed[] = sprintf('pair %d (%.6f,%.6f -> %.6f,%.6f): WEIGHT rust %d php %d', $i, $la1, $lo1, $la2, $lo2, $ra['w'], $rb['w']);
    }
    if (abs($ra['t'] - $rb['t']) <= 10) {
        $within1s++;
        if (abs($ra['d'] - $rb['d']) <= 1) {
            $distOk++;
        } else {
            $distDiff[] = sprintf('pair %d (%.6f,%.6f -> %.6f,%.6f): distance rust %d m php %d m (same weight %d)', $i, $la1, $lo1, $la2, $lo2, $ra['d'], $rb['d'], $ra['w']);
        }
    } else {
        $listed[] = sprintf('pair %d (%.6f,%.6f -> %.6f,%.6f): duration rust %.1f s php %.1f s (weight %d vs %d)', $i, $la1, $lo1, $la2, $lo2, $ra['t'] / 10, $rb['t'] / 10, $ra['w'], $rb['w']);
    }
}
printf("pairs %d (both found %d, no route in both %d), %.1fs\n", $pairs, $bothFound, $bothMissing, microtime(true) - $t0);
printf("weight identical:        %d / %d (%.2f%%)\n", $sameWeight, $pairs, 100 * $sameWeight / max($pairs, 1));
printf("duration within 1 s:     %d / %d (%.2f%%)\n", $within1s, $pairs, 100 * $within1s / max($pairs, 1));
printf("distance within 1 m too: %d / %d\n", $distOk, $within1s);
foreach ($listed as $l) {
    echo $l, "\n";
}
if ($distDiff !== []) {
    echo "pairs within 1 s whose distance differs by more than 1 m (equal weight, another equally cheap path):\n";
    foreach ($distDiff as $l) {
        echo $l, "\n";
    }
}
exit($sameWeight === $pairs && $within1s * 100 >= $pairs * 99 ? 0 : 1);
