<?php

declare(strict_types=1);

namespace MapCova\GraphBuilder\Support;

use RuntimeException;

/** Typed reads of decoded JSON: a field of the wrong type is an error, not a silent conversion. */
final class Json
{
    /** @param array<mixed> $a */
    public static function int(array $a, string $key, ?int $default = null): int
    {
        $v = \array_key_exists($key, $a) ? $a[$key] : $default;
        if (!\is_int($v)) {
            throw new RuntimeException("state field $key is missing or not an integer");
        }

        return $v;
    }

    /** @param array<mixed> $a */
    public static function string(array $a, string $key, ?string $default = null): string
    {
        $v = \array_key_exists($key, $a) ? $a[$key] : $default;
        if (!\is_string($v)) {
            throw new RuntimeException("state field $key is missing or not a string");
        }

        return $v;
    }

    /** @param array<mixed> $a */
    public static function bool(array $a, string $key, bool $default): bool
    {
        $v = \array_key_exists($key, $a) ? $a[$key] : $default;
        if (!\is_bool($v)) {
            throw new RuntimeException("state field $key is not a boolean");
        }

        return $v;
    }

    /**
     * @param array<mixed> $a
     * @return list<int>
     */
    public static function ints(array $a, string $key): array
    {
        $v = $a[$key] ?? null;
        if (!\is_array($v)) {
            throw new RuntimeException("state field $key is missing or not a list");
        }
        $out = [];
        foreach ($v as $x) {
            if (!\is_int($x)) {
                throw new RuntimeException("state field $key holds a non-integer");
            }
            $out[] = $x;
        }

        return $out;
    }

    /**
     * @param array<mixed> $a
     * @return array<mixed>
     */
    public static function arr(array $a, string $key): array
    {
        $v = $a[$key] ?? [];
        if (!\is_array($v)) {
            throw new RuntimeException("state field $key is not an object");
        }

        return $v;
    }
}
