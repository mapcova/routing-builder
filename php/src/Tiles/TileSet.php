<?php

declare(strict_types=1);

namespace MapCova\GraphBuilder\Tiles;

use MapCova\GraphBuilder\Build\Poly;
use RuntimeException;

/**
 * A tiling: the tiles (each an Osmosis `.poly` whose rings are filled with the even-odd rule, so a ring inside
 * another is a hole and a ring inside that a tile again), the tree of links above them, and which tile owns a point.
 *
 * The owner of a point is read from a raster of cells of `cell` micro-degrees, filled once from the polygons (the first
 * tile in the list wins where two overlap); a cell no polygon covers belongs to the tile whose bounding box is the
 * nearest. Every build of every tile uses the same raster, so a road has one owner whatever the tile being built; the
 * owner of an uncovered cell is computed at the centre of the cell, so it does not depend on which point asked first, and
 * `margin()` grows the cells a tile owns that way as well, so that the extract of a tile holds every road the tile owns.
 * The tree has tiles as leaves (strings) and links as inner nodes (`{"id", "children"}`), of any depth.
 */
final class TileSet
{
    /** @var list<string> */
    public array $ids = [];
    /** @var array<string,int> */
    private array $index = [];
    /** @var array<string,string> link or tile id => parent link id */
    private array $parent = [];
    /** @var array<string,list<string>> link id => children ids */
    private array $children = [];
    public string $root = '';
    /** @var list<array{int,int,int,int}> bounding box (min lat, min lon, max lat, max lon) of each tile */
    private array $bbox = [];
    private string $grid = '';
    private int $rows = 0;
    private int $cols = 0;
    private int $minLa = 0;
    private int $minLo = 0;
    /** @var array<int,int> fallback owner by cell */
    private array $fallback = [];

    private function __construct(private readonly int $cell)
    {
    }

    public static function load(string $jsonPath): self
    {
        $raw = @file_get_contents($jsonPath);
        $j = $raw === false ? null : json_decode($raw, true);
        if (!\is_array($j) || !isset($j['tiles'], $j['tree']) || !\is_array($j['tiles'])) {
            throw new RuntimeException("$jsonPath is not a tiling file");
        }
        $cell = isset($j['cell']) && \is_int($j['cell']) ? $j['cell'] : 2000;
        $t = new self($cell);
        $rings = [];
        foreach ($j['tiles'] as $id => $poly) {
            if (!\is_string($poly)) {
                throw new RuntimeException('a tile is a file name');
            }
            $text = @file_get_contents(\dirname($jsonPath) . '/' . $poly);
            if ($text === false) {
                throw new RuntimeException("cannot read the polygon $poly of tile $id");
            }
            $t->index[(string) $id] = \count($t->ids);
            $t->ids[] = (string) $id;
            $rings[] = Poly::parse($text);
        }
        if (\count($t->ids) > 250) {
            throw new RuntimeException('at most 250 tiles in a tiling');
        }
        $t->root = $t->addNode($j['tree'], null);
        $t->raster($rings);

        return $t;
    }

    /** @param mixed $node */
    private function addNode($node, ?string $parent): string
    {
        if (\is_string($node)) {
            if (!isset($this->index[$node])) {
                throw new RuntimeException("the tree names the unknown tile $node");
            }
            if ($parent !== null) {
                $this->parent[$node] = $parent;
            }

            return $node;
        }
        if (!\is_array($node) || !isset($node['id'], $node['children']) || !\is_string($node['id']) || !\is_array($node['children'])) {
            throw new RuntimeException('a link of the tree has an id and children');
        }
        $id = $node['id'];
        if ($parent !== null) {
            $this->parent[$id] = $parent;
        }
        $this->children[$id] = [];
        foreach ($node['children'] as $c) {
            $this->children[$id][] = $this->addNode($c, $id);
        }

        return $id;
    }

    public function index(string $tile): int
    {
        return $this->index[$tile] ?? throw new RuntimeException("unknown tile $tile");
    }

    /**
     * A margin of `$metres` around a tile in cells of the raster: `[lat cells, lon cells]`. The longitude count is taken at
     * the latitude of the tile farthest from the equator (where a degree of longitude is shortest), so the margin is never
     * narrower than asked anywhere in the tile.
     *
     * @return array{int, int}
     */
    public function marginCells(int $tile, float $metres): array
    {
        [$a, , $c] = $this->bbox[$tile] ?? throw new RuntimeException("unknown tile index $tile");
        $lat = min(89.0, max(abs($a), abs($c)) / 1e6);
        $cellMetres = $this->cell / 1e6 * 111195;

        return [(int) ceil($metres / $cellMetres), (int) ceil($metres / ($cellMetres * cos(deg2rad($lat))))];
    }

    public function has(string $tile): bool
    {
        return isset($this->index[$tile]);
    }

    public function isTile(string $id): bool
    {
        return isset($this->index[$id]);
    }

    public function parentOf(string $id): ?string
    {
        return $this->parent[$id] ?? null;
    }

    /** @return list<string> */
    public function childrenOf(string $link): array
    {
        return $this->children[$link] ?? [];
    }

    /** @return list<string> the links of the tree, children before parents */
    public function links(): array
    {
        $out = [];
        $walk = function (string $id) use (&$walk, &$out): void {
            if (!isset($this->children[$id])) {
                return;
            }
            foreach ($this->children[$id] as $c) {
                $walk($c);
            }
            $out[] = $id;
        };
        $walk($this->root);

        return $out;
    }

    /** @return list<string> the tiles below a link (or the tile itself) */
    public function tilesBelow(string $id): array
    {
        if ($this->isTile($id)) {
            return [$id];
        }
        $out = [];
        foreach ($this->childrenOf($id) as $c) {
            foreach ($this->tilesBelow($c) as $t) {
                $out[] = $t;
            }
        }

        return $out;
    }

    /** @return list<string> the ancestors of a tile or link, nearest first */
    public function ancestors(string $id): array
    {
        $out = [];
        while (($id = $this->parent[$id] ?? null) !== null) {
            $out[] = $id;
        }

        return $out;
    }

    /** Index of the tile that owns the point (1e-6 degree). */
    public function ownerOf(int $la, int $lo): int
    {
        $r = intdiv($la - $this->minLa + $this->cell * 1000000, $this->cell) - 1000000;
        $c = intdiv($lo - $this->minLo + $this->cell * 1000000, $this->cell) - 1000000;
        if ($r >= 0 && $c >= 0 && $r < $this->rows && $c < $this->cols) {
            $o = \ord($this->grid[$r * $this->cols + $c]);
            if ($o !== 0) {
                return $o - 1;
            }
        }

        return $this->fallback[$r * 1000003 + $c] ??= $this->nearest($this->minLa + $r * $this->cell + intdiv($this->cell, 2), $this->minLo + $c * $this->cell + intdiv($this->cell, 2));
    }

    /** Whether a polygon of the tiling covers the point (else `ownerOf` falls back to the nearest bounding box). */
    public function covers(int $la, int $lo): bool
    {
        $r = intdiv($la - $this->minLa, $this->cell);
        $c = intdiv($lo - $this->minLo, $this->cell);

        return $la >= $this->minLa && $lo >= $this->minLo && $r < $this->rows && $c < $this->cols && $this->grid[$r * $this->cols + $c] !== "\0";
    }

    /** The tile whose bounding box is nearest (the lowest index on a tie). */
    private function nearest(int $la, int $lo): int
    {
        $best = 0;
        $bd = PHP_INT_MAX;
        foreach ($this->bbox as $i => [$a, $b, $c, $d]) {
            $dy = max($a - $la, 0, $la - $c);
            $dx = max($b - $lo, 0, $lo - $d);
            $dist = $dy * $dy + $dx * $dx;
            if ($dist < $bd) {
                $bd = $dist;
                $best = $i;
            }
        }

        return $best;
    }

    /** @return list<int> the tiles whose bounding box, grown by `$margin` (1e-6 degree), holds the point */
    public function near(int $la, int $lo, int $margin): array
    {
        $out = [];
        foreach ($this->bbox as $i => [$a, $b, $c, $d]) {
            if ($la >= $a - $margin && $la <= $c + $margin && $lo >= $b - $margin && $lo <= $d + $margin) {
                $out[] = $i;
            }
        }

        return $out;
    }

    /**
     * The cells of a tile, grown by `$latCells` and `$lonCells` (a margin), as the contours of the grown area in
     * degrees: rings of (lat, lon), counter-clockwise for an outer ring, clockwise for a hole. The extract of a
     * tile (the tile and a margin) is cut with them (`tools/tile-extract.sh`).
     *
     * @return list<list<array{float,float}>>
     */
    public function margin(int $tile, int $latCells, int $lonCells): array
    {
        $rows = $this->rows;
        $cols = $this->cols;
        $want = \chr($tile + 1);
        // the cells no polygon covers belong to the tile nearest by bounding box (`ownerOf`): they are the tile's too
        $grid = $this->grid;
        for ($r = 0; $r < $rows; $r++) {
            for ($c = 0; $c < $cols; $c++) {
                if ($grid[$r * $cols + $c] === "\0" && $this->nearest($this->minLa + $r * $this->cell + intdiv($this->cell, 2), $this->minLo + $c * $this->cell + intdiv($this->cell, 2)) === $tile) {
                    $grid[$r * $cols + $c] = $want;
                }
            }
        }
        // widen each row by the lon margin, then each column by the lat margin (rows are strings of one byte per cell)
        $wide = [];
        for ($r = 0; $r < $rows; $r++) {
            $row = substr($grid, $r * $cols, $cols);
            if (strpos($row, $want) === false) {
                continue;
            }
            $m = str_repeat("\0", $cols);
            $last = -1000000;
            for ($c = 0; $c < $cols; $c++) {
                if ($row[$c] === $want) {
                    $last = $c;
                }
                if ($c - $last <= $lonCells) {
                    $m[$c] = "\1";
                }
            }
            $last = 1000000;
            for ($c = $cols - 1; $c >= 0; $c--) {
                if ($row[$c] === $want) {
                    $last = $c;
                }
                if ($last - $c <= $lonCells) {
                    $m[$c] = "\1";
                }
            }
            $wide[$r] = $m;
        }
        $mask = array_fill(0, $rows, str_repeat("\0", $cols));
        foreach ($wide as $r => $m) {
            for ($d = -$latCells; $d <= $latCells; $d++) {
                $rr = $r + $d;
                if ($rr >= 0 && $rr < $rows) {
                    $mask[$rr] = $mask[$rr] | $m;
                }
            }
        }
        unset($wide);
        $has = static fn (int $r, int $c): bool => $r >= 0 && $r < $rows && $c >= 0 && $c < $cols && $mask[$r][$c] === "\1";
        /** @var array<int,list<int>> $next vertex (y * (cols + 1) + x) => the vertices its boundary edges lead to */
        $next = [];
        $w = $cols + 1;
        for ($r = 0; $r < $rows; $r++) {
            for ($c = 0; $c < $cols; $c++) {
                if ($mask[$r][$c] !== "\1") {
                    continue;
                }
                if (!$has($r - 1, $c)) {
                    $next[$r * $w + $c][] = $r * $w + $c + 1;
                }
                if (!$has($r, $c + 1)) {
                    $next[$r * $w + $c + 1][] = ($r + 1) * $w + $c + 1;
                }
                if (!$has($r + 1, $c)) {
                    $next[($r + 1) * $w + $c + 1][] = ($r + 1) * $w + $c;
                }
                if (!$has($r, $c - 1)) {
                    $next[($r + 1) * $w + $c][] = $r * $w + $c;
                }
            }
        }
        $rings = [];
        while ($next !== []) {
            $start = array_key_first($next);
            $ring = [];
            $v = $start;
            do {
                $ring[] = $v;
                $to = array_pop($next[$v]);
                if ($next[$v] === []) {
                    unset($next[$v]);
                }
                if ($to === null) {
                    break;
                }
                $v = $to;
            } while ($v !== $start && isset($next[$v]));
            // drop the points in the middle of a straight run
            $pts = [];
            $n = \count($ring);
            for ($i = 0; $i < $n; $i++) {
                $a = $ring[($i + $n - 1) % $n];
                $b = $ring[$i];
                $c = $ring[($i + 1) % $n];
                if (($b % $w) - ($a % $w) !== ($c % $w) - ($b % $w) || intdiv($b, $w) - intdiv($a, $w) !== intdiv($c, $w) - intdiv($b, $w)) {
                    $pts[] = [($this->minLa + intdiv($b, $w) * $this->cell) / 1e6, ($this->minLo + ($b % $w) * $this->cell) / 1e6];
                }
            }
            if (\count($pts) >= 4) {
                $rings[] = $pts;
            }
        }

        return $rings;
    }

    /** @param list<list<list<array{int,int}>>> $rings by tile, the rings of (lat, lon) */
    private function raster(array $rings): void
    {
        $minLa = PHP_INT_MAX;
        $minLo = PHP_INT_MAX;
        $maxLa = PHP_INT_MIN;
        $maxLo = PHP_INT_MIN;
        foreach ($rings as $i => $rs) {
            $b = [PHP_INT_MAX, PHP_INT_MAX, PHP_INT_MIN, PHP_INT_MIN];
            foreach ($rs as $ring) {
                foreach ($ring as [$la, $lo]) {
                    $b = [min($b[0], $la), min($b[1], $lo), max($b[2], $la), max($b[3], $lo)];
                }
            }
            if ($b[0] === PHP_INT_MAX) {
                throw new RuntimeException("tile {$this->ids[$i]} has no polygon");
            }
            $this->bbox[$i] = [$b[0], $b[1], $b[2], $b[3]];
            $minLa = min($minLa, $b[0]);
            $minLo = min($minLo, $b[1]);
            $maxLa = max($maxLa, $b[2]);
            $maxLo = max($maxLo, $b[3]);
        }
        // a band around the tiles, so that a margin can reach beyond the outermost tile
        $minLa -= 50 * $this->cell;
        $minLo -= 50 * $this->cell;
        $maxLa += 50 * $this->cell;
        $maxLo += 50 * $this->cell;
        $this->minLa = $minLa;
        $this->minLo = $minLo;
        $this->rows = intdiv($maxLa - $minLa, $this->cell) + 1;
        $this->cols = intdiv($maxLo - $minLo, $this->cell) + 1;
        $grid = str_repeat("\0", $this->rows * $this->cols);
        $cell = $this->cell;
        foreach ($rings as $i => $rs) {
            /** @var array<int,list<float>> $cross crossings of each row's centre line, by row */
            $cross = [];
            foreach ($rs as $ring) {
                for ($k = 0, $n = \count($ring); $k < $n; $k++) {
                    [$la1, $lo1] = $ring[$k];
                    [$la2, $lo2] = $ring[($k + 1) % $n];
                    if ($la1 === $la2) {
                        continue;
                    }
                    $lo = min($la1, $la2);
                    $hi = max($la1, $la2);
                    // rows whose centre latitude lies in [lo, hi)
                    $r0 = max((int) ceil(($lo - $minLa) / $cell - 0.5), 0);
                    $r1 = min((int) ceil(($hi - $minLa) / $cell - 0.5) - 1, $this->rows - 1);
                    for ($r = $r0; $r <= $r1; $r++) {
                        $lat = $minLa + ($r + 0.5) * $cell;
                        $cross[$r][] = $lo1 + ($lat - $la1) * ($lo2 - $lo1) / ($la2 - $la1);
                    }
                }
            }
            foreach ($cross as $r => $xs) {
                sort($xs);
                for ($q = 0; $q + 1 < \count($xs); $q += 2) {
                    $c0 = max((int) ceil(($xs[$q] - $this->minLo) / $cell - 0.5), 0);
                    $c1 = min((int) ceil(($xs[$q + 1] - $this->minLo) / $cell - 0.5) - 1, $this->cols - 1);
                    for ($c = $c0; $c <= $c1; $c++) {
                        $o = $r * $this->cols + $c;
                        if ($grid[$o] === "\0") {
                            $grid[$o] = \chr($i + 1);
                        }
                    }
                }
            }
        }
        $this->grid = $grid;
    }
}
