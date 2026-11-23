<?php

declare(strict_types=1);

namespace MapCova\GraphBuilder\Tests;

use MapCova\GraphBuilder\Link\LinkBuilder;
use MapCova\GraphBuilder\Link\LinkOptions;
use MapCova\GraphBuilder\Mcr\Container;
use MapCova\GraphBuilder\Mcr\Exports;
use MapCova\GraphBuilder\Tests\Support\LinksFixture;
use MapCova\GraphBuilder\Tests\Support\TilesFixture;
use MapCova\GraphBuilder\Tiles\TileSet;
use PHPUnit\Framework\TestCase;

/** Links over the tiles of the small town: the cross arcs they need, when they are rebuilt, the resume. */
final class LinksBuildTest extends TestCase
{
    use TilesFixture;
    use LinksFixture;

    private function tile(string $id): Container
    {
        return new Container($this->dir . "/$id.mcr", Container::MAGIC_TILE);
    }

    public function testALinkStopsOnACrossArcWithoutATwin(): void
    {
        $tiling = $this->tiling([6.112], ['id' => 'top', 'children' => ['t0', 't1']]);
        $this->buildTile($tiling, 't0');
        $this->buildTile($tiling, 't1');
        $t1 = $this->dir . '/t1.mcr';
        $f = $this->tile('t1');
        $b = Exports::border($f->section(9));
        $in = array_keys(array_filter($b['cross'], static fn (array $x): bool => $x['kind'] === 1));
        self::assertNotEmpty($in);
        $raw = (string) file_get_contents($t1);
        // the first cross arc entering t1: the key of its other end now names a vertex that t0 does not have
        $off = $f->sec[9][0] + 16 + \count($b['keys']) * Exports::VERTEX_BYTES + $in[0] * Exports::CROSS_BYTES + 8;
        $raw = substr_replace($raw, "\xFF", $off, 1);
        file_put_contents($t1, $raw);
        try {
            $this->buildLink($tiling, 'top');
            self::fail('the link must not be built');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('has no twin', $e->getMessage());
        }
        self::assertFileDoesNotExist($this->dir . '/top.mco');
    }

    public function testALinkStopsOnACrossArcThatOnlyOneTileHas(): void
    {
        $tiling = $this->tiling([6.112], ['id' => 'top', 'children' => ['t0', 't1']]);
        $this->buildTile($tiling, 't0');
        $this->buildTile($tiling, 't1');
        $t1 = $this->dir . '/t1.mcr';
        $f = $this->tile('t1');
        $b = Exports::border($f->section(9));
        $in = array_keys(array_filter($b['cross'], static fn (array $x): bool => $x['kind'] === 1));
        $t0 = Exports::border($this->tile('t0')->section(9));
        // the other end of the first cross arc entering t1 becomes another vertex of t0, one that exists but is not that arc's
        $x = $b['cross'][$in[0]];
        $other = null;
        foreach ($t0['keys'] as $k) {
            if ($k !== $x['other']) {
                $other = $k;
                break;
            }
        }
        self::assertNotNull($other);
        $off = $f->sec[9][0] + 16 + \count($b['keys']) * Exports::VERTEX_BYTES + $in[0] * Exports::CROSS_BYTES + 8;
        file_put_contents($t1, substr_replace((string) file_get_contents($t1), $other, $off, 28));
        try {
            $this->buildLink($tiling, 'top');
            self::fail('the link must not be built');
        } catch (\RuntimeException $e) {
            self::assertMatchesRegularExpression('/one tile only|differently/', $e->getMessage());
        }
    }

    public function testALinkIsNotRebuiltWhenATileKeepsItsCore(): void
    {
        $tiling = $this->tiling([6.112], ['id' => 'top', 'children' => ['t0', 't1']]);
        $this->buildTile($tiling, 't0');
        $this->buildTile($tiling, 't1');
        $this->buildLink($tiling, 'top');
        $options = new LinkOptions(TileSet::load($tiling), 'top', $this->dir, $this->dir . '/top.mco', $this->dir . '/top.work', 600.0, 30, 1700000000, 0, 'car', 20);
        self::assertTrue((new LinkBuilder($options))->isDone());
        // the tile is built again from another copy of the extract: its file may differ, its core hash does not
        copy($this->dir . '/town.osm.pbf', $this->dir . '/town2.osm.pbf');
        touch($this->dir . '/town2.osm.pbf', time() + 100);
        $this->rm($this->dir . '/t1.work');
        unlink($this->dir . '/t1.mcr');
        $this->buildTile($tiling, 't1', 'car', $this->dir . '/town2.osm.pbf');
        self::assertTrue((new LinkBuilder($options))->isDone(), 'the link stays valid: no input moved');
        // a different link over the same tiles is another build
        self::assertFalse((new LinkBuilder(new LinkOptions(TileSet::load($tiling), 'top', $this->dir, $this->dir . '/other.mco', $this->dir . '/other.work', 600.0, 30, 1700000000, 0, 'car', 20)))->isDone());
    }

    public function testALinkBuiltInSlicesIsTheSameFile(): void
    {
        $tiling = $this->tiling([6.106, 6.114], ['id' => 'top', 'children' => [['id' => 'l1', 'children' => ['t0', 't1']], 't2']]);
        foreach (['t0', 't1', 't2'] as $t) {
            $this->buildTile($tiling, $t);
        }
        $this->buildLink($tiling, 'l1');
        copy($this->dir . '/l1.mco', $this->dir . '/l1-one.mco');
        $this->rm($this->dir . '/l1.work');
        unlink($this->dir . '/l1.mco');
        $slices = $this->buildLink($tiling, 'l1', 0.0);
        self::assertGreaterThan(3, $slices, 'the contraction must have been resumed');
        self::assertFileEquals($this->dir . '/l1-one.mco', $this->dir . '/l1.mco');
    }
}
