<?php

declare(strict_types=1);

namespace MapCova\GraphBuilder\Ch;

use MapCova\GraphBuilder\Support\Bin;
use MapCova\GraphBuilder\Support\Io;
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
    private const CHECKPOINT_MAGIC = 'MCRCHK05';
    private const CHECKPOINT_END = 'MCRCHEND';
    /** Share of the memory limit above which `run()` stops early, to checkpoint while there is room to do it. */
    public const MEMORY_SHARE = 0.4;

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

    /** Nodes that wait (one byte each, 1 = frozen) until the interior is contracted; '' = none. */
    private string $frozen = '';
    /** Whether the frozen nodes are contracted too, once the snapshot is taken (a tile), or stay for good (a link). */
    private bool $release = false;
    /**
     * Interior nodes (not frozen) that are still there when the snapshot is taken: the most important ones in the order of
     * the contraction. A tile or a link exports them with its frozen nodes; the top link has no frozen node, and its rest is
     * searched by Dijkstra.
     */
    private int $remainder = 0;
    private bool $snapshotted = false;
    /** Next node to release (frozen nodes are released in node order, so a resumed run does the same). */
    private int $relNext = 0;
    private string $corePath = '';

    private int $memoryCap = 0;
    private int $minSteps = 1000;
    private int $memoryGrowth = 0;

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
        if ($n > self::NODE_MASK) {
            throw new RuntimeException("$n nodes are more than the contraction numbers (" . self::NODE_MASK . '): cut the zone smaller');
        }
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

    /**
     * Makes `run()` stop early (as at a deadline) once the memory in use passes `MEMORY_SHARE` of `$limit` bytes and has
     * grown by `$growth` bytes (default 5 % of the limit) since the contraction of this call began, so that the state
     * is saved while there is room, not when the process is about to be killed. A state that is big from the start but
     * does not grow (the usual case: the contraction frees more than it adds) is saved at the deadline as ever, not
     * after every thousand nodes. A call contracts `$minSteps` nodes first: it always makes progress. 0 = no limit.
     */
    public function setMemoryLimit(int $limit, int $minSteps = 1000, ?int $growth = null): void
    {
        $this->memoryCap = $limit > 0 ? (int) ($limit * self::MEMORY_SHARE) : 0;
        $this->minSteps = $minSteps;
        $this->memoryGrowth = $growth ?? (int) ($limit * 0.05);
    }

    /**
     * Freezes nodes: they are not contracted with the others. When all the others but `$remainder` are contracted, the arcs
     * among the nodes still there (the frozen ones and the remainder) are saved to the core file (node, arc count, then per arc
     * destination, weight, duration, distance, middle: five u32), and then, with `$release`, the frozen nodes and the remainder are
     * contracted too (a tile: the file stays a whole hierarchy); without it they stay uncontracted (a link).
     *
     * A remainder larger than the nodes that are not frozen keeps them all (the core is then the whole graph).
     *
     * @param string $frozen one byte per node, "\1" = frozen, or empty for none
     */
    public function setFreeze(string $frozen, bool $release, int $remainder): void
    {
        if ($frozen !== '' && \strlen($frozen) !== $this->n) {
            throw new \InvalidArgumentException(\sprintf('the frozen flags have %d bytes for %d nodes', \strlen($frozen), $this->n));
        }
        if ($remainder < 0) {
            throw new \InvalidArgumentException("a negative remainder ($remainder)");
        }
        $this->frozen = strpos($frozen, "\1") === false ? '' : $frozen;
        $this->release = $release;
        $this->remainder = $remainder;
    }

    /** @return array{int,int,int} the settled-node limits of the witness searches: at the start, of a neighbour, of a contraction */
    private function limits(): array
    {
        return [$this->initialSettled, $this->neighbourSettled, $this->contractionSettled];
    }

    public function setCorePath(string $path): void
    {
        $this->corePath = $path;
    }

    /** Number of nodes contracted when the snapshot is taken: all but the frozen ones and the remainder. */
    private function stopRank(): int
    {
        if ($this->frozen === '' && $this->remainder === 0) {
            return $this->n;
        }

        return max(0, $this->n - substr_count($this->frozen, "\1") - $this->remainder);
    }

    private function needsSnapshot(): bool
    {
        return $this->frozen !== '' || $this->remainder > 0;
    }

    /** True when node `$x` takes part in the contraction now: not frozen, or released. */
    private function active(int $x): bool
    {
        return $this->frozen === '' || $this->frozen[$x] === "\0" || ($this->release && $this->snapshotted && $x < $this->relNext);
    }

    /** Writes the arcs among the nodes still there (what the interior contraction made of the frozen nodes). */
    private function snapshot(): void
    {
        $buf = '';
        for ($x = 0; $x < $this->n; $x++) {
            if ($this->alive[$x] !== "\1") {
                continue;
            }
            $o = Bin::allU32($this->out[$x]);
            $t = Bin::allU32($this->td[$x]);
            $buf .= pack('VV', $x, intdiv(\count($o), 3));
            for ($j = 0, $m = \count($o), $r = 0; $j < $m; $j += 3, $r += 2) {
                $buf .= pack('VVVVV', $o[$j], $o[$j + 1], $t[$r], $t[$r + 1], $o[$j + 2]);
            }
        }
        Io::putAtomic($this->corePath, $buf);
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
        if ($w > 0xFFFFFFFF || $t > 0xFFFFFFFF || $d > 0xFFFFFFFF) {
            throw new RuntimeException('a shortcut is too long to be stored in 32 bits: cut the zone smaller');
        }
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
                fclose($f);
                throw new RuntimeException("$path is shorter than its checkpoint");
            }
            if (!ftruncate($f, $size)) {
                fclose($f);
                throw new RuntimeException("cannot cut $path back to $size bytes");
            }
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
                Io::write($f, $this->$b, $h);
                Io::flush($f, $h);
                $this->$s += \strlen($this->$b);
                $this->$b = '';
            }
        }
    }

    private function rebuildQueue(): void
    {
        $this->queue = new IntMinHeap();
        for ($x = 0; $x < $this->initNext; $x++) {
            if ($this->alive[$x] === "\1" && $this->active($x)) {
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
                $p = $this->relevance($x, 0, $this->limits()[0]);
                $this->prio[$x] = $p;
                ++$this->initNext;
                if ($this->active($x)) {
                    $this->queue->insert(($p << self::NODE_BITS) | $x);
                }
                if (hrtime(true) > $deadline) {
                    return false;
                }
            }
            $q = $this->queue;
            $steps = 0;
            $startUsage = memory_get_usage();
            while (true) {
                $target = $this->snapshotted || !$this->needsSnapshot() ? $this->n : $this->stopRank();
                // a run resumed in the middle of the release goes on releasing before it contracts anything
                $releasing = $this->snapshotted && $this->release && $this->relNext < $this->n;
                while (!$releasing && $this->rank < $target && !$q->isEmpty()) {
                    $k = $q->extract();
                    $x = $k & self::NODE_MASK;
                    if ($this->alive[$x] === "\0" || ($k >> self::NODE_BITS) !== $this->prio[$x]) {
                        continue;
                    }
                    $this->contract($x);
                    ++$steps;
                    if (hrtime(true) > $deadline || ($this->memoryCap > 0 && $steps >= $this->minSteps && ($steps & 63) === 0 && $this->memoryAlarm($startUsage))) {
                        return $this->finished();
                    }
                }
                if (!$this->snapshotted && $this->needsSnapshot()) {
                    $this->snapshot();
                    $this->snapshotted = true;
                    if (!$this->release) {
                        return true;
                    }
                }
                while ($this->relNext < $this->n) {
                    $x = $this->relNext;
                    if ($this->frozen !== '' && $this->frozen[$x] === "\1" && $this->alive[$x] === "\1") {
                        $p = $this->relevance($x, $this->level[$x], $this->limits()[1]);
                        $this->prio[$x] = $p;
                        $this->queue->insert(($p << self::NODE_BITS) | $x);
                    }
                    ++$this->relNext;
                    if (hrtime(true) > $deadline) {
                        return $this->finished();
                    }
                }
                if ($this->rank >= $this->n || $q->isEmpty()) {
                    return true;
                }
            }
        } finally {
            try {
                $this->flush();
            } finally {
                foreach (['up', 'idx', 'ord'] as $h) {
                    if (\is_resource($this->$h)) {
                        fclose($this->$h);
                    }
                    $this->$h = null;
                }
            }
        }
    }

    /** Whether the contraction has nothing left to do (checked when a call stops at its deadline). */
    private function finished(): bool
    {
        if ($this->needsSnapshot()) {
            if (!$this->snapshotted) {
                return false;
            }
            if (!$this->release) {
                return true;
            }
        }

        return $this->rank >= $this->n;
    }

    /** True when the memory in use is over its cap and has grown since `$startUsage` by more than the allowed growth. */
    private function memoryAlarm(int $startUsage): bool
    {
        $u = memory_get_usage();

        return $u > $this->memoryCap && $u > $startUsage + $this->memoryGrowth;
    }

    private function contract(int $x): void
    {
        $this->alive[$x] = "\0";
        ++$this->rank;
        $this->shortcutsOf($x, $this->limits()[2], true);
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
            if (!$this->active($y)) {
                continue;
            }
            $p = $this->relevance($y, $this->level[$y], $this->limits()[1]);
            $this->prio[$y] = $p;
            $this->queue->insert(($p << self::NODE_BITS) | $y);
        }
    }


    /**
     * Saves the state to resume from (call after `run()` returned: the output files are flushed, their sizes recorded).
     * The file is written piece by piece to a temporary name, checked and renamed, so the memory it needs is about 1 MB,
     * not a second copy of the state, and a killed process leaves the previous checkpoint whole.
     *
     * Layout: magic, build key (u32 length, bytes), seven u64 (n, rank, initNext, shortcuts, upSize, idxSize, ordSize),
     * four u64 (snapshot taken, next node to release, release, remainder), the frozen flags (u32 length, bytes: empty
     * when nothing is frozen), `alive` (n bytes), `prio` and `level` (n u64 each), then `out`, `in` and `td` (per node a u32 length and the
     * bytes), the end mark.
     */
    public function checkpointTo(string $path, string $key): void
    {
        $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
        $f = @fopen($tmp, 'wb');
        if ($f === false) {
            throw new RuntimeException("cannot write $tmp");
        }
        try {
            $written = 0;
            $buf = self::CHECKPOINT_MAGIC . pack('V', \strlen($key)) . $key
                . pack('P7', $this->n, $this->rank, $this->initNext, $this->shortcuts, $this->upSize, $this->idxSize, $this->ordSize)
                . pack('P4', $this->snapshotted ? 1 : 0, $this->relNext, $this->release ? 1 : 0, $this->remainder)
                . pack('V', \strlen($this->frozen)) . $this->frozen;
            $put = static function () use ($f, $tmp, &$buf, &$written): void {
                Io::write($f, $buf, $tmp);
                $written += \strlen($buf);
                $buf = '';
            };
            for ($i = 0; $i < $this->n; $i += 1 << 20) {
                $buf .= substr($this->alive, $i, 1 << 20);
                $put();
            }
            foreach ([$this->prio, $this->level] as $list) {
                for ($i = 0; $i < $this->n; $i += 8192) {
                    $buf .= Bin::packAll('P', \array_slice($list, $i, 8192));
                    if (\strlen($buf) >= self::BUFFER) {
                        $put();
                    }
                }
            }
            $put();
            foreach (['out', 'in', 'td'] as $name) {
                $strings = $this->$name;
                for ($i = 0; $i < $this->n; $i++) {
                    $buf .= pack('V', \strlen($strings[$i])) . $strings[$i];
                    if (\strlen($buf) >= self::BUFFER) {
                        $put();
                    }
                }
            }
            unset($strings);
            $buf .= self::CHECKPOINT_END;
            $put();
            Io::close($f, $tmp);
            $f = null;
            clearstatcache(true, $tmp);
            if (filesize($tmp) !== $written || !rename($tmp, $path)) {
                throw new RuntimeException("cannot save the contraction state $path");
            }
        } catch (\Throwable $e) {
            if (\is_resource($f)) {
                @fclose($f);
            }
            @unlink($tmp);
            throw $e;
        }
    }

    /**
     * Resumes from a file `checkpointTo` wrote, read piece by piece: the memory needed is the state itself.
     * Refused when it belongs to another build (`$key`) or to a graph of another size (`$nodes`).
     */
    public static function restoreFrom(string $path, string $key, int $nodes, string $upPath, string $idxPath, string $ordPath): self
    {
        $r = new ChunkReader($path);
        $magic = $r->take(8);
        if ($magic !== self::CHECKPOINT_MAGIC) {
            throw new RuntimeException("$path is not a contraction state of this version: remove the work directory");
        }
        $kl = Bin::u32($r->take(4));
        if ($kl > 1024) {
            throw new RuntimeException("$path has a build key of $kl bytes, it is corrupted: remove the work directory");
        }
        if ($r->take($kl) !== $key) {
            throw new RuntimeException("$path belongs to another build: remove the work directory");
        }
        [$n, $rank, $initNext, $shortcuts, $upSize, $idxSize, $ordSize] = Bin::list('P7', $r->take(56));
        if ($n !== $nodes) {
            throw new RuntimeException("$path is for $n nodes, the graph has $nodes: remove the work directory");
        }
        [$snapshotted, $relNext, $release, $remainder] = Bin::list('P4', $r->take(32));
        $fl = Bin::u32($r->take(4));
        $frozen = $fl > 0 ? $r->take($fl) : '';
        $c = new self($n, $upPath, $idxPath, $ordPath);
        $c->snapshotted = $snapshotted === 1;
        $c->relNext = $relNext;
        $c->release = $release === 1;
        $c->remainder = $remainder;
        $c->frozen = $frozen;
        $c->rank = $rank;
        $c->initNext = $initNext;
        $c->shortcuts = $shortcuts;
        $c->upSize = $upSize;
        $c->idxSize = $idxSize;
        $c->ordSize = $ordSize;
        $alive = '';
        for ($i = 0; $i < $n; $i += 1 << 20) {
            $alive .= $r->take(min(1 << 20, $n - $i));
        }
        $c->alive = $alive;
        unset($alive);
        for ($i = 0; $i < $n; $i += 8192) {
            $m = min(8192, $n - $i);
            foreach (Bin::list('P' . $m, $r->take($m * 8)) as $j => $v) {
                $c->prio[$i + $j] = $v;
            }
        }
        for ($i = 0; $i < $n; $i += 8192) {
            $m = min(8192, $n - $i);
            foreach (Bin::list('P' . $m, $r->take($m * 8)) as $j => $v) {
                $c->level[$i + $j] = $v;
            }
        }
        for ($i = 0; $i < $n; $i++) {
            $len = Bin::u32($r->take(4));
            $c->out[$i] = $len > 0 ? $r->take($len) : '';
        }
        for ($i = 0; $i < $n; $i++) {
            $len = Bin::u32($r->take(4));
            $c->in[$i] = $len > 0 ? $r->take($len) : '';
        }
        for ($i = 0; $i < $n; $i++) {
            $len = Bin::u32($r->take(4));
            $c->td[$i] = $len > 0 ? $r->take($len) : '';
        }
        $end = $r->take(8);
        if ($end !== self::CHECKPOINT_END || !$r->atEnd()) {
            throw new RuntimeException("$path is damaged: remove the work directory");
        }
        $r->close();

        return $c;
    }
}
