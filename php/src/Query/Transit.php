<?php

declare(strict_types=1);

namespace MapCova\GraphBuilder\Query;

use MapCova\GraphBuilder\Support\Bin;
use MapCova\GraphBuilder\Tiles\Key;
use RuntimeException;

/**
 * The transit file (`.mct`) of a tile: the simplified polyline of every core arc (border vertex to border vertex) and of every
 * outgoing cross arc, so that a route through a tile that is not installed still has its geometry. Layout in FORMAT.md:
 * header of 64 bytes (magic `MCT\0`, version 1, vertices, core arcs, cross arcs), vertex keys (28 bytes each, sorted), core
 * arc first indices (u32, vertices + 1) and targets (u32), cross arcs (u32 vertex index, 28-byte key of the other end), then
 * the offsets (u32, core arcs + cross arcs + 1) into the polylines, which are coded like the `geometry` of a tile.
 */
final class Transit
{
    public const MAGIC = "MCT\0";
    /** @var resource */
    private $fh;
    private int $nv;
    private int $na;
    private int $nc;
    /** @var array<string,int> */
    private array $vertex = [];
    /** @var list<int> */
    private array $first = [];
    /** @var list<int> */
    private array $dst = [];
    /** @var array<string,int> "idx:key" of the other end => cross arc index */
    private array $cross = [];
    /** @var list<int> */
    private array $off = [];
    private int $dataOff;
    public int $reads = 0;

    public function __construct(public readonly string $path)
    {
        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            throw new RuntimeException("cannot open $path");
        }
        $this->fh = $fh;
        $h = $this->read(0, 64);
        $u = Bin::ints('Vversion/Vnv/Vna/Vnc', $h, 4);
        if (substr($h, 0, 4) !== self::MAGIC || $u['version'] !== 1) {
            throw new RuntimeException("$path is not a transit file");
        }
        [$this->nv, $this->na, $this->nc] = [$u['nv'], $u['na'], $u['nc']];
        $o = 64;
        $keys = $this->read($o, $this->nv * Key::BYTES);
        for ($i = 0; $i < $this->nv; $i++) {
            $this->vertex[substr($keys, $i * Key::BYTES, Key::BYTES)] = $i;
        }
        $o += $this->nv * Key::BYTES;
        $this->first = Bin::u32s($this->read($o, ($this->nv + 1) * 4), $this->nv + 1);
        $o += ($this->nv + 1) * 4;
        $this->dst = Bin::u32s($this->read($o, $this->na * 4), $this->na);
        $o += $this->na * 4;
        $x = $this->read($o, $this->nc * (4 + Key::BYTES));
        for ($i = 0; $i < $this->nc; $i++) {
            $e = $i * (4 + Key::BYTES);
            $this->cross[Bin::u32($x, $e) . ':' . substr($x, $e + 4, Key::BYTES)] = $i;
        }
        $o += $this->nc * (4 + Key::BYTES);
        $this->off = Bin::u32s($this->read($o, ($this->na + $this->nc + 1) * 4), $this->na + $this->nc + 1);
        $this->dataOff = $o + ($this->na + $this->nc + 1) * 4;
    }

    public function __destruct()
    {
        if (\is_resource($this->fh)) {
            fclose($this->fh);
        }
    }

    private function read(int $off, int $len): string
    {
        ++$this->reads;
        fseek($this->fh, $off);
        $d = $len > 0 ? fread($this->fh, $len) : '';
        if ($d === false || \strlen($d) < $len) {
            throw new RuntimeException("{$this->path}: short read");
        }

        return $d;
    }

    /** @return list<array{int,int}> */
    private function line(int $k): array
    {
        return Geo::decode($this->read($this->dataOff + $this->off[$k], $this->off[$k + 1] - $this->off[$k]));
    }

    /** @return list<array{int,int}> the polyline of the core arc between two border vertices */
    public function core(string $from, string $to): array
    {
        $i = $this->vertex[$from] ?? throw new RuntimeException("{$this->path} has no vertex " . Key::show($from));
        $j = $this->vertex[$to] ?? throw new RuntimeException("{$this->path} has no vertex " . Key::show($to));
        for ($a = $this->first[$i]; $a < $this->first[$i + 1]; $a++) {
            if ($this->dst[$a] === $j) {
                return $this->line($a);
            }
        }
        throw new RuntimeException("{$this->path} has no core arc " . Key::show($from) . ' to ' . Key::show($to));
    }

    /** @return list<array{int,int}> the polyline of the cross arc from a border vertex to a vertex of another tile */
    public function cross(string $from, string $to): array
    {
        $i = $this->vertex[$from] ?? throw new RuntimeException("{$this->path} has no vertex " . Key::show($from));
        $k = $this->cross["$i:$to"] ?? throw new RuntimeException("{$this->path} has no cross arc " . Key::show($from) . ' to ' . Key::show($to));

        return $this->line($this->na + $k);
    }
}
