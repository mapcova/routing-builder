<?php

declare(strict_types=1);

namespace MapCova\GraphBuilder\Tests\Support;

use MapCova\GraphBuilder\Support\Bin;
use MapCova\GraphBuilder\Support\IntMinHeap;
use RuntimeException;

/** A reader of the `.mcr` sections the tests look at: header, sections, and a node-to-node CH query. */
final class McrFile
{
    public const SECTIONS = ['nodes', 'up_fwd', 'up_bwd', 'segments', 'geometry', 'grid_dir', 'grid_lists', 'shadows', 'meta'];

    /** @var array<string,array{int,int}> */
    public array $sections = [];
    public int $nodes;
    public int $upFwd;
    public int $upBwd;
    public int $segments;
    public string $head;
    private string $data;

    public function __construct(string $path)
    {
        $d = file_get_contents($path);
        if ($d === false || substr($d, 0, 4) !== "MCR\0") {
            throw new RuntimeException("$path is not a .mcr file");
        }
        $this->data = $d;
        $this->head = substr($d, 0, 4096);
        $h = Bin::ints('Vversion/Vprofile/Vtype/Vnodes/Vupf/Vupb/Vsegs/Vcells', $d, 4);
        $this->nodes = $h['nodes'];
        $this->upFwd = $h['upf'];
        $this->upBwd = $h['upb'];
        $this->segments = $h['segs'];
        foreach (self::SECTIONS as $i => $name) {
            $u = Bin::ints('Poff/Plen', $d, 128 + $i * 16);
            $this->sections[$name] = [$u['off'], $u['len']];
        }
    }

    public function section(string $name): string
    {
        [$off, $len] = $this->sections[$name];

        return substr($this->data, $off, $len);
    }

    /** @return list<array{int,int,int,int,int}> upward arcs of `$node`: target, weight, duration, distance, middle */
    public function arcs(int $node, bool $fwd): array
    {
        $nodes = $this->sections['nodes'][0];
        [$ff, $fb, , $tf, $tb] = Bin::u32s($this->data, 5, $nodes + $node * 12);
        $from = $fwd ? $ff : $fb;
        $to = $fwd ? $tf : $tb;
        $base = $this->sections[$fwd ? 'up_fwd' : 'up_bwd'][0];
        $out = [];
        for ($i = $from; $i < $to; $i++) {
            [$a, $b, $c, $d, $e] = Bin::u32s($this->data, 5, $base + $i * 20);
            $out[] = [$a, $b, $c, $d, $e];
        }

        return $out;
    }

    /** @return array<int,array{int,int,int}> node => [weight, duration, distance] reached going only upward */
    private function up(int $start, bool $fwd): array
    {
        $best = [$start => [0, 0, 0]];
        $heap = new IntMinHeap();
        $heap->insert(($start));
        $closed = [];
        while (!$heap->isEmpty()) {
            $k = $heap->extract();
            $u = $k & 0xFFFFFF;
            if (isset($closed[$u])) {
                continue;
            }
            $closed[$u] = true;
            foreach ($this->arcs($u, $fwd) as [$v, $w, $t, $d]) {
                $nw = $best[$u][0] + $w;
                if (!isset($best[$v]) || $nw < $best[$v][0]) {
                    $best[$v] = [$nw, $best[$u][1] + $t, $best[$u][2] + $d];
                    $heap->insert(($nw << 24) | $v);
                }
            }
        }

        return $best;
    }

    /** @return array{int,int,int}|null weight, duration, distance between two graph nodes */
    public function query(int $from, int $to): ?array
    {
        $f = $this->up($from, true);
        $b = $this->up($to, false);
        $best = null;
        foreach ($b as $v => $lb) {
            if (isset($f[$v]) && ($best === null || $f[$v][0] + $lb[0] < $best[0])) {
                $best = [$f[$v][0] + $lb[0], $f[$v][1] + $lb[1], $f[$v][2] + $lb[2]];
            }
        }

        return $best;
    }
}
