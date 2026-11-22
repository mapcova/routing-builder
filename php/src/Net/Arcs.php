<?php

declare(strict_types=1);

namespace MapCova\GraphBuilder\Net;

use Generator;
use MapCova\GraphBuilder\Support\Bin;

/**
 * The arcs of a graph before contraction, kept as one binary string (six u32 per arc: source, destination, weight and
 * duration in 1/10 s, distance in metres, and for node-based graphs `segment * 2 + direction` of the road the arc
 * follows, `NONE` for edge-based graphs): 24 bytes an arc instead of 96 in PHP arrays. This is also the content of
 * `arcs.bin`.
 */
final class Arcs
{
    public const BYTES = 24;
    private string $buf = '';
    private int $n = 0;

    public function count(): int
    {
        return $this->n;
    }

    public function add(int $src, int $dst, int $w, int $t, int $d, int $sd): void
    {
        $this->buf .= pack('V6', $src, $dst, $w, $t, $d, $sd);
        ++$this->n;
    }

    public function buffer(): string
    {
        return $this->buf;
    }

    /** @return array{int,int,int,int,int,int} source, destination, weight, duration, distance, road */
    public function row(int $i): array
    {
        [$a, $b, $c, $d, $e, $f] = Bin::u32s($this->buf, 6, $i * self::BYTES);

        return [$a, $b, $c, $d, $e, $f];
    }

    /** @return Generator<int,array{int,int,int,int,int,int}> */
    public function rows(): Generator
    {
        for ($i = 0; $i < $this->n; $i++) {
            yield $i => $this->row($i);
        }
    }
}
