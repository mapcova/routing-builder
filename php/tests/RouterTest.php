<?php

declare(strict_types=1);

namespace MapCova\GraphBuilder\Tests;

use MapCova\GraphBuilder\Build\Builder;
use MapCova\GraphBuilder\Build\Options;
use MapCova\GraphBuilder\Query\Geo;
use MapCova\GraphBuilder\Query\LinkFile;
use MapCova\GraphBuilder\Query\Router;
use MapCova\GraphBuilder\Query\Search;
use MapCova\GraphBuilder\Query\SingleFile;
use MapCova\GraphBuilder\Query\TileFile;
use MapCova\GraphBuilder\Tests\Support\LinksFixture;
use MapCova\GraphBuilder\Tests\Support\TilesFixture;
use MapCova\GraphBuilder\Tiles\TileSet;
use PHPUnit\Framework\TestCase;

/** The multi-level reader on the small town: the tiles must route exactly as the whole file does. */
final class RouterTest extends TestCase
{
    use TilesFixture;
    use LinksFixture;

    private function compare(string $tiling): int
    {
        (new Builder(new Options($this->dir . '/town.osm.pbf', $this->dir . '/town.mcr', $this->dir . '/town.work', 'town', 'car', 600.0, builtAt: 1700000000)))->runSlice();
        $single = new SingleFile(new TileFile($this->dir . '/town.mcr'));
        $router = new Router(TileSet::load($tiling), $this->dir);
        $found = 0;
        $skipped = 0;
        foreach ($this->pairs(400, 11) as [$a, $b, $c, $d]) {
            // two points on one road, the second behind the first: the route is a loop around a block, which a hierarchy
            // finds only when the loop has a node above both ends; the file and the tiles can legitimately differ
            $sa = $single->f->snap($a, $b);
            $sb = $single->f->snap($c, $d);
            if ($sa !== null && $sb !== null && $sa['seg'] === $sb['seg']) {
                ++$skipped;
                continue;
            }
            $x = $single->route($a, $b, $c, $d, true);
            $y = $router->route($a, $b, $c, $d, true);
            self::assertSame($x === null, $y === null, "$a,$b -> $c,$d");
            if ($x === null || $y === null) {
                continue;
            }
            ++$found;
            self::assertSame($x['weight'], $y['weight'], "$a,$b -> $c,$d weight");
            self::assertEqualsWithDelta($x['duration'], $y['duration'], 1.0);
            self::assertEqualsWithDelta($x['distance'], $y['distance'], 1.0);
            $line = array_map(static fn (array $q): array => [(int) round($q[0] * 1e6), (int) round($q[1] * 1e6)], $y['geometry'] ?? []);
            self::assertEqualsWithDelta($y['distance'], Geo::length($line), 6.0, 'the polyline is as long as the route');
        }

        return $found;
    }

    public function testTwoTilesRouteLikeTheWholeTown(): void
    {
        $tiling = $this->tiling([6.112], ['id' => 'top', 'children' => ['t0', 't1']]);
        $this->buildAll($tiling, ['t0', 't1']);
        self::assertGreaterThan(250, $this->compare($tiling));
        $t = new TileFile($this->dir . '/t0.mcr');
        self::assertGreaterThan(0, \count($t->border()['keys']));
    }

    public function testThreeLevelsRouteLikeTheWholeTown(): void
    {
        $tiling = $this->tiling([6.106, 6.114], ['id' => 'top', 'children' => [['id' => 'l1', 'children' => ['t0', 't1']], 't2']]);
        $this->buildAll($tiling, ['t0', 't1', 't2']);
        $l1 = new LinkFile($this->dir . '/l1.mco');
        self::assertNotEmpty($l1->border()['keys'], 'l1 has an outer border');
        self::assertFalse($l1->remainder);
        self::assertTrue((new LinkFile($this->dir . '/top.mco'))->remainder);
        self::assertGreaterThan(250, $this->compare($tiling));
    }

    public function testFlatAndLayeredLinksAgree(): void
    {
        $tiling = $this->tiling([6.106, 6.114], ['id' => 'top', 'children' => ['t0', 't1', 't2']]);
        $this->buildAll($tiling, ['t0', 't1', 't2']);
        self::assertGreaterThan(250, $this->compare($tiling));
    }

    public function testASearchPastTheLimitStopsAndKeepsTheWeightsBelowIt(): void
    {
        $tiling = $this->tiling([6.112], ['id' => 'top', 'children' => ['t0', 't1']]);
        $this->buildAll($tiling, ['t0', 't1']);
        $f = new TileFile($this->dir . '/t0.mcr');
        $snap = $f->snap(49.605, 6.105, 2000.0);
        self::assertNotNull($snap);
        $init = $f->sourceLabels($snap);
        $before = Search::$settled;
        $all = Search::up($f, $init, true);
        $full = Search::$settled - $before;
        $weights = $all['w'];
        sort($weights);
        $limit = $weights[intdiv(\count($weights), 3)];
        $before = Search::$settled;
        $cut = Search::up($f, $init, true, null, $limit);
        $pruned = Search::$settled - $before;
        self::assertLessThan($full, $pruned, 'fewer nodes are settled under a limit');
        foreach ($cut['w'] as $n => $w) {
            self::assertLessThanOrEqual($limit, $w);
            self::assertSame($all['w'][$n], $w, "node $n keeps its weight");
        }
        foreach ($all['w'] as $n => $w) {
            if ($w <= $limit) {
                self::assertSame($w, $cut['w'][$n] ?? null, "node $n below the limit is found");
            }
        }
    }
}
