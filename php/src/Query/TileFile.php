<?php

declare(strict_types=1);

namespace MapCova\GraphBuilder\Query;

use MapCova\GraphBuilder\Mcr\Container;
use MapCova\GraphBuilder\Mcr\Exports;
use MapCova\GraphBuilder\Support\Bin;
use RuntimeException;

/** A `.mcr` file of the edge-based profiles (car): snapping, labels, hierarchy, and the border and core of a tile. */
final class TileFile extends ChFile
{
    public const SEG = 40;
    public int $segments;
    public int $cells;
    public int $cell;
    private int $shadows;
    /** @var array<int,list<int>> */
    private array $segCache = [];
    /** @var array<int,list<array{int,int}>> */
    private array $geomCache = [];
    /** @var array{keys:list<string>,ch:list<int>,flags:list<int>,cross:list<array{idx:int,kind:int,other:string,tile:int,w:int,t:int,d:int}>}|null */
    private ?array $border = null;
    /** @var array<int,int>|null node id => border index */
    private ?array $borderOfNode = null;
    /** @var array<string,int>|null */
    private ?array $borderOfKey = null;
    /** @var array{hash:string,first:list<int>,arcs:list<int>}|null */
    private ?array $core = null;

    public function __construct(string $path)
    {
        parent::__construct($path, Container::MAGIC_TILE);
        $h = $this->read(0, 64);
        $u = Bin::ints('Vtype/Vnodes/Vupf/Vupb/Vsegs/Vcells', $h, 12);
        if ($u['type'] !== 1) {
            throw new RuntimeException("$path: only edge-based graphs are read here");
        }
        $this->segments = $u['segs'];
        $this->cells = $u['cells'];
        $this->cell = Bin::u32($h, 52);
        $this->shadows = intdiv($this->sec[7][1], 8);
    }

    public function clearCaches(): void
    {
        parent::clearCaches();
        $this->segCache = [];
        $this->geomCache = [];
    }

    /** @return array<string,mixed> */
    public function meta(): array
    {
        $j = json_decode($this->section(8), true);
        $out = [];
        if (\is_array($j)) {
            foreach ($j as $k => $v) {
                $out[(string) $k] = $v;
            }
        }

        return $out;
    }

    /** @return list<int> */
    private function seg(int $s): array
    {
        if (isset($this->segCache[$s])) {
            return $this->segCache[$s];
        }
        return $this->segCache[$s] = Bin::u32s($this->read($this->sec[3][0] + $s * self::SEG, self::SEG), 10);
    }

    /** @return list<array{int,int}> points of segment `$s` as (lat, lon) in 1e-6 degree */
    public function geom(int $s): array
    {
        if (isset($this->geomCache[$s])) {
            return $this->geomCache[$s];
        }
        $r = $this->seg($s);

        return $this->geomCache[$s] = Geo::decode($this->read($this->sec[4][0] + $r[0], $r[1]));
    }

    /** Whether segment `$s` can be snapped to (usable as a start or an end in some direction). */
    public function usable(int $s): bool
    {
        return ($this->seg($s)[3] & 12) !== 0;
    }

    /** Length in metres of segment `$s`. */
    public function lengthM(int $s): int
    {
        return $this->seg($s)[2];
    }

    /** @return list<int> */
    private function candidates(int $la, int $lo): array
    {
        $ci = intdiv($la + 90_000_000, $this->cell);
        $cj = intdiv($lo + 180_000_000, $this->cell);
        $dirOff = $this->sec[5][0];
        $listOff = $this->sec[6][0];
        $ids = [];
        for ($r = -1; $r <= 1; $r++) {
            $start = ($ci + $r) * 100000 + $cj - 1;
            $a = 0;
            $b = $this->cells;
            while ($a < $b) {
                $mid = ($a + $b) >> 1;
                $k = Bin::u32($this->read($dirOff + $mid * 8, 4));
                if ($k < $start) {
                    $a = $mid + 1;
                } else {
                    $b = $mid;
                }
            }
            if ($a >= $this->cells) {
                continue;
            }
            $take = min(3, $this->cells - $a);
            $e = Bin::u32s($this->read($dirOff + $a * 8, $take * 8), $take * 2);
            for ($t = 0; $t < $take; $t++) {
                $key = $e[$t * 2];
                if ($key > $start + 2) {
                    break;
                }
                $off = $listOff + $e[$t * 2 + 1];
                $cnt = Bin::u32($this->read($off, 4));
                foreach (Bin::u32s($this->read($off + 4, $cnt * 4), $cnt) as $id) {
                    $ids[$id] = $id;
                }
            }
        }

        return array_values($ids);
    }

    /**
     * The nearest segment usable as a start or an end, within `$maxRadius` metres.
     *
     * @return array{seg:int,dist:float,f:float}|null `f`: fraction of the segment, from its start, of the snapped point
     */
    public function snap(float $lat, float $lon, float $maxRadius = 200.0): ?array
    {
        $la = (int) round($lat * 1e6);
        $lo = (int) round($lon * 1e6);
        $kx = cos(deg2rad($lat)) * 0.111195;
        $ky = 0.111195;
        $best = null;
        foreach ($this->candidates($la, $lo) as $s) {
            $p = $this->geom($s);
            $cum = 0.0;
            $bd = INF;
            $bpos = 0.0;
            for ($k = 1, $n = \count($p); $k < $n; $k++) {
                $ax = ($p[$k - 1][1] - $lo) * $kx;
                $ay = ($p[$k - 1][0] - $la) * $ky;
                $bx = ($p[$k][1] - $lo) * $kx;
                $by = ($p[$k][0] - $la) * $ky;
                $dx = $bx - $ax;
                $dy = $by - $ay;
                $l2 = $dx * $dx + $dy * $dy;
                $t = $l2 > 0 ? max(0.0, min(1.0, -($ax * $dx + $ay * $dy) / $l2)) : 0.0;
                $px = $ax + $t * $dx;
                $py = $ay + $t * $dy;
                $d = sqrt($px * $px + $py * $py);
                $sl = sqrt($l2);
                if ($d < $bd) {
                    $bd = $d;
                    $bpos = $cum + $t * $sl;
                }
                $cum += $sl;
            }
            if ($bd <= $maxRadius && ($best === null || $bd < $best['dist'] || ($bd === $best['dist'] && $s < $best['seg']))) {
                $best = ['seg' => $s, 'dist' => $bd, 'f' => $cum > 0 ? $bpos / $cum : 0.0];
            }
        }

        return $best;
    }

    /**
     * Labels `[weight, duration, distance]` by node from which a route starts at the snapped point: the directed segments,
     * with a negative offset (the arc out of a segment costs all of it).
     *
     * @param array{seg:int,dist:float,f:float} $snap
     * @return array<int,array{int,int,int}>
     */
    public function sourceLabels(array $snap): array
    {
        $r = $this->seg($snap['seg']);
        $out = [];
        foreach ([0, 1] as $dir) {
            if (($r[3] & (4 << $dir)) === 0) {
                continue;
            }
            $fd = $dir === 0 ? $snap['f'] : 1.0 - $snap['f'];
            $out[$r[6 + $dir]] = [(int) round(-$fd * $r[8 + $dir]), (int) round(-$fd * $r[4 + $dir]), (int) round(-$fd * $r[2])];
        }

        return $out;
    }

    /**
     * Labels by node from which the snapped point is reached (and the copies of the segment made for via-way restrictions).
     *
     * @param array{seg:int,dist:float,f:float} $snap
     * @return array<int,array{int,int,int}>
     */
    public function targetLabels(array $snap): array
    {
        $r = $this->seg($snap['seg']);
        $out = [];
        foreach ([0, 1] as $dir) {
            if (($r[3] & (4 << $dir)) === 0) {
                continue;
            }
            $fd = $dir === 0 ? $snap['f'] : 1.0 - $snap['f'];
            $label = [(int) round($fd * $r[8 + $dir]), (int) round($fd * $r[4 + $dir]), (int) round($fd * $r[2])];
            $node = $r[6 + $dir];
            $out[$node] = $label;
            foreach ($this->shadowsOf($node) as $copy) {
                $out[$copy] = $label;
            }
        }

        return $out;
    }

    /** @return list<int> */
    private function shadowsOf(int $node): array
    {
        if ($this->shadows === 0) {
            return [];
        }
        $base = $this->sec[7][0];
        $a = 0;
        $b = $this->shadows;
        while ($a < $b) {
            $mid = ($a + $b) >> 1;
            if (Bin::u32($this->read($base + $mid * 8, 4)) < $node) {
                $a = $mid + 1;
            } else {
                $b = $mid;
            }
        }
        $out = [];
        while ($a < $this->shadows) {
            $e = Bin::u32s($this->read($base + $a * 8, 8), 2);
            if ($e[0] !== $node) {
                break;
            }
            $out[] = $e[1];
            $a++;
        }

        return $out;
    }

    /** Directed segment (`segment * 2 + direction`) of a node. */
    public function nodeSegDir(int $node): int
    {
        return Bin::u32($this->read($this->sec[0][0] + $node * 12 + 8, 4));
    }

    /**
     * Fraction of the directed segment `$sd`, in its direction of travel, at which the snapped point lies.
     *
     * @param array{seg:int,dist:float,f:float} $snap
     */
    public static function along(array $snap, int $sd): float
    {
        return ($sd & 1) !== 0 ? 1.0 - $snap['f'] : $snap['f'];
    }

    /** @return array{keys:list<string>,ch:list<int>,flags:list<int>,cross:list<array{idx:int,kind:int,other:string,tile:int,w:int,t:int,d:int}>} */
    public function border(): array
    {
        if (!$this->has(9)) {
            throw new RuntimeException("{$this->path} is not a tile (no border section)");
        }

        return $this->border ??= Exports::border($this->section(9));
    }

    /** @return array<int,int> node id => index in the border */
    public function borderOfNode(): array
    {
        if ($this->borderOfNode === null) {
            $this->borderOfNode = [];
            foreach ($this->border()['ch'] as $i => $n) {
                $this->borderOfNode[$n] = $i;
            }
        }

        return $this->borderOfNode;
    }

    public function borderIndex(string $key): ?int
    {
        if ($this->borderOfKey === null) {
            $this->borderOfKey = array_flip($this->border()['keys']);
        }

        return $this->borderOfKey[$key] ?? null;
    }

    /** @return array{hash:string,first:list<int>,arcs:list<int>} */
    public function core(): array
    {
        return $this->core ??= Exports::core($this->section(10));
    }

    /** @return array{int,int,int,int}|null weight, duration, distance and middle of the core arc between two border vertices */
    public function coreArc(int $i, int $j): ?array
    {
        $c = $this->core();
        for ($a = $c['first'][$i]; $a < $c['first'][$i + 1]; $a++) {
            if ($c['arcs'][$a * 5] === $j) {
                return [$c['arcs'][$a * 5 + 1], $c['arcs'][$a * 5 + 2], $c['arcs'][$a * 5 + 3], $c['arcs'][$a * 5 + 4]];
            }
        }

        return null;
    }
}
