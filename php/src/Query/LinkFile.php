<?php

declare(strict_types=1);

namespace MapCova\GraphBuilder\Query;

use MapCova\GraphBuilder\Mcr\Container;
use MapCova\GraphBuilder\Mcr\Exports;
use MapCova\GraphBuilder\Support\Bin;
use MapCova\GraphBuilder\Tiles\Key;
use RuntimeException;

/** A `.mco` link: the hierarchy over the border vertices of its children, and what it exports to the level above. */
final class LinkFile extends ChFile
{
    /** @var array<string,int>|null key => node id */
    private ?array $idOfKey = null;
    /** @var list<string>|null key of vertex `i` (vertex order = key order) */
    private ?array $vkeys = null;
    /** @var array{keys:list<string>,ch:list<int>,flags:list<int>,cross:list<array{idx:int,kind:int,other:string,tile:int,w:int,t:int,d:int}>}|null */
    private ?array $border = null;
    /** @var array{hash:string,first:list<int>,arcs:list<int>}|null */
    private ?array $core = null;
    /** @var array<string,mixed>|null */
    private ?array $meta = null;
    /** @var array<int,string>|null node id => key */
    private ?array $keyOfId = null;
    /** Number of uncontracted vertices: the node ids below it. */
    public int $uncontracted;
    public bool $remainder;

    public function __construct(string $path)
    {
        parent::__construct($path, Container::MAGIC_LINK);
        $this->uncontracted = Bin::u32($this->read(0, 64), 36);
        $this->remainder = (Bin::u32($this->read($this->sec[6][0], 16), 8) & 1) === 1;
    }

    /** @return array<string,mixed> */
    public function meta(): array
    {
        if ($this->meta === null) {
            $j = json_decode($this->section(7), true);
            $this->meta = [];
            if (\is_array($j)) {
                foreach ($j as $k => $v) {
                    $this->meta[(string) $k] = $v;
                }
            }
        }

        return $this->meta;
    }

    private function loadVertices(): void
    {
        if ($this->idOfKey !== null) {
            return;
        }
        $sec = $this->section(3);
        $this->idOfKey = [];
        $this->vkeys = [];
        $this->keyOfId = [];
        for ($i = 0, $o = 0, $n = intdiv(\strlen($sec), Exports::KEYED_BYTES); $i < $n; $i++, $o += Exports::KEYED_BYTES) {
            $k = substr($sec, $o, Key::BYTES);
            $ch = Bin::u32($sec, $o + Key::BYTES);
            $this->idOfKey[$k] = $ch;
            $this->vkeys[] = $k;
            $this->keyOfId[$ch] = $k;
        }
    }

    public function idOfKey(string $key): int
    {
        $this->loadVertices();

        return $this->idOfKey[$key] ?? throw new RuntimeException("{$this->path} has no vertex " . Key::show($key));
    }

    public function keyOfId(int $id): string
    {
        $this->loadVertices();

        return $this->keyOfId[$id] ?? throw new RuntimeException("{$this->path} has no node $id");
    }

    public function keyOfVertex(int $v): string
    {
        $this->loadVertices();

        return $this->vkeys[$v] ?? throw new RuntimeException("{$this->path} has no vertex $v");
    }

    /** @return array{keys:list<string>,ch:list<int>,flags:list<int>,cross:list<array{idx:int,kind:int,other:string,tile:int,w:int,t:int,d:int}>} */
    public function border(): array
    {
        return $this->border ??= Exports::border($this->section(5));
    }

    /** @return array{hash:string,first:list<int>,arcs:list<int>} the arcs among the uncontracted vertices, by their rank (= node id) */
    public function core(): array
    {
        return $this->core ??= Exports::core($this->section(6));
    }

    /** @return array{int,int,int,int}|null weight, duration, distance, middle of the core arc between two uncontracted nodes */
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

    /** @return array{kind:int,src:int,dst:int} original arc `$i`: where it comes from, between which vertices (in key order) */
    public function orig(int $i): array
    {
        $u = Bin::u32s($this->read($this->sec[4][0] + $i * 12, 12), 3);

        return ['kind' => $u[0], 'src' => $u[1], 'dst' => $u[2]];
    }
}
