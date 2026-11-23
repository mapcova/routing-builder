<?php

declare(strict_types=1);

namespace MapCova\GraphBuilder\Tests;

use MapCova\GraphBuilder\Mcr\Container;
use MapCova\GraphBuilder\Mcr\Exports;
use MapCova\GraphBuilder\Support\Bin;
use MapCova\GraphBuilder\Tests\Support\McrFile;
use MapCova\GraphBuilder\Tests\Support\TilesFixture;
use MapCova\GraphBuilder\Tiles\TileSet;
use PHPUnit\Framework\TestCase;

/** Tiles and links of the small town: the files, their sections, their hashes, the resume. */
final class TilesBuildTest extends TestCase
{
    use TilesFixture;

    private function tile(string $id): Container
    {
        return new Container($this->dir . "/$id.mcr", Container::MAGIC_TILE);
    }

    public function testATileKeepsVersion1AndAddsTheBorderAndTheCore(): void
    {
        $tiling = $this->tiling([6.112], ['id' => 'top', 'children' => ['t0', 't1']]);
        $this->buildTile($tiling, 't0');
        $this->buildTile($tiling, 't1');
        // a version 1 reader reads the tile: the nine sections are there and the hierarchy is exact
        $m = new McrFile($this->dir . '/t0.mcr');
        self::assertSame(1, Bin::u32($m->head, 4));
        self::assertGreaterThan(40, $m->nodes);
        $meta = json_decode($m->section('meta'), true);
        self::assertIsArray($meta);
        self::assertSame(2, $meta['format_level']);
        self::assertSame(1, $meta['format']);
        $f = $this->tile('t0');
        $b = Exports::border($f->section(9));
        self::assertGreaterThan(0, \count($b['keys']));
        $sorted = $b['keys'];
        sort($sorted, SORT_STRING);
        self::assertSame($sorted, $b['keys'], 'the border is sorted by key');
        self::assertSame($b['keys'], array_values(array_unique($b['keys'])));
        $core = Exports::core($f->section(10));
        self::assertCount(\count($b['keys']) + 1, $core['first']);
        self::assertSame(\count($core['arcs']), 5 * $core['first'][\count($core['first']) - 1]);
        self::assertIsArray($meta['tile']);
        self::assertSame(bin2hex($core['hash']), $meta['tile']['core_hash']);
        // a cross arc leaves one tile and enters the other, with the same cost
        $o = Exports::border($this->tile('t1')->section(9));
        $out = array_values(array_filter($b['cross'], static fn (array $x): bool => $x['kind'] === 0));
        $in = array_values(array_filter($o['cross'], static fn (array $x): bool => $x['kind'] === 1));
        self::assertNotEmpty($out);
        self::assertCount(\count($in), $out);
        $a = array_map(static fn (array $x): string => $b['keys'][$x['idx']] . $x['other'] . "$x[w]/$x[t]/$x[d]", $out);
        $c = array_map(static fn (array $x): string => $x['other'] . $o['keys'][$x['idx']] . "$x[w]/$x[t]/$x[d]", $in);
        sort($a);
        sort($c);
        self::assertSame($a, $c, 'the two tiles see the same cross arcs');
    }

    public function testATileExportsItsBorderAndTheKMostImportantInteriorVertices(): void
    {
        $tiling = $this->tiling([6.112], ['id' => 'top', 'children' => ['t0', 't1']]);
        $this->buildTile($tiling, 't0');
        $f = $this->tile('t0');
        $b = Exports::border($f->section(9));
        $core = Exports::core($f->section(10));
        $interior = array_keys(array_filter($b['flags'], static fn (int $x): bool => $x === Exports::INTERIOR));
        $border = array_keys(array_filter($b['flags'], static fn (int $x): bool => $x !== Exports::INTERIOR));
        self::assertCount(20, $interior, 'K = 20 interior vertices are exported');
        self::assertSame(\count($b['keys']), \count($interior) + \count($border));
        foreach ($border as $i) {
            self::assertContains($b['flags'][$i], [Exports::OUT, Exports::IN, Exports::OUT | Exports::IN]);
        }
        // the vertices with a cross arc are exactly the ones flagged as border
        $withCross = [];
        foreach ($b['cross'] as $x) {
            $withCross[$x['idx']] = true;
        }
        $keys = array_keys($withCross);
        sort($keys);
        self::assertSame($keys, $border);
        self::assertCount(\count($b['keys']), $b['ch']);
        // the exported vertices are the last ones contracted: they hold the lowest node ids of the file
        $ids = $b['ch'];
        sort($ids);
        self::assertSame(range(0, \count($ids) - 1), $ids);
        // the core is a graph over the exported vertices, and the hash covers it
        $h = Bin::ints('Vnv/Vna', $f->section(10));
        self::assertSame(\count($b['keys']), $h['nv']);
        self::assertSame(\count($core['arcs']), 5 * $h['na']);
        $meta = (new McrFile($this->dir . '/t0.mcr'))->section('meta');
        $m = json_decode($meta, true);
        self::assertIsArray($m);
        self::assertIsArray($m['tile']);
        self::assertSame(\count($b['keys']), $m['tile']['exported']);
        self::assertSame(\count($border), $m['tile']['border']);
    }

    public function testACoreHashIsKeptWhenNothingChanges(): void
    {
        $tiling = $this->tiling([6.112], ['id' => 'top', 'children' => ['t0', 't1']]);
        $this->buildTile($tiling, 't0');
        $hash = Exports::core($this->tile('t0')->section(10))['hash'];
        // the same tile built again from the same extract
        rename($this->dir . '/t0.mcr', $this->dir . '/first.mcr');
        $this->rm($this->dir . '/t0.work');
        $this->buildTile($tiling, 't0');
        self::assertSame(bin2hex($hash), bin2hex(Exports::core($this->tile('t0')->section(10))['hash']));
        self::assertFileEquals($this->dir . '/first.mcr', $this->dir . '/t0.mcr');
    }

    public function testTheTilingGivesEveryPointOneOwner(): void
    {
        $tiling = $this->tiling([6.112], ['id' => 'top', 'children' => ['t0', 't1']]);
        $ts = TileSet::load($tiling);
        self::assertSame(0, $ts->ownerOf(49_600_000, 6_050_000));
        self::assertSame(1, $ts->ownerOf(49_600_000, 6_200_000));
        // outside every polygon: the tile with the nearest bounding box
        self::assertSame(1, $ts->ownerOf(49_900_000, 6_400_000));
        self::assertSame(['id' => 'top', 'children' => ['t0', 't1']], ['id' => $ts->root, 'children' => $ts->childrenOf($ts->root)]);
        // 3 km in cells of 0.002 degree, the longitude count taken at 49.7 N (the tile's northern edge)
        self::assertSame([14, 21], $ts->marginCells(0, 3000.0));
        $fine = (array) json_decode((string) file_get_contents($tiling), true);
        $fine['cell'] = 1000;
        file_put_contents($tiling, json_encode($fine));
        self::assertSame([27, 42], TileSet::load($tiling)->marginCells(0, 3000.0));
        $rings = $ts->margin(0, 3, 3);
        self::assertNotEmpty($rings);
        // the extract of a tile holds the roads it owns outside every polygon too (the margin grows from those cells as well)
        $north = 0.0;
        foreach ($ts->margin(1, 0, 0) as $ring) {
            foreach ($ring as [$la]) {
                $north = max($north, $la);
            }
        }
        // 49.75 N is outside the polygons (49.5 to 49.7) and inside the raster: the tile 1 owns it, and its margin reaches it
        self::assertSame(1, $ts->ownerOf(49_750_000, 6_250_000));
        self::assertGreaterThan(49.75, $north);
        self::assertSame($ts->ownerOf(49_750_000, 6_250_000), $ts->ownerOf(49_750_001, 6_250_001), 'one owner per cell');
    }
}
