<?php

declare(strict_types=1);

namespace MapCova\GraphBuilder\Profile;

use MapCova\GraphBuilder\Support\Text;

/**
 * Pedestrian profile, from OSRM `foot.lua`, for a node-based graph: weight = duration, 5 km/h, only `oneway:foot`
 * is honoured, no turn restrictions.
 */
final class Foot extends Profile
{
    private const WALKING_SPEED = 5.0;
    private const WHITELIST = ['yes', 'foot', 'permissive', 'designated'];
    private const BLACKLIST = ['no', 'agricultural', 'forestry', 'private', 'delivery', 'use_sidepath'];
    private const HIERARCHY = ['foot', 'access'];

    public function name(): string
    {
        return 'foot';
    }

    public function id(): int
    {
        return 2;
    }

    public function edgeBased(): bool
    {
        return false;
    }

    public function exempt(string $except): bool
    {
        foreach (explode(';', $except) as $x) {
            if (Text::trim($x) === 'foot') {
                return true;
            }
        }

        return false;
    }

    private static function walkable(string $h): bool
    {
        return \in_array($h, [
            'primary', 'primary_link', 'secondary', 'secondary_link', 'tertiary', 'tertiary_link', 'unclassified',
            'residential', 'living_street', 'service', 'track', 'path', 'steps', 'pedestrian', 'platform', 'footway',
            'pier',
        ], true);
    }

    private static function surfaceSpeed(string $s): ?float
    {
        return match ($s) {
            'fine_gravel', 'gravel', 'pebblestone' => self::WALKING_SPEED * 0.75,
            'mud', 'sand' => self::WALKING_SPEED * 0.5,
            default => null,
        };
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
        $pier = ($t['man_made'] ?? null) === 'pier';
        if ($highway === null && $route === null && !$platform && $amenity === null && !$pier && !isset($t['bridge'])) {
            return null;
        }
        if (($t['impassable'] ?? null) === 'yes' || ($t['status'] ?? null) === 'impassable'
            || ($t['motorroad'] ?? null) === 'yes'
            || (isset($t['proposed']) && ($highway === null || $highway === 'proposed'))) {
            return null;
        }
        $access = self::findAccess($t, self::HIERARCHY, self::WHITELIST, []);
        if ($access !== null && \in_array($access, self::BLACKLIST, true)) {
            return null;
        }
        $accessOpen = $access !== null && !\in_array($access, self::BLACKLIST, true);
        foreach (['sidewalk', 'sidewalk:both', 'sidewalk:left', 'sidewalk:right'] as $k) {
            if (($t[$k] ?? null) === 'separate') {
                if (!$accessOpen) {
                    return null;
                }
                break;
            }
        }
        $f = true;
        $b = true;
        $ow = $t['oneway:foot'] ?? null;
        if ($ow === '-1') {
            $f = false;
        } elseif ($ow === 'yes' || $ow === '1' || $ow === 'true') {
            $b = false;
        }
        $speed = null;
        $duration = null;
        $startpoint = true;
        if ($route === 'ferry') {
            $duration = isset($t['duration']) ? self::parseDuration($t['duration']) : null;
            $speed = 5.0;
            $startpoint = false;
        }
        if ($speed === null) {
            if (($highway !== null && self::walkable($highway)) || $platform || $amenity !== null || $pier
                || ($access !== null && (\in_array($access, self::WHITELIST, true) || !\in_array($access, self::BLACKLIST, true)))) {
                $speed = self::WALKING_SPEED;
            } else {
                return null;
            }
        }
        if ($duration === null) {
            $s = isset($t['surface']) ? self::surfaceSpeed($t['surface']) : null;
            if ($s !== null) {
                $speed = min($s, $speed);
            }
        }

        return new Way($f ? $speed : 0.0, 1.0, $b ? $speed : 0.0, 1.0, false, $duration, $startpoint, false);
    }

    public function nodeFlags(array $tags): int
    {
        $f = 0;
        $barrier = $highway = $sensory = $bollard = null;
        $acc = [null, null];
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
                case 'bollard':
                    $bollard = $v;
                    break;
                case 'foot':
                    $acc[0] = $v;
                    break;
                case 'access':
                    $acc[1] = $v;
                    break;
            }
        }
        if ($highway === 'traffic_signals') {
            $f |= self::NODE_SIGNALS;
        }
        $a = $acc[0] ?? $acc[1];
        if ($a !== null) {
            if (\in_array($a, self::BLACKLIST, true)) {
                $f |= self::NODE_BARRIER;
            }
        } elseif ($barrier !== null && ($barrier === 'yes' || $barrier === 'wall' || $barrier === 'fence')
            && $bollard !== 'rising'
            && !($barrier === 'fence' && ($sensory === 'audible' || $sensory === 'audio'))) {
            $f |= self::NODE_BARRIER;
        }

        return $f;
    }
}
