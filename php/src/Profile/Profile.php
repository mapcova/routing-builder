<?php

declare(strict_types=1);

namespace MapCova\GraphBuilder\Profile;

use MapCova\GraphBuilder\Support\F32;
use MapCova\GraphBuilder\Support\Text;

/**
 * A routing profile, adapted from the OSRM `car.lua`, `bicycle.lua` and `foot.lua` (BSD-2-Clause) as in the Rust
 * builder (`src/profile/`), which this is a line-by-line port of. Tags are `key => value` (first occurrence wins
 * for a way, as `Tags::get` in Rust); single-precision arithmetic is imitated with `F32::r()`.
 */
abstract class Profile
{
    public const NODE_SIGNALS = 1;
    public const NODE_BARRIER = 2;
    /** Via node of a turn restriction: always a junction. */
    public const NODE_RESTRICTION = 4;
    public const NODE_GATE = 8;

    public static function parse(string $name): ?self
    {
        return match ($name) {
            'car' => new Car(false),
            'car-notoll' => new Car(true),
            'bike' => new Bike(),
            'foot' => new Foot(),
            default => null,
        };
    }

    abstract public function name(): string;

    /** Numeric id stored in the header. */
    abstract public function id(): int;

    /** Car graphs are edge-based (turn penalties, restrictions); bike and foot are node-based. */
    abstract public function edgeBased(): bool;

    /** @param array<string,string> $tags */
    abstract public function way(array $tags): ?Way;

    /**
     * Flags of a node from its tags (`NODE_*`), in file order: a repeated key counts last.
     *
     * @param list<array{string,string}> $tags
     */
    abstract public function nodeFlags(array $tags): int;

    /** Whether `except=` of a restriction relation exempts this profile. */
    abstract public function exempt(string $except): bool;

    /** Keys the profiles look at: a way with none of them is skipped without decoding its tags. */
    public static function wayKeyOfInterest(string $k): bool
    {
        return match ($k) {
            'highway', 'route', 'bridge', 'railway', 'amenity', 'man_made', 'public_transport' => true,
            default => false,
        };
    }

    /** `highway`, `route` and the like: absent or empty = null. */
    protected static function nonEmpty(?string $v): ?string
    {
        return $v === null || $v === '' ? null : $v;
    }

    /**
     * `PT1H30M`, `P1DT2H`, `01:30`, `01:30:00`, or minutes. Seconds (an f32 value), at least 1.
     */
    public static function parseDuration(string $s): ?float
    {
        $s = Text::trim($s);
        if ($s !== '' && $s[0] === 'P') {
            return self::parseIsoDuration(substr($s, 1));
        }
        if (str_contains($s, ':')) {
            $parts = [];
            foreach (explode(':', $s) as $p) {
                $v = Text::parseF32($p);
                if ($v === null) {
                    return null;
                }
                $parts[] = $v;
            }
            if (\count($parts) === 2) {
                $t = F32::r(F32::r($parts[0] * 3600.0) + F32::r($parts[1] * 60.0));
            } elseif (\count($parts) === 3) {
                $t = F32::r(F32::r(F32::r($parts[0] * 3600.0) + F32::r($parts[1] * 60.0)) + $parts[2]);
            } else {
                return null;
            }

            return $t > 0.0 ? max($t, 1.0) : null;
        }
        $m = Text::parseF32($s);
        if ($m === null || !($m > 0.0)) {
            return null;
        }

        return max(F32::r($m * 60.0), 1.0);
    }

    private static function parseIsoDuration(string $rest): ?float
    {
        $total = 0.0;
        $num = '';
        $inTime = false;
        foreach (str_split($rest) as $c) {
            if ($c === 'T') {
                $inTime = true;
            } elseif (($c >= '0' && $c <= '9') || $c === '.') {
                $num .= $c;
            } elseif (str_contains('YWDHMS', $c)) {
                $v = Text::parseF32($num);
                if ($v === null) {
                    return null;
                }
                $num = '';
                $mult = match (true) {
                    $c === 'Y' => 365.0 * 86400.0,
                    $c === 'W' => 7.0 * 86400.0,
                    $c === 'D' => 86400.0,
                    $c === 'H' && $inTime => 3600.0,
                    $c === 'M' && $inTime => 60.0,
                    $c === 'M' => 30.0 * 86400.0,
                    $c === 'S' && $inTime => 1.0,
                    default => null,
                };
                if ($mult === null) {
                    return null;
                }
                $total = F32::r($total + F32::r($v * $mult));
            } else {
                return null;
            }
        }

        return $num === '' && $total > 0.0 ? max($total, 1.0) : null;
    }

    private static function maxspeedTable(string $s): ?float
    {
        return match ($s) {
            'at:rural', 'at:trunk' => 100.0,
            'ar:urban' => 40.0,
            'ar:rural' => 110.0,
            'be:motorway' => 120.0,
            'be-bru:rural', 'be-vlg:rural' => 70.0,
            'be-bru:urban' => 30.0,
            'bg:motorway' => 140.0,
            'ch:rural' => 80.0,
            'ch:trunk' => 100.0,
            'ch:motorway' => 120.0,
            'de:living_street' => 7.0,
            'de:rural' => 100.0,
            'de:motorway' => 0.0,
            'dk:rural', 'fr:rural', 'nl:rural', 'no:rural' => 80.0,
            'es:trunk' => 90.0,
            'gb:nsl_single', 'uk:nsl_single' => F32::r(96540.0 / 1000.0),
            'gb:nsl_dual', 'gb:motorway', 'uk:nsl_dual', 'uk:motorway' => F32::r(112630.0 / 1000.0),
            'nl:trunk' => 100.0,
            'pl:rural' => 100.0,
            'none' => 140.0,
            default => null,
        };
    }

    private static function maxspeedDefault(string $kind): ?float
    {
        return match ($kind) {
            'urban' => 50.0,
            'rural' => 90.0,
            'trunk' => 110.0,
            'motorway' => 130.0,
            default => null,
        };
    }

    /** Parsed km/h (an f32 value), or 0.0 when absent or unparseable (as in OSRM). */
    public static function parseMaxspeed(string $s): float
    {
        $digits = '';
        $n = \strlen($s);
        for ($i = 0; $i < $n && $s[$i] >= '0' && $s[$i] <= '9'; $i++) {
            $digits .= $s[$i];
        }
        if ($digits !== '') {
            $v = Text::parseF32($digits) ?? 0.0;
            if (str_contains($s, 'mph') || str_contains($s, 'mp/h')) {
                return F32::r(F32::r($v * 1609.0) / 1000.0);
            }

            return $v;
        }
        $lower = strtr($s, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz');
        $t = self::maxspeedTable($lower);
        if ($t !== null) {
            return $t;
        }
        if (\strlen($lower) > 3 && $lower[2] === ':') {
            $d = self::maxspeedDefault(substr($lower, 3));
            if ($d !== null) {
                return $d;
            }
        }

        return 0.0;
    }

    public static function isYes(?string $v): bool
    {
        return $v === 'yes' || $v === '1' || $v === 'true';
    }

    /**
     * First access tag of the hierarchy that is set; `a;b` keeps the most permissive part.
     *
     * @param array<string,string> $t
     * @param list<string> $hierarchy
     * @param list<string> $whitelist
     * @param list<string> $restricted
     */
    public static function findAccess(array $t, array $hierarchy, array $whitelist, array $restricted): ?string
    {
        $v = null;
        foreach ($hierarchy as $k) {
            if (isset($t[$k])) {
                $v = $t[$k];
                break;
            }
        }
        if ($v === null) {
            return null;
        }
        if (!str_contains($v, ';')) {
            return $v;
        }
        $best = $v;
        $bestRank = -1;
        foreach (explode(';', $v) as $part) {
            $p = Text::trim($part);
            $rank = \in_array($p, $whitelist, true) ? 3 : (\in_array($p, $restricted, true) ? 2 : 0);
            if ($rank >= $bestRank) { // `max_by_key` keeps the last of equal keys
                $best = $p;
                $bestRank = $rank;
            }
        }

        return $best;
    }

    /**
     * First of `$keys` present in the tags.
     *
     * @param array<string,string> $t
     * @param list<string> $keys
     */
    protected static function first(array $t, array $keys): ?string
    {
        foreach ($keys as $k) {
            if (isset($t[$k])) {
                return $t[$k];
            }
        }

        return null;
    }
}
