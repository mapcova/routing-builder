<?php

declare(strict_types=1);

namespace MapCova\GraphBuilder\Support;

/** String helpers with the semantics of the Rust standard library the profiles were written against. */
final class Text
{
    /** Characters of the Unicode `White_Space` property, as Rust's `str::trim` strips them. */
    private const SPACE = '[\x{9}-\x{d}\x{20}\x{85}\x{a0}\x{1680}\x{2000}-\x{200a}\x{2028}\x{2029}\x{202f}\x{205f}\x{3000}]';

    public static function trim(string $s): string
    {
        $r = preg_replace('/^' . self::SPACE . '+|' . self::SPACE . '+$/u', '', $s);

        return $r ?? trim($s);
    }

    /** `s.parse::<f32>().ok()`: the decimal grammar of Rust (sign, digits, dot, exponent, inf, nan), rounded to f32. */
    public static function parseF32(string $s): ?float
    {
        if (preg_match('/^[+-]?(?:\d+\.?\d*|\.\d+)(?:[eE][+-]?\d+)?$/', $s) === 1) {
            return F32::r((float) $s);
        }
        $l = strtolower($s);
        $sign = 1.0;
        if ($l !== '' && ($l[0] === '+' || $l[0] === '-')) {
            $sign = $l[0] === '-' ? -1.0 : 1.0;
            $l = substr($l, 1);
        }
        if ($l === 'inf' || $l === 'infinity') {
            return $sign * INF;
        }
        if ($l === 'nan') {
            return NAN;
        }

        return null;
    }

    /** `f.round()`: half away from zero. */
    public static function round(float $x): int
    {
        return (int) round($x);
    }
}
