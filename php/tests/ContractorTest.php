<?php

declare(strict_types=1);

namespace MapCova\GraphBuilder\Tests;

use MapCova\GraphBuilder\Ch\Contractor;
use MapCova\GraphBuilder\Tests\Support\Dijkstra;
use MapCova\GraphBuilder\Support\Bin;
use MapCova\GraphBuilder\Support\IntMinHeap;
use PHPUnit\Framework\TestCase;

final class ContractorTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/mcr-ch-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            unlink($f);
        }
        rmdir($this->dir);
    }

    /** A random strongly connected-ish graph: duration = weight, distance = 2 * weight, parallel arcs and loops included. */
    private function graph(int $n, int $arcs, int $seed): string
    {
        mt_srand($seed);
        $raw = '';
        for ($i = 0; $i < $arcs; $i++) {
            $u = mt_rand(0, $n - 1);
            $v = mt_rand(0, $n - 1);
            $w = mt_rand(1, 50);
            $raw .= pack('V6', $u, $v, $w, $w, 2 * $w, 0xFFFFFFFF);
        }
        for ($i = 0; $i < $n; $i++) { // a ring so that most pairs are connected
            $w = mt_rand(1, 50);
            $raw .= pack('V6', $i, ($i + 1) % $n, $w, $w, 2 * $w, 0xFFFFFFFF);
        }
        file_put_contents($this->dir . '/arcs.bin', $raw);

        return $this->dir . '/arcs.bin';
    }

    /** @return array{Contractor,int} the contractor and the number of launches it took */
    private function contract(string $arcs, string $tag, float $slice): array
    {
        $paths = [$this->dir . "/$tag.up", $this->dir . "/$tag.idx", $this->dir . "/$tag.ord"];
        $n = (int) (strlen((string) file_get_contents($arcs)) / 24);
        $nodes = 0;
        foreach (str_split((string) file_get_contents($arcs), 24) as $r) {
            [$src, $dst] = Bin::u32s($r, 2);
            $nodes = max($nodes, $src + 1, $dst + 1);
        }
        self::assertGreaterThan(0, $n);
        $c = Contractor::fromArcs($nodes, $arcs, true, ...$paths);
        $launches = 0;
        while (true) {
            ++$launches;
            $done = $c->run(hrtime(true) + (int) ($slice * 1e9));
            if ($done) {
                break;
            }
            // what a new process would do: save, drop everything, restore
            $c->checkpointTo($this->dir . '/chk.state', 'key');
            $c = Contractor::restoreFrom($this->dir . '/chk.state', 'key', $nodes, ...$paths);
            self::assertLessThan(100000, $launches);
        }

        return [$c, $launches];
    }

    /** @return array<int,list<array{int,int,int,int}>> upward arcs by node: target, weight, duration, distance */
    private function upArcs(string $tag, bool $fwd): array
    {
        $raw = (string) file_get_contents($this->dir . "/$tag.up");
        $idx = Bin::allU64((string) file_get_contents($this->dir . "/$tag.idx"));
        $out = [];
        foreach ($idx as $r => $off) {
            $end = $idx[$r + 1] ?? strlen($raw);
            $u = Bin::allU32(substr($raw, $off, $end - $off));
            [$x, $nf, $nb] = $u;
            for ($k = 0; $k < $nf + $nb; $k++) {
                $rec = \array_slice($u, 3 + 5 * $k, 5);
                if (($k < $nf) === $fwd) {
                    $out[$x][] = [$rec[0], $rec[1], $rec[3], $rec[4]];
                }
            }
        }

        return $out;
    }

    /**
     * @param array<int,list<array{int,int,int,int}>> $up
     * @return array<int,array{int,int,int}>
     */
    private function search(array $up, int $start): array
    {
        $best = [$start => [0, 0, 0]];
        $h = new IntMinHeap();
        $h->insert($start);
        $closed = [];
        while (!$h->isEmpty()) {
            $k = $h->extract();
            $u = $k & 0xFFFFFF;
            if (isset($closed[$u])) {
                continue;
            }
            $closed[$u] = true;
            foreach ($up[$u] ?? [] as [$v, $w, $t, $d]) {
                $nw = $best[$u][0] + $w;
                if (!isset($best[$v]) || $nw < $best[$v][0]) {
                    $best[$v] = [$nw, $best[$u][1] + $t, $best[$u][2] + $d];
                    $h->insert(($nw << 24) | $v);
                }
            }
        }

        return $best;
    }

    public function testHierarchyGivesTheShortestDistancesAndCarriesDurationAndDistance(): void
    {
        $arcs = $this->graph(60, 140, 3);
        [$c] = $this->contract($arcs, 'a', 600.0);
        self::assertSame(60, $c->rank());
        $fwd = $this->upArcs('a', true);
        $bwd = $this->upArcs('a', false);
        $ref = new Dijkstra($arcs);
        for ($from = 0; $from < 60; $from += 3) {
            $f = $this->search($fwd, $from);
            for ($to = 0; $to < 60; $to += 2) {
                $b = $this->search($bwd, $to);
                $best = null;
                foreach ($b as $v => $lb) {
                    if (isset($f[$v]) && ($best === null || $f[$v][0] + $lb[0] < $best[0])) {
                        $best = [$f[$v][0] + $lb[0], $f[$v][1] + $lb[1], $f[$v][2] + $lb[2]];
                    }
                }
                $want = $ref->weight($from, $to);
                self::assertSame($want, $best[0] ?? null, "$from -> $to");
                if ($best !== null) {
                    // every arc has duration = weight and distance = 2 * weight, so the shortcuts must keep that
                    self::assertSame($best[0], $best[1], "$from -> $to duration");
                    self::assertSame(2 * $best[0], $best[2], "$from -> $to distance");
                }
            }
        }
    }

    public function testResumedContractionGivesTheSameHierarchy(): void
    {
        $arcs = $this->graph(80, 200, 11);
        [, $oneGo] = $this->contract($arcs, 'one', 600.0);
        [, $many] = $this->contract($arcs, 'many', 0.0);
        self::assertSame(1, $oneGo);
        self::assertGreaterThan(80, $many);
        foreach (['up', 'idx', 'ord'] as $ext) {
            self::assertFileEquals($this->dir . "/one.$ext", $this->dir . "/many.$ext");
        }
    }

    /** A contractor over a graph big enough that its state weighs several MB, run for one step. */
    private function bigContractor(): Contractor
    {
        $arcs = $this->graph(60000, 120000, 11);
        $c = Contractor::fromArcs(60000, $arcs, true, $this->dir . '/b.up', $this->dir . '/b.idx', $this->dir . '/b.ord');
        self::assertFalse($c->run(0));

        return $c;
    }

    public function testACheckpointNeedsAboutAMegabyteNotACopyOfTheState(): void
    {
        $c = $this->bigContractor();
        gc_collect_cycles();
        $live = memory_get_usage();
        $limit = ini_get('memory_limit');
        // a hard limit just above what is in use: a serialize() of the whole state would not fit in it
        self::assertNotFalse(ini_set('memory_limit', (string) (memory_get_usage(true) + (6 << 20))));
        try {
            memory_reset_peak_usage();
            $c->checkpointTo($this->dir . '/big.state', 'key');
            $extra = memory_get_peak_usage() - $live;
        } finally {
            ini_set('memory_limit', (string) $limit);
        }
        $size = (int) filesize($this->dir . '/big.state');
        self::assertGreaterThan(6 << 20, $size, 'the state must be bigger than the room left');
        self::assertLessThan(3 << 20, $extra, "the checkpoint used $extra bytes on top of the state");
        self::assertSame([], glob($this->dir . '/big.state.tmp.*'));
    }

    public function testARestoreNeedsTheStateAndAboutAMegabyte(): void
    {
        $c = $this->bigContractor();
        $c->checkpointTo($this->dir . '/big.state', 'key');
        $c = null;
        gc_collect_cycles();
        $paths = [$this->dir . '/b.up', $this->dir . '/b.idx', $this->dir . '/b.ord'];
        $before = memory_get_usage();
        memory_reset_peak_usage();
        $r = Contractor::restoreFrom($this->dir . '/big.state', 'key', 60000, ...$paths);
        $kept = memory_get_usage() - $before;
        $transient = memory_get_peak_usage() - $before - $kept;
        self::assertGreaterThan(6 << 20, $kept);
        self::assertLessThan(3 << 20, $transient, "the restore needed $transient bytes beyond the state it rebuilt");
        self::assertSame(60000, $r->nodes());
    }

    public function testTheContractionStopsEarlyWhenTheMemoryPassesItsShare(): void
    {
        $arcs = $this->graph(300, 600, 3);
        $paths = [$this->dir . '/e.up', $this->dir . '/e.idx', $this->dir . '/e.ord'];
        $c = Contractor::fromArcs(300, $arcs, true, ...$paths);
        $c->setMemoryLimit(10, 5, -(1 << 30)); // 40 % of 10 bytes: always above, whatever the growth; five contractions first
        $launches = 0;
        $last = -1;
        do {
            self::assertLessThan(2000, ++$launches);
            // the initial priorities first (one node per call here), the contraction after, in steps of 64
            $done = $c->run(hrtime(true) + 60_000_000_000);
            self::assertGreaterThanOrEqual($last, $c->rank());
            $last = $c->rank();
            if (!$done) {
                $c->checkpointTo($this->dir . '/e.state', 'k');
                $c = Contractor::restoreFrom($this->dir . '/e.state', 'k', 300, ...$paths);
                $c->setMemoryLimit(10, 5, -(1 << 30));
            }
        } while (!$done);
        self::assertGreaterThan(2, $launches, 'it must have stopped before the end');
        $ref = Contractor::fromArcs(300, $arcs, true, $this->dir . '/r.up', $this->dir . '/r.idx', $this->dir . '/r.ord');
        self::assertTrue($ref->run(hrtime(true) + 60_000_000_000));
        self::assertFileEquals($this->dir . '/r.up', $this->dir . '/e.up');
        self::assertFileEquals($this->dir . '/r.ord', $this->dir . '/e.ord');
    }

    public function testABigStateThatDoesNotGrowIsNotCheckpointedAgainAndAgain(): void
    {
        $arcs = $this->graph(300, 600, 3);
        $c = Contractor::fromArcs(300, $arcs, true, $this->dir . '/g.up', $this->dir . '/g.idx', $this->dir . '/g.ord');
        $c->setMemoryLimit(10, 5, 1 << 40); // over the cap from the start, but never 1 TB more than at the start
        $launches = 0;
        do {
            self::assertLessThan(2000, ++$launches);
            $done = $c->run(hrtime(true) + 60_000_000_000);
        } while (!$done);
        self::assertSame(1, $launches);
    }

    public function testATruncatedOrDamagedCheckpointIsRefused(): void
    {
        $arcs = $this->graph(200, 400, 5);
        $paths = [$this->dir . '/t.up', $this->dir . '/t.idx', $this->dir . '/t.ord'];
        $c = Contractor::fromArcs(200, $arcs, true, ...$paths);
        $c->run(0);
        $c->checkpointTo($this->dir . '/t.state', 'k');
        $whole = (string) file_get_contents($this->dir . '/t.state');
        foreach ([substr($whole, 0, -1), substr($whole, 0, 100), 'garbage', $whole . 'x'] as $i => $bad) {
            file_put_contents($this->dir . '/bad.state', $bad);
            try {
                Contractor::restoreFrom($this->dir . '/bad.state', 'k', 200, ...$paths);
                self::fail("damaged checkpoint $i accepted");
            } catch (\RuntimeException) {
                self::addToAssertionCount(1);
            }
        }
        self::assertSame(200, Contractor::restoreFrom($this->dir . '/t.state', 'k', 200, ...$paths)->nodes());
    }

    public function testTooManyNodesForTheHeapKeyAreRefused(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cut the zone smaller');
        Contractor::fromArcs((1 << 25) + 1, $this->dir . '/none', true, $this->dir . '/x.up', $this->dir . '/x.idx', $this->dir . '/x.ord');
    }

    public function testAShortcutThatDoesNotFitIn32BitsIsAnError(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('too long to be stored in 32 bits');
        $arcs = new \MapCova\GraphBuilder\Net\Arcs();
        $arcs->add(0, 1, 0xFFFFFFFF + 1, 1, 1, 0);
    }
}
