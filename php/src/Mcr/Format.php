<?php

declare(strict_types=1);

namespace MapCova\GraphBuilder\Mcr;

use RuntimeException;

/** The `.mcr` container, version 1 (see FORMAT.md): header, section table, 4096-byte aligned sections. */
final class Format
{
    public const VERSION = 1;
    public const HEADER = 4096;
    public const SECTIONS = ['nodes', 'up_fwd', 'up_bwd', 'segments', 'geometry', 'grid_dir', 'grid_lists', 'shadows', 'meta'];
    /** The sections a tile adds (format level 2): entries 9 and 10 of the section table, which a version 1 reader never reads. */
    public const TILE_SECTIONS = ['border', 'core'];
    public const SEG_BYTES = 40;
    public const LICENSE_OFFSET = 512;
    public const LICENSE_LEN = 1024;
    public const CELL = 5000;
    public const BUILDER = 'mcr-php 0.1.0';

    public const LICENSE = 'Contains information from OpenStreetMap, made available under the Open Database License (ODbL) 1.0 - https://opendatacommons.org/licenses/odbl/1-0/ - (c) OpenStreetMap contributors. This file is a Derived Database: if you share it, share it under the ODbL and keep this notice. Method of construction: https://github.com/mapcova/routing-builder (profiles adapted from OSRM, BSD-2-Clause).';
    public const ATTRIBUTION = '© OpenStreetMap contributors (ODbL)';

    /** JSON string literal, as the Rust builder writes it (non-ASCII characters stay as UTF-8). */
    public static function jsonStr(string $s): string
    {
        $o = '"';
        $n = \strlen($s);
        for ($i = 0; $i < $n; $i++) {
            $c = $s[$i];
            $code = \ord($c);
            if ($c === '"') {
                $o .= '\\"';
            } elseif ($c === '\\') {
                $o .= '\\\\';
            } elseif ($c === "\n") {
                $o .= '\\n';
            } elseif ($code < 0x20) {
                $o .= sprintf('\\u%04x', $code);
            } else {
                $o .= $c;
            }
        }

        return $o . '"';
    }

    /**
     * @param array{profile:int,edgeBased:bool,nodes:int,upFwd:int,upBwd:int,segments:int,cells:int,bbox:array{int,int,int,int},osmTimestamp:int,builtAt:int,builder:string,zone:string} $h
     * @param list<int> $lengths byte length of each of the nine sections
     * @return array{string,list<array{int,int}>} the 4096-byte header and the (offset, length) table
     */
    public static function header(array $h, array $lengths): array
    {
        $head = str_repeat("\0", self::HEADER);
        $put = static function (int $off, int $v) use (&$head): void {
            $head = substr_replace($head, pack('V', $v & 0xFFFFFFFF), $off, 4);
        };
        $head = substr_replace($head, "MCR\0", 0, 4);
        $put(4, self::VERSION);
        $put(8, $h['profile']);
        $put(12, $h['edgeBased'] ? 1 : 0);
        $put(16, $h['nodes']);
        $put(20, $h['upFwd']);
        $put(24, $h['upBwd']);
        $put(28, $h['segments']);
        $put(32, $h['cells']);
        foreach ($h['bbox'] as $i => $v) {
            $put(36 + $i * 4, $v);
        }
        $put(52, self::CELL);
        $put(56, $h['osmTimestamp']);
        $put(60, $h['builtAt']);
        $b = substr($h['builder'], 0, 32);
        $head = substr_replace($head, $b, 64, \strlen($b));
        $z = substr($h['zone'], 0, 31);
        $head = substr_replace($head, $z, 96, \strlen($z));
        $l = substr(self::LICENSE, 0, self::LICENSE_LEN - 1);
        $head = substr_replace($head, $l, self::LICENSE_OFFSET, \strlen($l));
        $off = self::HEADER;
        $table = [];
        foreach ($lengths as $i => $len) {
            $table[] = [$off, $len];
            $head = substr_replace($head, pack('PP', $off, $len), 128 + $i * 16, 16);
            $off += intdiv($len + 4095, 4096) * 4096;
        }
        if (\count($lengths) !== 9 && \count($lengths) !== 11) {
            throw new RuntimeException('a .mcr file has nine sections, or eleven for a tile');
        }

        return [$head, $table];
    }
}
