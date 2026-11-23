<?php

declare(strict_types=1);

namespace MapCova\GraphBuilder\Tests\Support;

use MapCova\GraphBuilder\Link\LinkBuilder;
use MapCova\GraphBuilder\Link\LinkOptions;
use MapCova\GraphBuilder\Link\TransitBuilder;
use MapCova\GraphBuilder\Tiles\TileSet;

/** The builds of links and transit files over the tiles of `TilesFixture`. */
trait LinksFixture
{
    private function buildLink(string $tiling, string $id, float $slice = 600.0, int $remainder = 30): int
    {
        $slices = 0;
        do {
            $b = new LinkBuilder(new LinkOptions(TileSet::load($tiling), $id, $this->dir, $this->dir . "/$id.mco", $this->dir . "/$id.work", $slice, $remainder, 1700000000, 0, 'car', 20));
            $r = $b->runSlice();
            ++$slices;
            self::assertLessThan(3000, $slices);
        } while (!$r->done);

        return $slices;
    }

    /** @param list<string> $tiles */
    private function buildAll(string $tiling, array $tiles): void
    {
        foreach ($tiles as $t) {
            $this->buildTile($tiling, $t);
            TransitBuilder::build($this->dir . "/$t.mcr", $this->dir . "/$t.mct");
        }
        $ts = TileSet::load($tiling);
        foreach ($ts->links() as $l) {
            $this->buildLink($tiling, $l);
        }
    }
}
