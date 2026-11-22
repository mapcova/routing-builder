<?php

declare(strict_types=1);

namespace MapCova\GraphBuilder\Tests;

use MapCova\GraphBuilder\Build\Builder;
use MapCova\GraphBuilder\Build\Options;
use MapCova\GraphBuilder\Support\Bin;
use MapCova\GraphBuilder\Tests\Support\Dijkstra;
use MapCova\GraphBuilder\Tests\Support\GridMap;
use MapCova\GraphBuilder\Tests\Support\McrFile;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class BuilderTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/mcr-build-' . bin2hex(random_bytes(4));
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

    private function options(string $profile, string $tag, float $slice = 600.0, bool $keep = false): Options
    {
        return new Options(
            input: $this->dir . '/town.osm.pbf',
            output: $this->dir . "/$tag.mcr",
            workDir: $this->dir . "/$tag.work",
            zone: 'town',
            profile: $profile,
            slice: $slice,
            builtAt: 1700000000,
            dumpArcs: $this->dir . "/$tag.arcs",
            keepWork: $keep,
        );
    }

    /** @return list<string> */
    public static function profiles(): array
    {
        return ['car', 'car-notoll', 'bike', 'foot'];
    }

    public function testBuildsAFileWhoseHierarchyIsExact(): void
    {
        foreach (self::profiles() as $profile) {
            $b = new Builder($this->options($profile, $profile, 600.0, true));
            $r = $b->runSlice();
            self::assertTrue($r->done, $profile);
            self::assertTrue($b->isDone());
            $m = new McrFile($this->dir . "/$profile.mcr");
            self::assertGreaterThan(40, $m->nodes, $profile);
            self::assertGreaterThan(40, $m->segments);
            $meta = json_decode($m->section('meta'), true);
            self::assertIsArray($meta);
            self::assertSame($profile, $meta['profile']);
            self::assertSame('town', $meta['zone']);
            self::assertSame(1700000000, $meta['built_at']);
            self::assertSame(1700000000, $meta['osm_timestamp']);
            self::assertSame(in_array($profile, ['car', 'car-notoll'], true) ? 'edge' : 'node', $meta['graph']);
            // every section starts on a 4096-byte boundary
            foreach ($m->sections as [$off]) {
                self::assertSame(0, $off % 4096);
            }
            $ref = new Dijkstra($this->dir . "/$profile.work/arcs.bin");
            // graph nodes in the arc file are numbered before the contraction; the file numbers them by rank
            $order = Bin::allU32((string) file_get_contents($this->dir . "/$profile.work/order.bin"));
            $nn = \count($order);
            $newId = [];
            foreach ($order as $rank => $old) {
                $newId[$old] = $nn - 1 - $rank;
            }
            mt_srand(5);
            for ($i = 0; $i < 150; $i++) {
                $from = mt_rand(0, $nn - 1);
                $to = mt_rand(0, $nn - 1);
                $q = $m->query($newId[$from], $newId[$to]);
                self::assertSame($ref->weight($from, $to), $q[0] ?? null, "$profile $from -> $to");
            }
            self::assertFileExists($this->dir . "/$profile.arcs");
        }
    }

    public function testViaWayRestrictionMakesCopiesInTheCarGraph(): void
    {
        (new Builder($this->options('car', 'c')))->runSlice();
        $m = new McrFile($this->dir . '/c.mcr');
        self::assertGreaterThan(0, strlen($m->section('shadows')));
        (new Builder($this->options('bike', 'b')))->runSlice();
        self::assertSame(0, strlen((new McrFile($this->dir . '/b.mcr'))->section('shadows')));
    }

    public function testResumedBuildIsByteIdentical(): void
    {
        foreach (['car', 'bike'] as $profile) {
            (new Builder($this->options($profile, "one-$profile")))->runSlice();
            $slices = 0;
            do {
                $b = new Builder($this->options($profile, "many-$profile", 0.0));
                $r = $b->runSlice();
                ++$slices;
                self::assertLessThan(5000, $slices);
            } while (!$r->done);
            self::assertGreaterThan(8, $slices, 'the contraction must have been resumed');
            self::assertFileEquals($this->dir . "/one-$profile.mcr", $this->dir . "/many-$profile.mcr");
            self::assertFileEquals($this->dir . "/one-$profile.arcs", $this->dir . "/many-$profile.arcs");
            self::assertFileExists($this->dir . "/many-$profile.mcr.slices.json");
        }
    }

    public function testAKilledSliceLeavesAWorkDirectoryThatResumes(): void
    {
        // a stale tail in the way file, as a process killed between two checkpoints leaves it
        (new Builder($this->options('car', 'ref')))->runSlice();
        $o = $this->options('car', 'killed', 0.0, true);
        $b = new Builder($o);
        $b->runSlice(); // ways, one block at a time
        file_put_contents($o->workDir . '/ways.bin', 'garbage that was written after the last checkpoint', FILE_APPEND);
        do {
            $r = (new Builder($o))->runSlice();
        } while (!$r->done);
        self::assertFileEquals($this->dir . '/ref.mcr', $this->dir . '/killed.mcr');
    }

    public function testRefusesAWorkDirectoryOfAnotherBuild(): void
    {
        $o = $this->options('car', 'x', 0.0, true);
        (new Builder($o))->runSlice();
        $other = new Options($o->input, $o->output, $o->workDir, 'town', 'bike', 0.0);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('belongs to another build');
        (new Builder($other))->runSlice();
    }

    public function testAnExtractWithoutUsableWaysIsAnError(): void
    {
        $w = new \MapCova\GraphBuilder\Tests\Support\PbfWriter();
        $w->node(1, 49.6, 6.1)->node(2, 49.61, 6.1)->way(1, [1, 2], ['highway' => 'footway']);
        $w->write($this->dir . '/walk.osm.pbf');
        $o = new Options($this->dir . '/walk.osm.pbf', $this->dir . '/walk.mcr', $this->dir . '/walk.work', 'town', 'car', 600.0);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no way the car profile can use');
        (new Builder($o))->runSlice();
    }

    public function testRejectsABadProfile(): void
    {
        $this->expectException(RuntimeException::class);
        new Builder(new Options('a', 'b', $this->dir . '/w', 'z', 'boat'));
    }

    public function testRejectsABadZone(): void
    {
        $this->expectException(RuntimeException::class);
        new Builder(new Options('a', 'b', $this->dir . '/w2', 'Z!', 'car'));
    }
}
