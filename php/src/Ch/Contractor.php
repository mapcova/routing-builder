<?php

declare(strict_types=1);

namespace MapCova\GraphBuilder\Ch;

use MapCova\GraphBuilder\Support\Bin;
use MapCova\GraphBuilder\Support\IntMinHeap;
use RuntimeException;

/**
 * Contraction Hierarchies in pure PHP, resumable: the algorithm and parameters of `fast_paths` (priority
 * `1000 * (0.1 * level + (shortcuts + 1) / (arcs + 1))`, witness searches limited to 500 / 100 / 500 settled nodes),
 * with duration and distance carried by every arc and shortcut (the sum of the two halves, as `Ctx::td` in `ch.rs`).
 *
 * Memory layout ("ch4" of the design study): per node two binary strings, the arcs leaving it (12 bytes: target,
 * weight, middle) and the arcs entering it (8 bytes: source, weight), plus a string of 8-byte records (duration,
 * distance) parallel to the first. A heap holds `priority << 25 | node`, keys are unique, so the order of the
 * contraction is deterministic and a resumed run gives the same hierarchy as an uninterrupted one.
 *
 * For each contracted node, in contraction order, a record goes to `up.bin`: node u32, forward count u32,
 * backward count u32, then the upward arcs as five u32 (other node, weight, middle, duration, distance), the
 * forward arcs first. `middle` has bit 31 set for an original arc, else it is the node a shortcut goes over.
 * `upidx.bin` holds the offset of each record (u64), `order.bin` the contracted node (u32).
 */
final class Contractor
{
    public const ORIGINAL = 0x80000000;
    private const NODE_BITS = 25;
    private const NODE_MASK = (1 << self::NODE_BITS) - 1;
    private const BUFFER = 1 << 20;

    /** @var array<int,string> */
    private array $out;
    /** @var array<int,string> */
    private array $in;
    /** @var array<int,string> */
    private array $td;
    /** @var array<int,int> */
    private array $prio;
    /** @var array<int,int> */
    private array $level;
    private string $alive;
    private int $rank = 0;
    private int $initNext = 0;
    public int $shortcuts = 0;
    private IntMinHeap $queue;

    // witness search
    /** @var array<int,int> */
    private array $dist = [];
    /** @var array<int,true> */
    private array $settled = [];
    private IntMinHeap $heap;
    private int $start = -1;
    private int $avoid = -1;
    private int $nSettled = 0;

    /** @var resource|null */
    private $up = null;
    /** @var resource|null */
    private $idx = null;
    /** @var resource|null */
    private $ord = null;
    private string $upBuf = '';
    private string $idxBuf = '';
    private string $ordBuf = '';
    private int $upSize = 0;
    private int $idxSize = 0;
    private int $ordSize = 0;

    private function __construct(
        private readonly int $n,
        private readonly string $upPath,
        private readonly string $idxPath,
        private readonly string $ordPath,
        private readonly int $initialSettled = 500,
        private readonly int $neighbourSettled = 100,
        private readonly int $contractionSettled = 500,
    ) {
        $this->out = array_fill(0, $n, '');
        $this->in = array_fill(0, $n, '');
        $this->td = array_fill(0, $n, '');
        $this->prio = array_fill(0, $n, 0);
        $this->level = array_fill(0, $n, 0);
        $this->alive = str_repeat("\1", $n);
        $this->queue = new IntMinHeap();
        $this->heap = new IntMinHeap();
    }

    /**
     * A contractor over `$n` nodes and the arcs of `$arcFile` (six u32 per arc: src, dst, weight, duration, distance,
     * road; `$edgeBased` arcs carry no road). Loops are dropped, of parallel arcs the cheapest is kept, as `ch.rs`.
     */
    public static function fromArcs(int $n, string $arcFile, bool $edgeBased, string $upPath, string $idxPath, string $ordPath): self
    {
        $c = new self($n, $upPath, $idxPath, $ordPath);
        $keys = [];
        $f = @fopen($arcFile, 'rb');
        if ($f === false) {
            throw new RuntimeException("cannot open $arcFile");
        }
        while (($raw = fread($f, 24 * 8192)) !== false && $raw !== '') {
            for ($o = 0, $e = \strlen($raw); $o + 24 <= $e; $o += 24) {
                [$src, $dst, $w, $t, $d, $sd] = Bin::u32s($raw, 6, $o);
                if ($src === $dst) {
                    continue;
                }
                // sorted by (src, dst, weight, duration): big-endian keys sort as numbers
                $keys[] = pack('NNNN', $src, $dst, $w, $t) . pack('NN', $d, $sd);
            }
        }
        fclose($f);
        sort($keys, SORT_STRING);
        $pu = -1;
        $pv = -1;
        foreach ($keys as $k) {
            $u = Bin::ints('Nsrc/Ndst/Nw/Nt/Nd/Nsd', $k);
            if ($u['src'] === $pu && $u['dst'] === $pv) {
                continue;
            }
            $pu = $u['src'];
            $pv = $u['dst'];
            $mid = $u['sd'] === 0xFFFFFFFF ? 0xFFFFFFFF : self::ORIGINAL | $u['sd'];
            $w = max($u['w'], 1);
            $c->out[$pu] .= pack('VVV', $pv, $w, $mid);
            $c->td[$pu] .= pack('VV', $u['t'], $u['d']);
            $c->in[$pv] .= pack('VV', $pu, $w);
        }

        return $c;
    }

    /** @return int number of contracted nodes so far */
    public function rank(): int
    {
        return $this->rank;
    }

    public function nodes(): int
    {
        return $this->n;
    }

    private static function recPos(string $s, string $needle, int $rec): int
    {
        $o = 0;
        while (($p = strpos($s, $needle, $o)) !== false) {
            if ($p % $rec === 0) {
                return $p;
            }
            $o = $p + 1;
        }

        return -1;
    }

    /** Adds the arc `$u -> $v` or lowers its weight (an equal one stays), as `PreparationGraph::add_or_reduce_edge`. */
    private function addOrReduce(int $u, int $v, int $w, int $c, int $t, int $d): void
    {
        $p = self::recPos($this->out[$u], pack('V', $v), 12);
        if ($p < 0) {
            $this->out[$u] .= pack('VVV', $v, $w, $c);
            $this->td[$u] .= pack('VV', $t, $d);
            $this->in[$v] .= pack('VV', $u, $w);

            return;
        }
        if (Bin::u32($this->out[$u], $p + 4) <= $w) {
            return;
        }
        $this->out[$u] = substr_replace($this->out[$u], pack('VV', $w, $c), $p + 4, 8);
        $this->td[$u] = substr_replace($this->td[$u], pack('VV', $t, $d), intdiv($p, 12) * 8, 8);
        $q = self::recPos($this->in[$v], pack('V', $u), 8);
        if ($q >= 0) {
            $this->in[$v] = substr_replace($this->in[$v], pack('V', $w), $q + 4, 4);
        }
    }

    private function wInit(int $s, int $avoid): void
    {
        $this->dist = [$s => 0];
        $this->settled = [];
        $this->heap = new IntMinHeap();
        $this->heap->insert($s);
        $this->start = $s;
        $this->avoid = $avoid;
        $this->nSettled = 0;
    }

    /** Upper bound of the distance from the start to `$t` (see `WitnessSearch::find_max_weight`). */
    private function wFind(int $t, int $limit, int $maxSettled): int
    {
        if ($t === $this->start) {
            return 0;
        }
        $d = $this->dist[$t] ?? null;
        if ($d !== null && (isset($this->settled[$t]) || $d <= $limit)) {
            return $d;
        }
        $h = $this->heap;
        $dist = &$this->dist;
        $settled = &$this->settled;
        $out = &$this->out;
        $avoid = $this->avoid;
        while (!$h->isEmpty()) {
            if ($this->nSettled >= $maxSettled) {
                break;
            }
            $top = $h->top();
            $cw = $top >> self::NODE_BITS;
            if ($cw > $limit) {
                break;
            }
            $h->extract();
            $cn = $top & self::NODE_MASK;
            if (isset($settled[$cn])) {
                continue;
            }
            $found = false;
            $b = $out[$cn];
            if ($b !== '') {
                $a = Bin::allU32($b);
                for ($j = 0, $m = \count($a); $j < $m; $j += 3) {
                    $adj = $a[$j];
                    if ($adj === $avoid) {
                        continue;
                    }
                    $nw = $cw + $a[$j + 1];
                    if ($nw < ($dist[$adj] ?? PHP_INT_MAX)) {
                        $dist[$adj] = $nw;
                        $h->insert(($nw << self::NODE_BITS) | $adj);
                        if ($adj === $t && $nw <= $limit) {
                            $found = true;
                        }
                    }
                }
            }
            $settled[$cn] = true;
            ++$this->nSettled;
            if ($found) {
                break;
            }
        }

        return $dist[$t] ?? PHP_INT_MAX;
    }

    /** Counts the shortcuts that contracting `$x` needs; with `$apply`, adds them (`handle_shortcuts`). */
    private function shortcutsOf(int $x, int $maxSettled, bool $apply): int
    {
        if ($this->out[$x] === '' || $this->in[$x] === '') {
            return 0;
        }
        $o = Bin::allU32($this->out[$x]);
        $i = Bin::allU32($this->in[$x]);
        $tdx = $apply ? Bin::allU32($this->td[$x]) : [];
        $mo = \count($o);
        $mi = \count($i);
        $count = 0;
        for ($k = 0; $k < $mi; $k += 2) {
            $u = $i[$k];
            $wu = $i[$k + 1];
            $this->wInit($u, $x);
            $tIn = null;
            $dIn = 0;
            for ($j = 0; $j < $mo; $j += 3) {
                $w = $wu + $o[$j + 1];
                if ($this->wFind($o[$j], $w, $maxSettled) <= $w) {
                    continue;
                }
                ++$count;
                if (!$apply) {
                    continue;
                }
                if ($tIn === null) {
                    $p = self::recPos($this->out[$u], pack('V', $x), 12);
                    [$tIn, $dIn] = Bin::u32s($this->td[$u], 2, intdiv($p, 12) * 8);
                }
                $q = intdiv($j, 3) * 2;
                $this->addOrReduce($u, $o[$j], $w, $x, $tIn + $tdx[$q], $dIn + $tdx[$q + 1]);
                ++$this->shortcuts;
            }
        }

        return $count;
    }

    private function relevance(int $x, int $level, int $maxSettled): int
    {
        $ns = $this->shortcutsOf($x, $maxSettled, false);
        $ne = intdiv(\strlen($this->out[$x]), 12) + (\strlen($this->in[$x]) >> 3);

        return (int) (1000 * (0.1 * $level + ($ns + 1.0) / ($ne + 1.0)));
    }

    private function openStreams(): void
    {
        foreach ([['up', $this->upPath, $this->upSize], ['idx', $this->idxPath, $this->idxSize], ['ord', $this->ordPath, $this->ordSize]] as [$name, $path, $size]) {
            clearstatcache(true, $path);
            if (!is_file($path)) {
                touch($path);
            }
            $f = fopen($path, 'r+b');
            if ($f === false) {
                throw new RuntimeException("cannot open $path");
            }
            if ($size < 0 || filesize($path) < $size) {
                throw new RuntimeException("$path is shorter than its checkpoint");
            }
            ftruncate($f, $size);
            clearstatcache(true, $path);
            fseek($f, $size);
            $this->$name = $f;
        }
    }

    private function flush(): void
    {
        foreach ([['up', 'upBuf', 'upSize'], ['idx', 'idxBuf', 'idxSize'], ['ord', 'ordBuf', 'ordSize']] as [$h, $b, $s]) {
            $f = $this->$h;
            if (\is_resource($f)) {
                fwrite($f, $this->$b);
                fflush($f);
                $this->$s += \strlen($this->$b);
                $this->$b = '';
            }
        }
    }

    private function rebuildQueue(): void
    {
        $this->queue = new IntMinHeap();
        for ($x = 0; $x < $this->initNext; $x++) {
            if ($this->alive[$x] === "\1") {
                $this->queue->insert(($this->prio[$x] << self::NODE_BITS) | $x);
            }
        }
    }

    /**
     * Contracts until every node is done (returns true) or `hrtime(true)` passes `$deadline` (returns false: save the
     * state with `checkpoint()`).
     */
    public function run(int $deadline): bool
    {
        $this->openStreams();
        $this->rebuildQueue();
        try {
            // each step is done before the clock is read: a call always makes progress, whatever its deadline
            while ($this->initNext < $this->n) {
                $x = $this->initNext;
                $p = $this->relevance($x, 0, $this->initialSettled);
                $this->prio[$x] = $p;
                $this->queue->insert(($p << self::NODE_BITS) | $x);
                ++$this->initNext;
                if (hrtime(true) > $deadline) {
                    return false;
                }
            }
            $q = $this->queue;
            while (!$q->isEmpty()) {
                $k = $q->extract();
                $x = $k & self::NODE_MASK;
                if ($this->alive[$x] === "\0" || ($k >> self::NODE_BITS) !== $this->prio[$x]) {
                    continue;
                }
                $this->contract($x);
                if (hrtime(true) > $deadline) {
                    return $q->isEmpty() && $this->rank >= $this->n;
                }
            }

            return true;
        } finally {
            $this->flush();
            foreach (['up', 'idx', 'ord'] as $h) {
                if (\is_resource($this->$h)) {
                    fclose($this->$h);
                }
                $this->$h = null;
            }
        }
    }

    private function contract(int $x): void
    {
        $this->alive[$x] = "\0";
        ++$this->rank;
        $this->shortcutsOf($x, $this->contractionSettled, true);
        $ob = $this->out[$x];
        $ib = $this->in[$x];
        $tdx = $this->td[$x];
        $nF = intdiv(\strlen($ob), 12);
        $nB = \strlen($ib) >> 3;
        $rec = pack('VVV', $x, $nF, $nB);
        $nb = [];
        $px = pack('V', $x);
        $o = Bin::allU32($ob);
        $t = Bin::allU32($tdx);
        for ($j = 0, $m = \count($o), $r = 0; $j < $m; $j += 3, $r += 2) {
            $v = $o[$j];
            $rec .= pack('VVVVV', $v, $o[$j + 1], $o[$j + 2], $t[$r], $t[$r + 1]);
            $p = self::recPos($this->in[$v], $px, 8);
            if ($p >= 0) {
                $this->in[$v] = substr_replace($this->in[$v], '', $p, 8);
            }
            $nb[$v] = true;
        }
        $i = Bin::allU32($ib);
        for ($j = 0, $m = \count($i); $j < $m; $j += 2) {
            $u = $i[$j];
            $p = self::recPos($this->out[$u], $px, 12);
            if ($p >= 0) {
                [$aw, $amid] = Bin::u32s($this->out[$u], 2, $p + 4);
                [$bt, $bd] = Bin::u32s($this->td[$u], 2, intdiv($p, 12) * 8);
                $rec .= pack('VVVVV', $u, $aw, $amid, $bt, $bd);
                $this->out[$u] = substr_replace($this->out[$u], '', $p, 12);
                $this->td[$u] = substr_replace($this->td[$u], '', intdiv($p, 12) * 8, 8);
            }
            $nb[$u] = true;
        }
        $this->upBuf .= $rec;
        $this->idxBuf .= pack('P', $this->upSize + \strlen($this->upBuf) - \strlen($rec));
        $this->ordBuf .= $px;
        if (\strlen($this->upBuf) > self::BUFFER) {
            $this->flush();
        }
        $this->out[$x] = '';
        $this->in[$x] = '';
        $this->td[$x] = '';
        $lx = $this->level[$x];
        foreach ($nb as $y => $_) {
            if ($this->alive[$y] === "\0") {
                continue;
            }
            if ($this->level[$y] < $lx + 1) {
                $this->level[$y] = $lx + 1;
            }
            $p = $this->relevance($y, $this->level[$y], $this->neighbourSettled);
            $this->prio[$y] = $p;
            $this->queue->insert(($p << self::NODE_BITS) | $y);
        }
    }

    /** The state to resume from (call after `run()` returned): output files are flushed, their sizes recorded. */
    public function checkpoint(): string
    {
        return serialize([
            'n' => $this->n,
            'out' => $this->out,
            'in' => $this->in,
            'td' => $this->td,
            'prio' => $this->prio,
            'level' => $this->level,
            'alive' => $this->alive,
            'rank' => $this->rank,
            'initNext' => $this->initNext,
            'shortcuts' => $this->shortcuts,
            'upSize' => $this->upSize,
            'idxSize' => $this->idxSize,
            'ordSize' => $this->ordSize,
        ]);
    }

    /** @return array<int,string> */
    private static function strMap(mixed $v): array
    {
        if (!\is_array($v)) {
            throw new RuntimeException('corrupt contraction state');
        }
        $out = [];
        foreach ($v as $k => $x) {
            if (!\is_int($k) || !\is_string($x)) {
                throw new RuntimeException('corrupt contraction state');
            }
            $out[$k] = $x;
        }

        return $out;
    }

    /** @return array<int,int> */
    private static function intMap(mixed $v): array
    {
        if (!\is_array($v)) {
            throw new RuntimeException('corrupt contraction state');
        }
        $out = [];
        foreach ($v as $k => $x) {
            if (!\is_int($k) || !\is_int($x)) {
                throw new RuntimeException('corrupt contraction state');
            }
            $out[$k] = $x;
        }

        return $out;
    }

    private static function intOf(mixed $v): int
    {
        if (!\is_int($v)) {
            throw new RuntimeException('corrupt contraction state');
        }

        return $v;
    }

    public static function restore(string $state, string $upPath, string $idxPath, string $ordPath): self
    {
        $s = unserialize($state, ['allowed_classes' => false]);
        if (!\is_array($s) || !isset($s['alive']) || !\is_string($s['alive'])) {
            throw new RuntimeException('corrupt contraction state');
        }
        $c = new self(self::intOf($s['n'] ?? null), $upPath, $idxPath, $ordPath);
        $c->out = self::strMap($s['out'] ?? null);
        $c->in = self::strMap($s['in'] ?? null);
        $c->td = self::strMap($s['td'] ?? null);
        $c->prio = self::intMap($s['prio'] ?? null);
        $c->level = self::intMap($s['level'] ?? null);
        $c->alive = $s['alive'];
        $c->rank = self::intOf($s['rank'] ?? null);
        $c->initNext = self::intOf($s['initNext'] ?? null);
        $c->shortcuts = self::intOf($s['shortcuts'] ?? null);
        $c->upSize = self::intOf($s['upSize'] ?? null);
        $c->idxSize = self::intOf($s['idxSize'] ?? null);
        $c->ordSize = self::intOf($s['ordSize'] ?? null);

        return $c;
    }
}
