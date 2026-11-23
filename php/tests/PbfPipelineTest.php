<?php

declare(strict_types=1);

namespace MapCova\GraphBuilder\Tests;

use MapCova\GraphBuilder\Osm\NeededIds;
use MapCova\GraphBuilder\Osm\NodesPass;
use MapCova\GraphBuilder\Osm\WaysPass;
use MapCova\GraphBuilder\Pbf\Block;
use MapCova\GraphBuilder\Pbf\PbfReader;
use MapCova\GraphBuilder\Profile\Profile;
use MapCova\GraphBuilder\Tests\Support\PbfWriter;
use PHPUnit\Framework\TestCase;

final class PbfPipelineTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/mcr-test-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            unlink($f);
        }
        rmdir($this->dir);
    }

    private function sample(): string
    {
        $w = new PbfWriter();
        $w->node(10, 49.6, 6.1, ['highway' => 'traffic_signals'])
            ->node(11, 49.601, 6.101)
            ->node(12, 49.602, 6.102, ['barrier' => 'bollard'])
            ->node(99, -33.5, -70.25) // far away, in no way
            ->node(13, 49.603, 6.103);
        $w->way(100, [10, 11, 12], ['highway' => 'residential', 'name' => 'Rue']);
        $w->way(101, [12, 13], ['highway' => 'motorway', 'oneway' => 'yes']);
        $w->way(102, [11, 13], ['building' => 'yes']);
        $w->relation(500, ['type' => 'restriction', 'restriction' => 'no_left_turn'], [['from', 1, 100], ['via', 0, 12], ['to', 1, 101]]);
        $w->relation(501, ['type' => 'route', 'route' => 'bus'], [['', 1, 100]]);
        $path = $this->dir . '/sample.osm.pbf';
        $w->write($path, 1700000123);

        return $path;
    }

    public function testTimestampAndBlobs(): void
    {
        $p = $this->sample();
        self::assertSame(1700000123, PbfReader::timestamp($p));
        $types = [];
        foreach (PbfReader::blobs($p) as $b) {
            $types[] = $b['type'];
        }
        self::assertSame(['OSMHeader', 'OSMData', 'OSMData'], $types);
    }

    public function testWaysPassKeepsWhatTheProfileKeeps(): void
    {
        $p = $this->sample();
        $pass = new WaysPass(Profile::parse('car') ?? throw new \LogicException());
        $ways = 0;
        $restr = 0;
        $recs = '';
        foreach (PbfReader::blobs($p) as $b) {
            if ($b['type'] === 'OSMData') {
                $r = $pass->process(Block::decode($b['data']));
                $ways += $r['nWays'];
                $restr += $r['nRestrictions'];
                $recs .= $r['ways'];
            }
        }
        self::assertSame(2, $ways);
        self::assertSame(1, $restr);
        $u = unpack('Pid/Vn/gfs/gfr/gbs/gbr/Cflags', $recs);
        self::assertIsArray($u);
        self::assertSame(100, $u['id']);
        self::assertSame(3, $u['n']);
        self::assertSame(25.0, $u['fs']);
        // bike: no restrictions are read, the motorway is not a bike way
        $bike = new WaysPass(Profile::parse('bike') ?? throw new \LogicException());
        $n = 0;
        $rs = 0;
        foreach (PbfReader::blobs($p) as $b) {
            if ($b['type'] === 'OSMData') {
                $r = $bike->process(Block::decode($b['data']));
                $n += $r['nWays'];
                $rs += $r['nRestrictions'];
            }
        }
        self::assertSame(1, $n);
        self::assertSame(0, $rs);
    }

    public function testNeededIdsAreSortedAndDistinctAcrossRuns(): void
    {
        $w = fopen($this->dir . '/ways.bin', 'wb');
        self::assertIsResource($w);
        mt_srand(7);
        $all = [];
        for ($i = 0; $i < 40; $i++) {
            $refs = [];
            for ($k = 0; $k < 25; $k++) {
                $refs[] = mt_rand(1, 400);
            }
            $all = array_merge($all, $refs);
            fwrite($w, pack('PVggggCg', $i, 25, 1.0, 1.0, 1.0, 1.0, 2, 0.0) . pack('P*', ...$refs));
        }
        fclose($w);
        $count = NeededIds::build($this->dir . '/ways.bin', $this->dir . '/needed.bin', $this->dir, 64);
        $want = array_values(array_unique($all));
        sort($want);
        self::assertSame(\count($want), $count);
        $got = unpack('P*', (string) file_get_contents($this->dir . '/needed.bin'));
        self::assertSame($want, array_values((array) $got));
        self::assertSame([], glob($this->dir . '/run-*.bin'));
    }

    public function testUnsortedWaysAreRefused(): void
    {
        $w = fopen($this->dir . '/ways.bin', 'wb');
        self::assertIsResource($w);
        foreach ([5, 3] as $id) {
            fwrite($w, pack('PVggggCg', $id, 1, 1.0, 1.0, 1.0, 1.0, 2, 0.0) . pack('P', 1));
        }
        fclose($w);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not sorted by id');
        NeededIds::build($this->dir . '/ways.bin', $this->dir . '/needed.bin', $this->dir);
    }

    public function testNodesPassFindsOnlyNeededNodesWithFlagsAndCoordinates(): void
    {
        $p = $this->sample();
        $profile = Profile::parse('car') ?? throw new \LogicException();
        file_put_contents($this->dir . '/needed.bin', pack('P*', 10, 11, 12, 13, 77));
        $pass = new NodesPass($this->dir . '/needed.bin', $profile);
        $out = '';
        foreach (PbfReader::blobs($p) as $b) {
            if ($b['type'] === 'OSMData') {
                $block = Block::decode($b['data']);
                $out .= $pass->process($block);
            }
        }
        self::assertSame(4 * NodesPass::RECORD, \strlen($out));
        $u = unpack('Pid/Vlat/Vlon/Cflags', $out, 0);
        self::assertIsArray($u);
        self::assertSame(10, $u['id']);
        self::assertSame(49600000, $u['lat']);
        self::assertSame(6100000, $u['lon']);
        self::assertSame(Profile::NODE_SIGNALS, $u['flags']);
        $u = unpack('Pid/Vlat/Vlon/Cflags', $out, 2 * NodesPass::RECORD);
        self::assertIsArray($u);
        self::assertSame(Profile::NODE_BARRIER, $u['flags']);
    }

    public function testNegativeCoordinatesRoundHalfAwayFromZero(): void
    {
        $w = new PbfWriter();
        // decimicro -335000005 and 12345 -> 1e-6 degree: -33500001 (half away from zero) and 1235
        $w->node(5, -33.5000005, 0.0012345);
        $path = $this->dir . '/neg.osm.pbf';
        $w->write($path);
        file_put_contents($this->dir . '/needed.bin', pack('P', 5));
        $pass = new NodesPass($this->dir . '/needed.bin', Profile::parse('foot') ?? throw new \LogicException());
        $out = '';
        foreach (PbfReader::blobs($path) as $b) {
            if ($b['type'] === 'OSMData') {
                $out .= $pass->process(Block::decode($b['data']));
            }
        }
        $u = unpack('Pid/Vlat/Vlon/Cflags', $out);
        self::assertIsArray($u);
        self::assertSame(0x100000000 - 33500001, $u['lat']);
        self::assertSame(1235, $u['lon']);
    }

    public function testWaysThatAreNotSortedByIdFailAtOnce(): void
    {
        $w = new PbfWriter();
        $w->node(1, 49.6, 6.1)->node(2, 49.601, 6.1)
            ->way(5, [1, 2], ['highway' => 'residential'])
            ->way(3, [1, 2], ['highway' => 'residential']);
        $w->write($this->dir . '/unsorted.osm.pbf');
        $pass = new WaysPass(Profile::parse('car') ?? throw new \LogicException());
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not sorted by id (way 3 after 5)');
        foreach (PbfReader::blobs($this->dir . '/unsorted.osm.pbf') as $b) {
            if ($b['type'] === 'OSMData') {
                $pass->process(Block::decode($b['data']));
            }
        }
    }

    public function testTheLastWayIdSurvivesALaunchBoundary(): void
    {
        $w = new PbfWriter();
        $w->node(1, 49.6, 6.1)->node(2, 49.601, 6.1)->way(5, [1, 2], ['highway' => 'residential']);
        $w->write($this->dir . '/one.osm.pbf');
        $this->expectException(\RuntimeException::class);
        foreach (PbfReader::blobs($this->dir . '/one.osm.pbf') as $b) {
            if ($b['type'] === 'OSMData') {
                (new WaysPass(Profile::parse('car') ?? throw new \LogicException(), 9))->process(Block::decode($b['data']));
            }
        }
    }

    public function testAnAbsurdBlobHeaderIsRefusedBeforeItIsRead(): void
    {
        file_put_contents($this->dir . '/bad.pbf', pack('N', 70000) . str_repeat("\0", 100));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('a blob header of 70000 bytes');
        foreach (PbfReader::blobs($this->dir . '/bad.pbf') as $_) {
        }
    }

    public function testAnAbsurdBlobSizeIsRefused(): void
    {
        // header: type "OSMData" (field 1), datasize (field 3) = 64 MiB
        $h = "\x0a\x07OSMData" . "\x18" . "\x80\x80\x80\x20";
        file_put_contents($this->dir . '/bad.pbf', pack('N', \strlen($h)) . $h);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('over the limit');
        foreach (PbfReader::blobs($this->dir . '/bad.pbf') as $_) {
        }
    }

    public function testATruncatedBlobIsAnError(): void
    {
        $h = "\x0a\x07OSMData" . "\x18\x64";
        file_put_contents($this->dir . '/bad.pbf', pack('N', \strlen($h)) . $h . 'short');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('truncated');
        foreach (PbfReader::blobs($this->dir . '/bad.pbf') as $_) {
        }
    }
}
