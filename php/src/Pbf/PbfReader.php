<?php

declare(strict_types=1);

namespace MapCova\GraphBuilder\Pbf;

use Generator;
use MapCova\GraphBuilder\Support\Bin;
use RuntimeException;

/** Streams the blobs of an OSM PBF file; `$offset` resumes at a blob boundary. */
final class PbfReader
{
    /** Limits of the PBF specification: a blob header is under 64 KiB, a blob and its uncompressed block under 32 MiB. */
    private const MAX_HEADER = 64 << 10;
    private const MAX_BLOB = 32 << 20;

    /**
     * @return Generator<int,array{type:string,data:string,next:int}> keyed by the file offset the blob starts at;
     *   `data` is the decompressed block, `next` the offset of the following blob
     */
    public static function blobs(string $path, int $offset = 0): Generator
    {
        $f = @fopen($path, 'rb');
        if ($f === false) {
            throw new RuntimeException("cannot open $path");
        }
        try {
            if ($offset > 0) {
                fseek($f, $offset);
            }
            while (true) {
                $at = (int) ftell($f);
                $l = fread($f, 4);
                if ($l === false || \strlen($l) < 4) {
                    break;
                }
                $hl = Bin::ints('Nlen', $l)['len'];
                if ($hl > self::MAX_HEADER) {
                    throw new RuntimeException("not a PBF file: a blob header of $hl bytes (the limit is " . self::MAX_HEADER . ')');
                }
                $h = self::read($f, $hl);
                $p = 0;
                $type = '';
                $ds = 0;
                $n = \strlen($h);
                while ($p < $n) {
                    $key = Wire::varint($h, $p);
                    $field = $key >> 3;
                    $wt = $key & 7;
                    if ($wt === 2) {
                        $len = Wire::varint($h, $p);
                        if ($field === 1) {
                            $type = substr($h, $p, $len);
                        }
                        $p += $len;
                    } elseif ($wt === 0) {
                        $v = Wire::varint($h, $p);
                        if ($field === 3) {
                            $ds = $v;
                        }
                    } else {
                        throw new RuntimeException("unexpected wire type $wt in a blob header");
                    }
                }
                if ($ds > self::MAX_BLOB) {
                    throw new RuntimeException("a PBF blob of $ds bytes is over the limit of " . self::MAX_BLOB);
                }
                $blob = self::read($f, $ds);
                yield $at => ['type' => $type, 'data' => self::inflate($blob), 'next' => $at + 4 + $hl + $ds];
            }
        } finally {
            fclose($f);
        }
    }

    /** @param resource $f */
    private static function read($f, int $n): string
    {
        if ($n <= 0) {
            return '';
        }
        $s = '';
        while (\strlen($s) < $n) {
            $part = fread($f, max(1, $n - \strlen($s)));
            if ($part === false || $part === '') {
                throw new RuntimeException('truncated PBF file');
            }
            $s .= $part;
        }

        return $s;
    }

    private static function inflate(string $b): string
    {
        $p = 0;
        $n = \strlen($b);
        $raw = null;
        while ($p < $n) {
            $key = Wire::varint($b, $p);
            $field = $key >> 3;
            $wt = $key & 7;
            if ($wt === 2) {
                $len = Wire::varint($b, $p);
                if ($field === 1) {
                    $raw = substr($b, $p, $len);
                } elseif ($field === 3) {
                    $raw = @gzuncompress(substr($b, $p, $len), self::MAX_BLOB);
                    if ($raw === false) {
                        throw new RuntimeException('corrupt zlib data in a PBF blob, or a block over ' . self::MAX_BLOB . ' bytes');
                    }
                } elseif ($field >= 4 && $field <= 7) {
                    throw new RuntimeException('unsupported blob compression (only raw and zlib are read)');
                }
                $p += $len;
            } elseif ($wt === 0) {
                Wire::varint($b, $p);
            } else {
                throw new RuntimeException("unexpected wire type $wt in a blob");
            }
        }

        return $raw ?? '';
    }

    /** Unix time of the extract (`osmosis_replication_timestamp` of the header block), or null. */
    public static function timestamp(string $path): ?int
    {
        foreach (self::blobs($path) as $blob) {
            if ($blob['type'] !== 'OSMHeader') {
                return null;
            }
            $d = $blob['data'];
            $p = 0;
            $n = \strlen($d);
            while ($p < $n) {
                $key = Wire::varint($d, $p);
                $field = $key >> 3;
                $wt = $key & 7;
                if ($field === 32 && $wt === 0) {
                    return Wire::varint($d, $p);
                }
                Wire::skip($d, $p, $wt);
            }

            return null;
        }

        return null;
    }
}
