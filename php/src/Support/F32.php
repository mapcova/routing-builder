<?php

declare(strict_types=1);

namespace MapCova\GraphBuilder\Support;

/**
 * Rust's `f32` arithmetic, imitated: PHP floats are doubles, so every operation Rust rounds to single precision is
 * wrapped in `F32::r()`.
 */
final class F32
{
    /** @var float `f32::MAX` */
    public const MAX = 3.4028234663852886E+38;

    public static function r(float $x): float
    {
        return Bin::f32(pack('g', $x));
    }
}
