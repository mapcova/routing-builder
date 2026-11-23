<?php

declare(strict_types=1);

namespace MapCova\GraphBuilder\Tiles;

/**
 * Stable key of a graph vertex: the directed road segment `(from OSM node, to OSM node, way, direction)`, 28 bytes,
 * big-endian so that the byte order is the numeric order. `dc` is `direction | copy << 1`: copy 0 is the segment
 * itself, copy `n` the n-th copy made for a via-way restriction (copies are numbered by the key of the segment the
 * restricted sequence starts from). The key names a vertex in every file: a tile rebuilt from a newer extract keeps
 * the keys of the roads that did not change, so the links that cite them stay valid.
 */
final class Key
{
    public const BYTES = 28;

    public static function make(int $from, int $to, int $way, int $dc): string
    {
        return pack('JJJN', $from, $to, $way, $dc);
    }

    /** @return array{from:int,to:int,way:int,dc:int} */
    public static function parse(string $k): array
    {
        $u = unpack('Jfrom/Jto/Jway/Ndc', $k);
        if ($u === false) {
            throw new \RuntimeException('bad vertex key');
        }
        /** @var array{from:int,to:int,way:int,dc:int} $u */
        return $u;
    }

    public static function show(string $k): string
    {
        $p = self::parse($k);

        return "{$p['from']}>{$p['to']}/{$p['way']}/" . ($p['dc'] & 1) . ($p['dc'] > 1 ? 'c' . ($p['dc'] >> 1) : '');
    }
}
