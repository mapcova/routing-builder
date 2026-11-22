<?php

declare(strict_types=1);

namespace MapCova\GraphBuilder\Profile;

use MapCova\GraphBuilder\Support\F32;
use MapCova\GraphBuilder\Support\Text;

/**
 * Car profile, from OSRM `car.lua`. Weight = duration / rate, where the rate carries the service-road, width,
 * side-road and alternating-oneway penalties. `noToll` drops the ways tagged as toll roads (`car-notoll`).
 */
final class Car extends Profile
{
    public const TURN_PENALTY = 7.5;
    public const TURN_BIAS = 1.075;
    public const TRAFFIC_LIGHT_PENALTY = 2.0;
    public const U_TURN_PENALTY = 20.0;
    public const GATE_PENALTY = 60.0;
    /** Weight added when a route enters a local-access-only road from the open network (seconds). */
    public const RESTRICTED_ENTRY_WEIGHT = 100000.0;

    private const WHITELIST = ['yes', 'motorcar', 'motor_vehicle', 'vehicle', 'permissive', 'designated', 'hov'];
    private const BLACKLIST = [
        'no', 'agricultural', 'forestry', 'emergency', 'psv', 'taxi', 'share_taxi', 'minibus', 'bus', 'foot',
        'emergency_vehicle', 'restricted', 'military', 'official', 'customers', 'private', 'delivery', 'destination',
        'permit', 'residents',
    ];
    private const RESTRICTED = ['private', 'delivery', 'destination', 'customers', 'permit', 'residents', 'unknown'];
    private const HIERARCHY = ['motorcar', 'motor_vehicle', 'vehicle', 'access'];
    private const RESTRICTED_HIGHWAYS = [
        'motorway', 'motorway_link', 'trunk', 'trunk_link', 'primary', 'primary_link', 'secondary', 'secondary_link',
        'tertiary', 'tertiary_link', 'residential', 'living_street', 'unclassified', 'service', 'winter_road',
        'ice_road',
    ];
    private const BARRIER_WHITELIST = [
        'cattle_grid', 'border_control', 'toll_booth', 'sally_port', 'no', 'entrance', 'height_restrictor', 'arch',
    ];

    public function __construct(private readonly bool $noToll)
    {
    }

    public function name(): string
    {
        return $this->noToll ? 'car-notoll' : 'car';
    }

    public function id(): int
    {
        return $this->noToll ? 3 : 0;
    }

    public function edgeBased(): bool
    {
        return true;
    }

    public function exempt(string $except): bool
    {
        foreach (explode(';', $except) as $x) {
            $x = Text::trim($x);
            if ($x === 'motorcar' || $x === 'motor_vehicle') {
                return true;
            }
        }

        return false;
    }

    private static function highwaySpeed(string $h): ?float
    {
        return match ($h) {
            'motorway' => 90.0,
            'motorway_link' => 45.0,
            'trunk' => 85.0,
            'trunk_link' => 40.0,
            'primary' => 65.0,
            'primary_link' => 30.0,
            'secondary' => 55.0,
            'secondary_link' => 25.0,
            'tertiary' => 40.0,
            'tertiary_link' => 20.0,
            'unclassified' => 25.0,
            'residential' => 25.0,
            'living_street' => 10.0,
            'service' => 15.0,
            'winter_road' => 20.0,
            'ice_road' => 15.0,
            default => null,
        };
    }

    private static function surfaceCap(string $s): ?float
    {
        return match ($s) {
            'cement', 'compacted', 'fine_gravel' => 80.0,
            'paving_stones', 'metal', 'bricks' => 60.0,
            'grass', 'wood', 'sett', 'grass_paver', 'gravel', 'unpaved', 'ground', 'dirt', 'pebblestone',
            'tartan' => 40.0,
            'cobblestone', 'clay' => 30.0,
            'earth', 'stone', 'rocky', 'sand' => 20.0,
            'laterite' => 15.0,
            'mud' => 10.0,
            'ice' => 20.0,
            'snow' => 30.0,
            default => null,
        };
    }

    private static function tracktypeCap(string $s): ?float
    {
        return match ($s) {
            'grade1' => 60.0,
            'grade2' => 40.0,
            'grade3' => 30.0,
            'grade4' => 25.0,
            'grade5' => 20.0,
            default => null,
        };
    }

    private static function smoothnessCap(string $s): ?float
    {
        return match ($s) {
            'intermediate' => 80.0,
            'bad' => 40.0,
            'very_bad' => 20.0,
            'horrible' => 10.0,
            'very_horrible' => 5.0,
            'impassable' => 0.0,
            default => null,
        };
    }

    private static function servicePenalty(string $s): ?float
    {
        return \in_array($s, ['alley', 'parking', 'parking_aisle', 'driveway', 'drive-through', 'drive-thru'], true)
            ? 0.5 : null;
    }

    /**
     * `toll=yes` or `toll:motorcar=yes`/`toll:motor_vehicle=yes`, or a toll booth way. Swiss motorways are paid by
     * vignette and carry no toll tag: they are not "toll" here.
     *
     * @param array<string,string> $t
     */
    public static function isToll(array $t): bool
    {
        if (($t['toll'] ?? null) === 'yes' || ($t['toll:motorcar'] ?? null) === 'yes'
            || ($t['toll:motor_vehicle'] ?? null) === 'yes') {
            return true;
        }

        return ($t['barrier'] ?? null) === 'toll_booth';
    }

    private static function leadingNumber(string $s): ?float
    {
        $n = '';
        $s = Text::trim($s);
        $len = \strlen($s);
        for ($i = 0; $i < $len && (($s[$i] >= '0' && $s[$i] <= '9') || $s[$i] === '.'); $i++) {
            $n .= $s[$i];
        }

        return Text::parseF32($n);
    }

    /**
     * The speed limit of one direction: `maxspeed:advisory`, `maxspeed`, `source:maxspeed`, `maxspeed:type`.
     *
     * @param array<string,string> $t
     */
    private static function pick(array $t, string $dir): float
    {
        foreach (['maxspeed:advisory', 'maxspeed', 'source:maxspeed', 'maxspeed:type'] as $k) {
            foreach ([$k . ':' . $dir, $k] as $key) {
                if (isset($t[$key])) {
                    $n = self::parseMaxspeed($t[$key]);
                    if ($n > 0.0) {
                        return $n;
                    }
                }
            }
        }

        return 0.0;
    }

    public function way(array $t): ?Way
    {
        $highway = self::nonEmpty($t['highway'] ?? null);
        $route = self::nonEmpty($t['route'] ?? null);
        if ($highway === null && $route === null) {
            return null;
        }
        // blocked ways
        if (($t['area'] ?? null) === 'yes' || ($this->noToll && self::isToll($t)) || ($t['oneway'] ?? null) === 'reversible') {
            return null;
        }
        if (($t['impassable'] ?? null) === 'yes' || ($t['status'] ?? null) === 'impassable') {
            return null;
        }
        if (isset($t['construction']) && !\in_array($t['construction'], ['no', 'widening', 'minor'], true)) {
            return null;
        }
        if (isset($t['proposed']) && ($highway === null || $highway === 'proposed')) {
            return null;
        }
        if (($t['service'] ?? null) === 'emergency_access') {
            return null;
        }
        // access
        $access = self::findAccess($t, self::HIERARCHY, self::WHITELIST, self::RESTRICTED);
        $restricted = $highway !== null && \in_array($highway, self::RESTRICTED_HIGHWAYS, true)
            && $access !== null && \in_array($access, self::RESTRICTED, true);
        if ($access !== null && \in_array($access, self::BLACKLIST, true) && !$restricted) {
            return null;
        }
        // oneway
        $oneway = self::first($t, ['oneway:motorcar', 'oneway:motor_vehicle', 'oneway:vehicle', 'oneway']);
        $fwdOk = true;
        $bwdOk = true;
        if ($oneway === '-1') {
            $fwdOk = false;
        } elseif ($oneway === 'yes' || $oneway === '1' || $oneway === 'true') {
            $bwdOk = false;
        } else {
            $junction = $t['junction'] ?? null;
            if (($highway === 'motorway' || $junction === 'roundabout' || $junction === 'circular') && $oneway !== 'no') {
                $bwdOk = false;
            }
        }
        // ferries, shuttle trains and movable bridges
        $speed = null;
        $duration = null;
        $startpoint = true;
        $rs = match ($route) {
            'ferry' => 5.0,
            'shuttle_train' => 10.0,
            default => null,
        };
        if ($rs !== null) {
            $duration = isset($t['duration']) ? self::parseDuration($t['duration']) : null;
            $speed = $rs;
            $startpoint = false;
        }
        if (($t['bridge'] ?? null) === 'movable') {
            $startpoint = true;
            $d = isset($t['duration']) ? self::parseDuration($t['duration']) : null;
            if ($d !== null) {
                $duration = $d;
            }
            $speed = 5.0;
        }
        // speed by way type
        if ($speed === null) {
            $speed = $highway !== null ? self::highwaySpeed($highway) : null;
            if ($speed === null) {
                if ($access !== null && (\in_array($access, self::WHITELIST, true) || !\in_array($access, self::BLACKLIST, true))) {
                    $speed = 10.0;
                } else {
                    return null;
                }
            }
        }
        $sf = $speed;
        $sb = $speed;
        // maxspeed
        if ($duration === null) {
            $mf = self::pick($t, 'forward');
            $mb = self::pick($t, 'backward');
            if ($mf > 0.0) {
                $sf = F32::r($mf * F32::r(0.8));
            }
            if ($mb > 0.0) {
                $sb = F32::r($mb * F32::r(0.8));
            }
            // surface
            $cap = F32::MAX;
            foreach ([
                isset($t['surface']) ? self::surfaceCap($t['surface']) : null,
                isset($t['tracktype']) ? self::tracktypeCap($t['tracktype']) : null,
                isset($t['smoothness']) ? self::smoothnessCap($t['smoothness']) : null,
            ] as $c) {
                if ($c !== null) {
                    $cap = min($cap, $c);
                }
            }
            $sf = min($sf, $cap);
            $sb = min($sb, $cap);
        }
        if ($sf <= 0.0 && $sb <= 0.0 && $duration === null) {
            return null;
        }
        // penalties
        $both = $fwdOk && $bwdOk;
        $common = 1.0;
        $p = isset($t['service']) ? self::servicePenalty($t['service']) : null;
        if ($p !== null) {
            $common = min($common, $p);
        }
        if ($both) {
            if (($t['lane_markings'] ?? null) === 'no') {
                $common = min($common, 0.75);
            }
            $w = isset($t['width']) ? self::leadingNumber($t['width']) : null;
            if ($w !== null) {
                $pw = match (true) {
                    $w <= 3.0 => 0.5,
                    $w < 4.0 => F32::r(0.6),
                    $w < 5.0 => 0.75,
                    $w < 6.0 => F32::r(0.8),
                    default => 1.0,
                };
                $common = min($common, $pw);
            }
            $l = isset($t['lanes']) ? self::leadingNumber($t['lanes']) : null;
            if ($l !== null && $l <= 1.0) {
                $common = min($common, 0.5);
            }
        }
        if (($t['oneway'] ?? null) === 'alternating') {
            $common = min($common, F32::r(0.4));
        }
        $side = $t['side_road'] ?? null;
        if ($side === 'yes' || $side === 'rotary') {
            $common = min($common, F32::r(0.8));
        }
        $rf = $common;
        $rb = $common;
        $priority = $t['priority'] ?? null;
        if ($priority === 'forward') {
            $rb = min($rb, F32::r(0.7));
        } elseif ($priority === 'backward') {
            $rf = min($rf, F32::r(0.7));
        }
        if ($highway === 'service' && $access === 'private') {
            $startpoint = false;
        }
        $min = F32::r(0.1);
        $dir = static fn (bool $ok, float $speed): float => $ok && ($speed > 0.0 || $duration !== null)
            ? max($speed, $min) : 0.0;

        return new Way($dir($fwdOk, $sf), $rf, $dir($bwdOk, $sb), $rb, $restricted, $duration, $startpoint, self::isToll($t));
    }

    public function nodeFlags(array $tags): int
    {
        $f = 0;
        $barrier = $bollard = $kerb = $highway = $sensory = null;
        $acc = [null, null, null, null];
        foreach ($tags as [$k, $v]) {
            switch ($k) {
                case 'highway':
                    $highway = $v;
                    break;
                case 'barrier':
                    $barrier = $v;
                    break;
                case 'bollard':
                    $bollard = $v;
                    break;
                case 'kerb':
                    $kerb = $v;
                    break;
                case 'sensory':
                    $sensory = $v;
                    break;
                case 'motorcar':
                    $acc[0] = $v;
                    break;
                case 'motor_vehicle':
                    $acc[1] = $v;
                    break;
                case 'vehicle':
                    $acc[2] = $v;
                    break;
                case 'access':
                    $acc[3] = $v;
                    break;
            }
        }
        if ($highway === 'traffic_signals') {
            $f |= self::NODE_SIGNALS;
        }
        $a = $acc[0] ?? $acc[1] ?? $acc[2] ?? $acc[3];
        if ($a !== null) {
            if (\in_array($a, self::BLACKLIST, true) && !\in_array($a, self::RESTRICTED, true)) {
                $f |= self::NODE_BARRIER;
            }
        } elseif ($barrier !== null) {
            if ($barrier === 'gate' || $barrier === 'lift_gate') {
                $f |= self::NODE_GATE;
            } elseif (!\in_array($barrier, self::BARRIER_WHITELIST, true)
                && $bollard !== 'rising'
                && !($kerb === 'lowered' || $kerb === 'flush')
                && !($barrier === 'kerb' && $highway === 'crossing')
                && !($barrier === 'fence' && ($sensory === 'audible' || $sensory === 'audio'))) {
                $f |= self::NODE_BARRIER;
            }
        }

        return $f;
    }

    /** OSRM `process_turn` (seconds): `$angle` in degrees, positive = right turn, 180 = U-turn. */
    public static function turnPenalty(float $angle, int $roads, bool $light, bool $uTurn): float
    {
        $d = $light ? self::TRAFFIC_LIGHT_PENALTY : 0.0;
        if ($roads > 2 || $uTurn) {
            $e = $angle >= 0.0
                ? -((13.0 / self::TURN_BIAS) * $angle / 180.0 - 6.5 * self::TURN_BIAS)
                : -((13.0 * self::TURN_BIAS) * (-$angle) / 180.0 - 6.5 / self::TURN_BIAS);
            $d += self::TURN_PENALTY / (1.0 + exp($e));
            if ($uTurn) {
                $d += self::U_TURN_PENALTY;
            }
        }

        return $d;
    }
}
