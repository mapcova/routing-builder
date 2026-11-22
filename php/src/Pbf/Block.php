<?php

declare(strict_types=1);

namespace MapCova\GraphBuilder\Pbf;

use RuntimeException;

/** A decoded `PrimitiveBlock`: string table, the entity groups (as byte ranges) and the coordinate scaling. */
final class Block
{
    /**
     * @param list<string> $strings
     * @param list<array{int,int,int}> $groups [field (1 node, 2 dense, 3 way, 4 relation), start, end] in `$data`
     */
    public function __construct(
        public readonly string $data,
        public readonly array $strings,
        public readonly array $groups,
        public readonly int $granularity,
        public readonly int $latOffset,
        public readonly int $lonOffset,
    ) {
    }

    public static function decode(string $d): self
    {
        $p = 0;
        $n = \strlen($d);
        $st = [];
        $groups = [];
        $gran = 100;
        $lat0 = 0;
        $lon0 = 0;
        while ($p < $n) {
            $key = Wire::varint($d, $p);
            $field = $key >> 3;
            $wt = $key & 7;
            if ($wt === 2) {
                $len = Wire::varint($d, $p);
                $end = $p + $len;
                if ($field === 1) {
                    $q = $p;
                    while ($q < $end) {
                        $q++; // tag of `s`
                        $sl = Wire::varint($d, $q);
                        $st[] = substr($d, $q, $sl);
                        $q += $sl;
                    }
                } elseif ($field === 2) {
                    $q = $p;
                    while ($q < $end) {
                        $k2 = Wire::varint($d, $q);
                        $l2 = Wire::varint($d, $q);
                        $groups[] = [$k2 >> 3, $q, $q + $l2];
                        $q += $l2;
                    }
                }
                $p = $end;
            } elseif ($wt === 0) {
                $v = Wire::varint($d, $p);
                if ($field === 17) {
                    $gran = $v;
                } elseif ($field === 19) {
                    $lat0 = $v;
                } elseif ($field === 20) {
                    $lon0 = $v;
                }
            } else {
                throw new RuntimeException("unexpected wire type $wt in a primitive block");
            }
        }

        return new self($d, $st, $groups, $gran, $lat0, $lon0);
    }
}
