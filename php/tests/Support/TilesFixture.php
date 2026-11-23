<?php

declare(strict_types=1);

namespace MapCova\GraphBuilder\Tests\Support;

use MapCova\GraphBuilder\Build\Builder;
use MapCova\GraphBuilder\Build\Options;

/** The small town cut into tiles, and the builds of tiles, links and transit files over it. */
trait TilesFixture
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/mcr-tiles-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        GridMap::write($this->dir . '/town.osm.pbf');
    }

    protected function tearDown(): void
    {
        $this->rm($this->dir);
    }

    private function rm(string $d): void
    {
        foreach (glob($d . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
            if (basename($f) === '.' || basename($f) === '..') {
                continue;
            }
            is_dir($f) ? $this->rm($f) : unlink($f);
        }
        rmdir($d);
    }

    /**
     * The town cut by vertical lines; `$tree` is the tree of the tiling.
     *
     * @param list<float> $cuts longitudes of the cuts, west to east
     * @param array<string,mixed> $tree
     */
    private function tiling(array $cuts, array $tree): string
    {
        $edges = array_merge([6.0], $cuts, [6.3]);
        $tiles = [];
        foreach (array_keys($edges) as $i) {
            if ($i === \count($edges) - 1) {
                break;
            }
            $name = 't' . $i;
            file_put_contents("{$this->dir}/$name.poly", "$name\n1\n   {$edges[$i]} 49.5\n   {$edges[$i]} 49.7\n   {$edges[$i + 1]} 49.7\n   {$edges[$i + 1]} 49.5\n   {$edges[$i]} 49.5\nEND\nEND\n");
            $tiles[$name] = "$name.poly";
        }
        file_put_contents($this->dir . '/tiles.json', json_encode(['tiles' => $tiles, 'tree' => $tree]));

        return $this->dir . '/tiles.json';
    }

    private function buildTile(string $tiling, string $id, string $profile = 'car', ?string $input = null): void
    {
        $b = new Builder(new Options(
            input: $input ?? $this->dir . '/town.osm.pbf',
            output: $this->dir . "/$id.mcr",
            workDir: $this->dir . "/$id.work",
            zone: $id,
            profile: $profile,
            slice: 600.0,
            builtAt: 1700000000,
            tiles: $tiling,
            exportInterior: 20,
        ));
        self::assertTrue($b->runSlice()->done);
    }

    /** @return list<array{float,float,float,float}> */
    private function pairs(int $n, int $seed): array
    {
        mt_srand($seed);
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $out[] = [49.60 + mt_rand(0, 150) / 10000, 6.10 + mt_rand(0, 240) / 10000, 49.60 + mt_rand(0, 150) / 10000, 6.10 + mt_rand(0, 240) / 10000];
        }

        return $out;
    }
}
