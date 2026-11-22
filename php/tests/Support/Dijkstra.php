<?php

declare(strict_types=1);

namespace MapCova\GraphBuilder\Tests\Support;

use MapCova\GraphBuilder\Support\Bin;
use MapCova\GraphBuilder\Support\IntMinHeap;

/** Plain Dijkstra over an arc file (`arcs.bin`: six u32 per arc), the reference the hierarchy is checked against. */
final class Dijkstra
{
    /** @var array<int,list<array{int,int,int,int}>> */
    private array $adj = [];

    public function __construct(string $arcFile)
    {
        $raw = (string) file_get_contents($arcFile);
        for ($o = 0, $e = \strlen($raw); $o + 24 <= $e; $o += 24) {
            [$src, $dst, $w, $t, $d] = Bin::u32s($raw, 5, $o);
            if ($src === $dst) {
                continue;
            }
            $this->adj[$src][] = [$dst, max($w, 1), $t, $d];
        }
    }

    /** @return int|null the shortest weight */
    public function weight(int $from, int $to): ?int
    {
        $dist = [$from => 0];
        $h = new IntMinHeap();
        $h->insert($from);
        $closed = [];
        while (!$h->isEmpty()) {
            $k = $h->extract();
            $u = $k & 0xFFFFFF;
            if (isset($closed[$u])) {
                continue;
            }
            $closed[$u] = true;
            if ($u === $to) {
                return $dist[$u];
            }
            foreach ($this->adj[$u] ?? [] as [$v, $w]) {
                $nw = $dist[$u] + $w;
                if (!isset($dist[$v]) || $nw < $dist[$v]) {
                    $dist[$v] = $nw;
                    $h->insert(($nw << 24) | $v);
                }
            }
        }

        return null;
    }
}
