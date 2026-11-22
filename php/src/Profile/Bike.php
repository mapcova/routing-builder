<?php

declare(strict_types=1);

namespace MapCova\GraphBuilder\Profile;

use MapCova\GraphBuilder\Support\F32;
use MapCova\GraphBuilder\Support\Text;

/**
 * Bicycle profile, from OSRM `bicycle.lua`, for a node-based graph: weight = duration. Not modelled: turn
 * penalties, the U-turn penalty and the 30 s mode-change penalty. No elevation.
 */
final class Bike extends Profile
{
    private const DEFAULT_SPEED = 15.0;
    private const WALKING_SPEED = 4.0;
    private const MAX_SPEED = 21.0;
    private const WHITELIST = ['yes', 'permissive', 'designated'];
    private const BLACKLIST = ['no', 'private', 'agricultural', 'forestry', 'delivery', 'use_sidepath'];
    private const HIERARCHY = ['bicycle', 'vehicle', 'access'];
    private const CYCLEWAY = ['track', 'lane', 'share_busway', 'sharrow', 'shared', 'shared_lane'];
    private const OPPOSITE = ['opposite', 'opposite_lane', 'opposite_track'];

    public function name(): string
    {
        return 'bike';
    }

    public function id(): int
    {
        return 1;
    }

    public function edgeBased(): bool
    {
        return false;
    }

    public function exempt(string $except): bool
    {
        foreach (explode(';', $except) as $x) {
            if (Text::trim($x) === 'bicycle') {
                return true;
            }
        }

        return false;
    }

    private static function bicycleSpeed(string $h): ?float
    {
        return match ($h) {
            'cycleway', 'primary', 'primary_link', 'secondary', 'secondary_link', 'tertiary', 'tertiary_link',
            'residential', 'unclassified', 'living_street', 'service' => self::DEFAULT_SPEED,
            'track' => 12.0,
            'path' => 13.0,
            default => null,
        };
    }

    private static function surfaceSpeed(string $s): ?float
    {
        return match ($s) {
            'asphalt', 'chipseal', 'concrete', 'concrete_lanes' => self::DEFAULT_SPEED,
            'wood', 'metal', 'cobblestone:flattened', 'paving_stones', 'compacted', 'fine_gravel', 'ground' => 10.0,
            'cobblestone' => 7.0,
            'unpaved', 'gravel', 'pebblestone', 'grass_paver', 'earth', 'grass' => 6.0,
            'dirt' => 8.0,
            'laterite' => 5.0,
            'mud', 'sand', 'woodchips' => 3.0,
            'sett' => 9.0,
            default => null,
        };
    }

    /** @param list<string> $set */
    private static function has(?string $v, array $set): bool
    {
        return $v !== null && \in_array($v, $set, true);
    }

    /**
     * The legal speed limit, the surface and the cap on one direction.
     *
     * @param array<string,string> $t
     * @return array{bool,float} whether the direction is usable, its speed
     */
    private static function limit(array $t, ?float $duration, bool $ok, float $speed, string $dir): array
    {
        if ($speed > 0.0 && $duration === null) {
            $m = self::parseMaxspeed($t['maxspeed:' . $dir] ?? '');
            if (!($m > 0.0)) {
                $m = self::parseMaxspeed($t['maxspeed'] ?? '');
            }
            if ($m > 0.0) {
                $speed = min($speed, $m);
            }
            $s = isset($t['surface']) ? self::surfaceSpeed($t['surface']) : null;
            if ($s !== null) {
                $speed = min($speed, $s);
            }
            $speed = min($speed, self::MAX_SPEED);
        }
        if ($speed <= 0.0 && $duration === null) {
            $ok = false;
        }

        return [$ok, $speed];
    }

    public function way(array $t): ?Way
    {
        $highway = self::nonEmpty($t['highway'] ?? null);
        $route = self::nonEmpty($t['route'] ?? null);
        $platform = ($t['railway'] ?? null) === 'platform' || ($t['public_transport'] ?? null) === 'platform';
        $amenity = $t['amenity'] ?? null;
        if ($amenity !== 'parking' && $amenity !== 'parking_entrance') {
            $amenity = null;
        }
        $manMade = $t['man_made'] ?? null;
        $movable = ($t['bridge'] ?? null) === 'movable';
        if ($highway === null && $route === null && !$platform && $amenity === null && $manMade !== 'pier'
            && !$movable && !isset($t['railway'])) {
            return null;
        }
        // blocked ways
        if (($t['motorroad'] ?? null) === 'yes' || ($t['impassable'] ?? null) === 'yes' || ($t['status'] ?? null) === 'impassable') {
            return null;
        }
        if (isset($t['construction']) && !\in_array($t['construction'], ['no', 'widening', 'minor'], true)) {
            return null;
        }
        if (isset($t['proposed']) && ($highway === null || $highway === 'proposed')) {
            return null;
        }
        $access = self::findAccess($t, self::HIERARCHY, self::WHITELIST, []);
        if ($access !== null && \in_array($access, self::BLACKLIST, true)) {
            return null;
        }
        $speed = null;
        $duration = null;
        $startpoint = true;
        $allowsPush = false;
        if ($movable) {
            $duration = isset($t['duration']) ? self::parseDuration($t['duration']) : null;
            $speed = 5.0;
            $allowsPush = true;
        } elseif ($route === 'ferry') {
            $duration = isset($t['duration']) ? self::parseDuration($t['duration']) : null;
            $speed = 5.0;
            $startpoint = false;
        } elseif ($platform) {
            $speed = self::WALKING_SPEED;
            $allowsPush = true;
        } elseif ($amenity !== null) {
            $speed = 10.0;
            $allowsPush = true;
        } elseif ($highway !== null && self::bicycleSpeed($highway) !== null) {
            $speed = self::bicycleSpeed($highway);
            $allowsPush = true;
        } elseif ($access !== null && \in_array($access, self::WHITELIST, true)) {
            $speed = self::DEFAULT_SPEED;
            $allowsPush = true;
        }
        $fOk = true;
        $bOk = true;
        $fSpeed = $speed ?? -1.0;
        $bSpeed = $fSpeed;
        // oneway
        $junction = $t['junction'] ?? null;
        $implied = $junction === 'roundabout' || $junction === 'circular' || $highway === 'motorway';
        $reverse = false;
        $owBike = $t['oneway:bicycle'] ?? null;
        $ow = $t['oneway'] ?? null;
        if (self::isYes($owBike)) {
            $bOk = false;
        } elseif ($owBike === 'no' || $owBike === '0' || $owBike === 'false') {
            // two ways
        } elseif ($owBike === '-1') {
            $fOk = false;
            $reverse = true;
        } elseif (self::isYes($ow)) {
            $bOk = false;
        } elseif ($ow === 'no' || $ow === '0' || $ow === 'false') {
            // two ways
        } elseif ($ow === '-1') {
            $fOk = false;
            $reverse = true;
        } elseif ($implied) {
            $bOk = false;
        }
        // cycleways on normal roads
        $cw = $t['cycleway'] ?? $t['cycleway:both'] ?? null;
        $cl = $t['cycleway:left'] ?? null;
        $cr = $t['cycleway:right'] ?? null;
        $cf = false;
        $cb = false;
        if ($fOk && $bOk && !$implied) {
            if (self::has($cw, self::CYCLEWAY)) {
                $cf = true;
                $cb = true;
            }
            if (self::has($cr, self::CYCLEWAY) || self::has($cl, self::OPPOSITE)) {
                $cf = true;
            }
            if (self::has($cl, self::CYCLEWAY) || self::has($cr, self::OPPOSITE)) {
                $cb = true;
            }
        } else {
            $twoway = self::has($cw, self::OPPOSITE) || self::has($cr, self::OPPOSITE) || self::has($cl, self::OPPOSITE);
            $opposite = self::has($cl, self::OPPOSITE) || self::has($cr, self::OPPOSITE);
            $onewayCw = self::has($cw, self::CYCLEWAY) || self::has($cr, self::CYCLEWAY) || self::has($cl, self::CYCLEWAY);
            if ($twoway) {
                $cf = true;
                $cb = true;
            } elseif ($opposite) {
                if ($reverse) {
                    $cf = true;
                } else {
                    $cb = true;
                }
            } elseif ($onewayCw) {
                if ($reverse) {
                    $cb = true;
                } else {
                    $cf = true;
                }
            }
        }
        if ($cb) {
            $bOk = true;
            $bSpeed = self::DEFAULT_SPEED;
        }
        if ($cf) {
            $fOk = true;
            $fSpeed = self::DEFAULT_SPEED;
        }
        // pushing the bike where riding is not possible
        $foot = $t['foot'] ?? null;
        $pf = null;
        $pb = null;
        if ($foot !== 'no' && (!$fOk || !$bOk || $fSpeed < 0.0 || $bSpeed < 0.0)) {
            $p = match ($highway) {
                'footway', 'pedestrian' => self::WALKING_SPEED,
                'steps' => 2.0,
                default => null,
            };
            if ($p !== null) {
                $pf = $p;
                $pb = $p;
            } elseif ($manMade === 'pier') {
                $pf = self::WALKING_SPEED;
                $pb = self::WALKING_SPEED;
            } elseif ($foot === 'yes') {
                $pf = self::WALKING_SPEED;
                if (!$implied) {
                    $pb = self::WALKING_SPEED;
                }
            } elseif (($t['foot:forward'] ?? null) === 'yes') {
                $pf = self::WALKING_SPEED;
            } elseif (($t['foot:backward'] ?? null) === 'yes') {
                $pb = self::WALKING_SPEED;
            } elseif ($allowsPush) {
                $pf = self::WALKING_SPEED;
                if (!$implied) {
                    $pb = self::WALKING_SPEED;
                }
            }
        }
        if ($pf !== null && (!$fOk || $fSpeed < 0.0)) {
            $fOk = true;
            $fSpeed = $pf;
        }
        if ($pb !== null && (!$bOk || $bSpeed < 0.0)) {
            $bOk = true;
            $bSpeed = $pb;
        }
        if (($t['bicycle'] ?? null) === 'dismount') {
            $fOk = true;
            $fSpeed = self::WALKING_SPEED;
            $bOk = true;
            $bSpeed = self::WALKING_SPEED;
        }
        // cycleway mapped as a separate way
        $bicycle = $t['bicycle'] ?? null;
        $separate = $cw === 'separate' || ($t['cycleway:both'] ?? null) === 'separate' || $cl === 'separate' || $cr === 'separate';
        if ($separate && !($bicycle === 'yes' || $bicycle === 'permissive' || $bicycle === 'designated' || $bicycle === 'destination')) {
            return null;
        }
        // legal speed limit, surface, cap
        [$fOk, $fSpeed] = self::limit($t, $duration, $fOk, $fSpeed, 'forward');
        [$bOk, $bSpeed] = self::limit($t, $duration, $bOk, $bSpeed, 'backward');
        if (!$fOk && !$bOk) {
            return null;
        }
        $min = F32::r(0.1);
        $dir = static fn (bool $ok, float $speed): float => $ok ? max($speed, $min) : 0.0;

        return new Way($dir($fOk, $fSpeed), 1.0, $dir($bOk, $bSpeed), 1.0, false, $duration, $startpoint, false);
    }

    public function nodeFlags(array $tags): int
    {
        $f = 0;
        $barrier = $highway = $sensory = null;
        $acc = [null, null, null];
        foreach ($tags as [$k, $v]) {
            switch ($k) {
                case 'highway':
                    $highway = $v;
                    break;
                case 'barrier':
                    $barrier = $v;
                    break;
                case 'sensory':
                    $sensory = $v;
                    break;
                case 'bicycle':
                    $acc[0] = $v;
                    break;
                case 'vehicle':
                    $acc[1] = $v;
                    break;
                case 'access':
                    $acc[2] = $v;
                    break;
            }
        }
        if ($highway === 'traffic_signals') {
            $f |= self::NODE_SIGNALS;
        }
        $a = null;
        foreach ($acc as $x) {
            if ($x !== null && $x !== '') {
                $a = $x;
                break;
            }
        }
        if ($a !== null) {
            if (\in_array($a, self::BLACKLIST, true) && $highway !== 'crossing') {
                $f |= self::NODE_BARRIER;
            }
        } elseif ($barrier !== null && ($barrier === 'yes' || $barrier === 'wall' || $barrier === 'fence')
            && !($barrier === 'fence' && ($sensory === 'audible' || $sensory === 'audio'))) {
            $f |= self::NODE_BARRIER;
        }

        return $f;
    }
}
