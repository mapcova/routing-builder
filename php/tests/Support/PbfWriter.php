<?php

declare(strict_types=1);

namespace MapCova\GraphBuilder\Tests\Support;

/**
 * A minimal OSM PBF writer for tests: dense nodes, ways and restriction relations, zlib-compressed blobs, nodes
 * sorted by id (as the builder wants), one block per entity kind.
 */
final class PbfWriter
{
    /** @var array<int,array{int,int,array<string,string>}> id => [lat, lon in 1e-7 degree, tags] */
    private array $nodes = [];
    /** @var array<int,array{list<int>,array<string,string>}> */
    private array $ways = [];
    /** @var list<array{int,array<string,string>,list<array{string,int,int}>}> id, tags, members (role, type, id) */
    private array $relations = [];

    /** @param array<string,string> $tags */
    public function node(int $id, float $lat, float $lon, array $tags = []): self
    {
        $this->nodes[$id] = [(int) round($lat * 1e7), (int) round($lon * 1e7), $tags];

        return $this;
    }

    /**
     * @param list<int> $refs
     * @param array<string,string> $tags
     */
    public function way(int $id, array $refs, array $tags): self
    {
        $this->ways[$id] = [$refs, $tags];

        return $this;
    }

    /**
     * @param array<string,string> $tags
     * @param list<array{string,int,int}> $members role, type (0 node, 1 way), id
     */
    public function relation(int $id, array $tags, array $members): self
    {
        $this->relations[] = [$id, $tags, $members];

        return $this;
    }

    private static function varint(int $v): string
    {
        $o = '';
        while ($v >= 0x80) {
            $o .= \chr(($v & 0x7f) | 0x80);
            $v >>= 7;
        }

        return $o . \chr($v);
    }

    private static function zig(int $v): int
    {
        return ($v << 1) ^ ($v >> 63);
    }

    private static function field(int $no, int $wt, string $payload): string
    {
        return self::varint(($no << 3) | $wt) . $payload;
    }

    private static function bytes(int $no, string $b): string
    {
        return self::field($no, 2, self::varint(\strlen($b)) . $b);
    }

    private static function int(int $no, int $v): string
    {
        return self::field($no, 0, self::varint($v));
    }

    /** @param list<int> $vals */
    private static function packed(int $no, array $vals, bool $zigzag = false): string
    {
        $b = '';
        foreach ($vals as $v) {
            $b .= self::varint($zigzag ? self::zig($v) : $v);
        }

        return self::bytes($no, $b);
    }

    /**
     * @param list<int> $vals
     * @return list<int> deltas
     */
    private static function delta(array $vals): array
    {
        $out = [];
        $prev = 0;
        foreach ($vals as $v) {
            $out[] = $v - $prev;
            $prev = $v;
        }

        return $out;
    }

    private function blob(string $type, string $content): string
    {
        $z = (string) gzcompress($content);
        $blob = self::int(2, \strlen($content)) . self::bytes(3, $z);
        $header = self::bytes(1, $type) . self::int(3, \strlen($blob));

        return pack('N', \strlen($header)) . $header . $blob;
    }

    public function write(string $path, ?int $timestamp = 1700000000): void
    {
        $out = $this->blob('OSMHeader', $timestamp !== null ? self::int(32, $timestamp) : '');
        // dense nodes
        $table = [''];
        $idx = static function (string $s) use (&$table): int {
            $i = array_search($s, $table, true);
            if ($i === false) {
                $table[] = $s;
                $i = \count($table) - 1;
            }

            return (int) $i;
        };
        ksort($this->nodes);
        if ($this->nodes !== []) {
            $ids = [];
            $lats = [];
            $lons = [];
            $kv = [];
            foreach ($this->nodes as $id => [$la, $lo, $tags]) {
                $ids[] = $id;
                $lats[] = $la;
                $lons[] = $lo;
                foreach ($tags as $k => $v) {
                    $kv[] = $idx((string) $k);
                    $kv[] = $idx($v);
                }
                $kv[] = 0;
            }
            $dense = self::packed(1, self::delta($ids), true)
                . self::packed(8, self::delta($lats), true)
                . self::packed(9, self::delta($lons), true)
                . self::packed(10, $kv);
            // granularity 100 nanodegrees: decimicro = value, and the nano value is value * 100
            $out .= $this->blob('OSMData', $this->block($table, self::bytes(2, self::bytes(2, $dense))));
        }
        // ways and relations share a block
        $table = [''];
        $groups = '';
        $ways = '';
        foreach ($this->ways as $id => [$refs, $tags]) {
            $k = [];
            $v = [];
            foreach ($tags as $key => $val) {
                $k[] = $idx((string) $key);
                $v[] = $idx($val);
            }
            $ways .= self::bytes(3, self::int(1, $id) . self::packed(2, $k) . self::packed(3, $v) . self::packed(8, self::delta($refs), true));
        }
        $rels = '';
        foreach ($this->relations as [$id, $tags, $members]) {
            $k = [];
            $v = [];
            foreach ($tags as $key => $val) {
                $k[] = $idx((string) $key);
                $v[] = $idx($val);
            }
            $roles = [];
            $mem = [];
            $types = [];
            foreach ($members as [$role, $type, $mid]) {
                $roles[] = $idx($role);
                $mem[] = $mid;
                $types[] = $type;
            }
            $rels .= self::bytes(4, self::int(1, $id) . self::packed(2, $k) . self::packed(3, $v) . self::packed(8, $roles) . self::packed(9, self::delta($mem), true) . self::packed(10, $types));
        }
        if ($ways !== '' || $rels !== '') {
            $groups = ($ways !== '' ? self::bytes(2, $ways) : '') . ($rels !== '' ? self::bytes(2, $rels) : '');
            $out .= $this->blob('OSMData', $this->block($table, $groups));
        }
        file_put_contents($path, $out);
    }

    /** @param list<string> $table */
    private function block(array $table, string $groups): string
    {
        $st = '';
        foreach ($table as $s) {
            $st .= self::bytes(1, $s);
        }

        return self::bytes(1, $st) . $groups . self::int(17, 100);
    }
}
