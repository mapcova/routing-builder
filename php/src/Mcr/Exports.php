<?php

declare(strict_types=1);

namespace MapCova\GraphBuilder\Mcr;

use MapCova\GraphBuilder\Support\Bin;
use MapCova\GraphBuilder\Tiles\Key;

/**
 * Reads the two sections a tile or a link exports to the level above: `border` (the exported vertices, sorted by key, with their
 * node id in the file and a flag; for a tile also its cross arcs) and `core` (the shortest ways between them in the file, as a
 * table of arcs). A tile exports its border vertices (flags `OUT` 1: a cross arc leaves it, `IN` 2: one enters it) and the K
 * most important interior vertices (`INTERIOR` 4); a link exports its outer border (`OUTER` 1) and its K_L most important
 * interior vertices (`INTERIOR`), or, at the top, the remainder (`INTERIOR`). The layouts are in FORMAT.md.
 */
final class Exports
{
    /** An exported vertex: 28-byte key, u32 node id in the file, u32 flags. */
    public const VERTEX_BYTES = 36;
    /** A vertex of a link (`vertices` section): 28-byte key, u32 node id. */
    public const KEYED_BYTES = 32;
    public const OUT = 1;
    public const IN = 2;
    public const OUTER = 1;
    public const INTERIOR = 4;
    public const CROSS_BYTES = 52;
    public const CORE_ARC_BYTES = 20;
    public const CORE_HEADER = 48;

    /** @return array{keys:list<string>,ch:list<int>,flags:list<int>,cross:list<array{idx:int,kind:int,other:string,tile:int,w:int,t:int,d:int}>} */
    public static function border(string $sec): array
    {
        $h = Bin::ints('Vnv/Vnc', $sec);
        $keys = [];
        $ch = [];
        $flags = [];
        for ($i = 0, $o = 16; $i < $h['nv']; $i++, $o += self::VERTEX_BYTES) {
            $keys[] = substr($sec, $o, Key::BYTES);
            [$ch[], $flags[]] = Bin::u32s($sec, 2, $o + Key::BYTES);
        }
        $cross = [];
        for ($i = 0; $i < $h['nc']; $i++, $o += self::CROSS_BYTES) {
            $u = Bin::ints('Vidx/Vkind', $sec, $o);
            $v = Bin::ints('Vtile/Vw/Vt/Vd', $sec, $o + 8 + Key::BYTES);
            $cross[] = ['idx' => $u['idx'], 'kind' => $u['kind'], 'other' => substr($sec, $o + 8, Key::BYTES), 'tile' => $v['tile'], 'w' => $v['w'], 't' => $v['t'], 'd' => $v['d']];
        }

        return ['keys' => $keys, 'ch' => $ch, 'flags' => $flags, 'cross' => $cross];
    }

    /** @return array{hash:string,first:list<int>,arcs:list<int>} `arcs` is flat: target index, weight, duration, distance, middle */
    public static function core(string $sec): array
    {
        $h = Bin::ints('Vnv/Vna', $sec);
        $first = Bin::u32s($sec, $h['nv'] + 1, 48);
        $arcs = Bin::u32s($sec, $h['na'] * 5, 48 + ($h['nv'] + 1) * 4);

        return ['hash' => substr($sec, 16, 20), 'first' => $first, 'arcs' => $arcs];
    }
}
