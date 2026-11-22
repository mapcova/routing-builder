<?php

declare(strict_types=1);

namespace MapCova\GraphBuilder\Pbf;

use RuntimeException;

/** Protocol Buffers wire format, just what an OSM PBF file needs. */
final class Wire
{
    /** Reads a varint at `$p` and moves `$p` past it. */
    public static function varint(string $s, int &$p): int
    {
        $b = \ord($s[$p++]);
        $v = $b & 0x7f;
        $sh = 7;
        while ($b >= 0x80) {
            $b = \ord($s[$p++]);
            $v |= ($b & 0x7f) << $sh;
            $sh += 7;
        }

        return $v;
    }

    /** Skips the value of a field of wire type `$wt` (the tag is already read). */
    public static function skip(string $s, int &$p, int $wt): void
    {
        switch ($wt) {
            case 0:
                self::varint($s, $p);
                break;
            case 1:
                $p += 8;
                break;
            case 2:
                $len = self::varint($s, $p);
                $p += $len;
                break;
            case 5:
                $p += 4;
                break;
            default:
                throw new RuntimeException("unsupported protobuf wire type $wt");
        }
    }

    /** @return list<int> packed varints of `$s[$p..$end)` */
    public static function packed(string $s, int $p, int $end): array
    {
        $out = [];
        while ($p < $end) {
            $b = \ord($s[$p++]);
            $v = $b & 0x7f;
            $sh = 7;
            while ($b >= 0x80) {
                $b = \ord($s[$p++]);
                $v |= ($b & 0x7f) << $sh;
                $sh += 7;
            }
            $out[] = $v;
        }

        return $out;
    }

    /** @return list<int> packed zigzag varints, delta-decoded (running sum) */
    public static function packedDelta(string $s, int $p, int $end): array
    {
        $out = [];
        $acc = 0;
        while ($p < $end) {
            $b = \ord($s[$p++]);
            $v = $b & 0x7f;
            $sh = 7;
            while ($b >= 0x80) {
                $b = \ord($s[$p++]);
                $v |= ($b & 0x7f) << $sh;
                $sh += 7;
            }
            $acc += ($v >> 1) ^ -($v & 1);
            $out[] = $acc;
        }

        return $out;
    }

    /** `sint` zigzag decoding of one value. */
    public static function zigzag(int $v): int
    {
        return ($v >> 1) ^ -($v & 1);
    }
}
