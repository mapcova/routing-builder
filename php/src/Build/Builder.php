<?php

declare(strict_types=1);

namespace MapCova\GraphBuilder\Build;

use MapCova\GraphBuilder\Ch\Contractor;
use MapCova\GraphBuilder\Osm\NeededIds;
use MapCova\GraphBuilder\Osm\NodesPass;
use MapCova\GraphBuilder\Osm\WaysPass;
use MapCova\GraphBuilder\Pbf\Block;
use MapCova\GraphBuilder\Pbf\PbfReader;
use MapCova\GraphBuilder\Profile\Profile;
use MapCova\GraphBuilder\Support\Bin;
use MapCova\GraphBuilder\Support\Io;
use MapCova\GraphBuilder\Tiles\TileSet;
use RuntimeException;

/**
 * Builds one `.mcr` file in slices. Each call of `runSlice()` works until its time is up, saves its state in the work
 * directory and returns; the next call (another process, any time later) resumes from there. The last slice writes
 * the file, atomically.
 *
 * Phases, in order: `ways` (stream the PBF: kept ways and restrictions), `needed` (sorted node ids the ways use),
 * `nodes` (stream the PBF again: coordinates by a sorted merge), `graph` (network, arcs, segments, geometry, grid),
 * `contract` (the hierarchy), `finish` (final ids, sections, file). `ways`, `nodes` and `contract` stop and resume
 * at a cursor; `needed`, `graph` and `finish` are short and run whole (a build killed inside one starts it over).
 */
final class Builder
{
    /**
     * Part of the build key: raise it whenever a change of the code makes a work directory of the previous version
     * useless (another file format, another checkpoint).
     */
    public const CODE_VERSION = 5;
    /**
     * Bytes per kept node the `graph` phase needs at its peak, for the edge-based graph (car: 103 MB for 323,000 kept
     * nodes) and the node-based one (bike: 150 MB for 917,000), measured on Luxembourg, plus about 20 % of margin.
     */
    private const GRAPH_BYTES_PER_NODE_EDGE = 330;
    private const GRAPH_BYTES_PER_NODE_NODE = 180;
    private const GRAPH_BASE_BYTES = 16 << 20;
    /** Per graph node of the contraction (about 450 measured on Luxembourg, runtime included), and per arc of the sort that loads it. */
    private const CONTRACT_BYTES_PER_NODE = 450;
    private const SORT_BYTES_PER_ARC = 130;
    /** Share of the memory limit a phase may be expected to use: the builder warns above it. */
    private const MEMORY_SHARE = 0.85;
    /** Seconds between two saves of the state inside a long phase. */
    private const SAVE_EVERY = 2.0;

    private Workdir $w;
    private Profile $profile;
    private State $state;
    private ?TileSet $tileSet = null;
    /** @var callable(string):void */
    private $log;

    /** @param (callable(string):void)|null $log */
    public function __construct(private readonly Options $o, ?callable $log = null)
    {
        $p = Profile::parse($o->profile);
        if ($p === null) {
            throw new RuntimeException('profile must be car, car-notoll, bike or foot');
        }
        if (preg_match('/^[a-z0-9-]+$/', $o->zone) !== 1) {
            throw new RuntimeException('the zone identifier is lower case letters, digits and dashes');
        }
        $this->profile = $p;
        if ($o->tiles !== null) {
            if (!$p->edgeBased()) {
                throw new RuntimeException('tiles are built for car and car-notoll');
            }
            $this->tileSet = TileSet::load($o->tiles);
            if (!$this->tileSet->has($o->zone)) {
                throw new RuntimeException("the tiling {$o->tiles} has no tile {$o->zone}");
            }
        }
        $this->w = new Workdir($o->workDir);
        $this->state = new State();
        $this->log = $log ?? static function (string $m): void {
        };
    }

    private function say(string $m): void
    {
        ($this->log)($m);
    }

    private function key(): string
    {
        $in = $this->o->input;
        $poly = $this->o->poly !== null && is_file($this->o->poly) ? md5_file($this->o->poly) : '';

        $dump = $this->o->dumpArcs !== null ? self::absolute($this->o->dumpArcs) : '';
        $tiles = $this->o->tiles !== null ? $this->tilingHash($this->o->tiles) : '';
        $junctions = $this->o->junctions !== null && is_file($this->o->junctions) ? filesize($this->o->junctions) . ':' . filemtime($this->o->junctions) : '';

        return sha1(implode('|', [self::CODE_VERSION, realpath($in) ?: $in, (string) filesize($in), (string) filemtime($in), $this->o->profile, $this->o->zone, (string) $poly, self::absolute($this->o->output), $dump, $tiles, $junctions, $this->tileSet !== null ? (string) $this->o->exportInterior : '']));
    }

    /** Fingerprint of a tiling file and of the polygons it names: another tiling, another build. */
    private function tilingHash(string $file): string
    {
        $h = hash_init('sha1');
        hash_update($h, (string) @file_get_contents($file));
        $j = json_decode((string) @file_get_contents($file), true);
        if (\is_array($j) && isset($j['tiles']) && \is_array($j['tiles'])) {
            foreach ($j['tiles'] as $poly) {
                if (\is_string($poly)) {
                    hash_update($h, (string) @file_get_contents(\dirname($file) . '/' . $poly));
                }
            }
        }

        return hash_final($h);
    }

    /** The path with its directory resolved, so that the same file has the same key whatever the working directory. */
    public static function absolute(string $path): string
    {
        $dir = realpath(\dirname($path));

        return ($dir !== false ? $dir : \dirname($path)) . '/' . basename($path);
    }

    private function memoryLimit(): int
    {
        if ($this->o->memoryLimit > 0) {
            return $this->o->memoryLimit;
        }
        $l = (int) ini_parse_quantity((string) ini_get('memory_limit'));

        return max($l, 0);
    }

    private function loadState(): ?State
    {
        $a = $this->w->readJson('state.json');

        return $a === null ? null : State::fromArray($a);
    }

    /** True when this very build (same input, options and output) is finished and its file is there. */
    public function isDone(): bool
    {
        $s = $this->loadState();

        return $s !== null && $s->phase === 'done' && $s->key === $this->key() && is_file($this->o->output);
    }

    /**
     * Works for at most the slice time, then saves the state and returns.
     *
     * @throws BuilderBusy when another launch holds the work directory
     */
    public function runSlice(): SliceReport
    {
        $this->w->lock();
        try {
            return $this->slice();
        } finally {
            $this->w->unlock();
        }
    }

    private function slice(): SliceReport
    {
        $t0 = hrtime(true);
        $deadline = $t0 + (int) ($this->o->slice * 1e9);
        memory_reset_peak_usage();
        $this->w->sweepTemporaries();
        $key = $this->key();
        $state = $this->loadState();
        if ($state !== null && $state->phase === 'done') {
            if ($state->key === $key && is_file($this->o->output)) {
                return new SliceReport(true, 'done', 0.0, 0, []);
            }
            // another build finished here, or the file it wrote is gone: the work directory holds nothing to resume
            $this->say($state->key === $key ? "{$this->o->output} is missing: building it again" : 'the work directory holds a finished build of another input or options: starting over');
            $state = null;
        }
        if ($state === null) {
            if (!is_file($this->o->input)) {
                throw new RuntimeException("cannot read {$this->o->input}");
            }
            // no state: what is in the directory (phase files, a contraction state) belongs to no build we can trust
            $this->w->clean();
            $state = new State();
            $state->key = $key;
            $state->osmTimestamp = max(PbfReader::timestamp($this->o->input) ?? 0, 0);
        } elseif ($state->key !== $key) {
            throw new RuntimeException("the work directory {$this->o->workDir} belongs to another build (other input, profile, zone or output): remove it or pick another");
        }
        $this->state = $state;
        $done = [];
        $peak = 0;
        while ($this->state->phase !== 'done') {
            if ($done !== [] && hrtime(true) > $deadline) {
                break;
            }
            $phase = $this->state->phase;
            memory_reset_peak_usage();
            $before = hrtime(true);
            $finished = match ($phase) {
                'ways' => $this->phaseWays($deadline),
                'needed' => $this->phaseNeeded(),
                'nodes' => $this->phaseNodes($deadline),
                'graph' => $this->phaseGraph(),
                'contract' => $this->phaseContract($deadline),
                'finish' => $this->phaseFinish(),
                default => throw new RuntimeException("unknown phase $phase"),
            };
            $phasePeak = memory_get_peak_usage(true);
            $peak = max($peak, $phasePeak);
            $this->say(sprintf('phase %s %s in %.1fs, peak %.0f MB', $phase, $finished ? 'finished' : 'paused', (hrtime(true) - $before) / 1e9, $phasePeak / 1048576));
            $done[] = $phase . ($finished ? '' : '*');
            if (!$finished) {
                break;
            }
            $this->state->next();
            $this->save();
        }
        $seconds = (hrtime(true) - $t0) / 1e9;
        $peak = max($peak, memory_get_peak_usage(true));
        $this->state->slices[] = ['seconds' => round($seconds, 2), 'peak_bytes' => $peak, 'phases' => $done];
        $final = $this->state->phase === 'done';
        $this->save();
        // kept beside the output so the numbers survive the cleaning of the work directory
        Io::putAtomic($this->o->output . '.slices.json', json_encode(['slices' => $this->state->slices], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        if ($final && !$this->o->keepWork) {
            $this->w->clean(['state.json']);
        }

        return new SliceReport($final, $this->state->phase, $seconds, $peak, $done);
    }

    private function save(): void
    {
        $this->w->writeJson('state.json', $this->state->toArray());
    }

    private function phaseWays(int $deadline): bool
    {
        $s = $this->state;
        $this->w->truncate('ways.bin', $s->waysSize);
        $this->w->truncate('restrictions.bin', $s->restrictionsSize);
        $fw = fopen($this->w->path('ways.bin'), 'ab');
        $fr = fopen($this->w->path('restrictions.bin'), 'ab');
        try {
            if ($fw === false || $fr === false) {
                throw new RuntimeException('cannot open the way files');
            }
            $pass = new WaysPass($this->profile, $s->lastWayId);
            $lastSave = hrtime(true);
            $finished = true;
            foreach (PbfReader::blobs($this->o->input, $s->waysOffset) as $blob) {
                if ($blob['type'] === 'OSMData') {
                    $r = $pass->process(Block::decode($blob['data']));
                    Io::write($fw, $r['ways'], 'ways.bin');
                    Io::write($fr, $r['restrictions'], 'restrictions.bin');
                    $s->waysSize += \strlen($r['ways']);
                    $s->restrictionsSize += \strlen($r['restrictions']);
                    $s->ways += $r['nWays'];
                    $s->restrictions += $r['nRestrictions'];
                    $s->lastWayId = $pass->lastWayId();
                }
                $s->waysOffset = $blob['next'];
                $now = hrtime(true);
                if ($now > $deadline) {
                    $finished = false;
                    break;
                }
                if (($now - $lastSave) / 1e9 > self::SAVE_EVERY) {
                    Io::flush($fw, 'ways.bin');
                    Io::flush($fr, 'restrictions.bin');
                    $this->save();
                    $lastSave = $now;
                }
            }
            Io::close($fw, 'ways.bin');
            Io::close($fr, 'restrictions.bin');
            $this->save();
        } finally {
            foreach ([$fw, $fr] as $h) {
                if (\is_resource($h)) {
                    fclose($h);
                }
            }
        }
        $this->say("ways: {$s->ways} ways, {$s->restrictions} restrictions");

        return $finished;
    }

    private function phaseNeeded(): bool
    {
        if ($this->state->ways === 0) {
            throw new RuntimeException("the extract has no way the {$this->o->profile} profile can use");
        }
        $this->state->needed = NeededIds::build($this->w->path('ways.bin'), $this->w->path('needed.bin'), $this->w->dir);
        $this->say("needed: {$this->state->needed} node ids");

        return true;
    }

    private function phaseNodes(int $deadline): bool
    {
        $s = $this->state;
        $this->w->truncate('nodes.bin', $s->nodesSize);
        $fn = fopen($this->w->path('nodes.bin'), 'ab');
        try {
            if ($fn === false) {
                throw new RuntimeException('cannot open nodes.bin');
            }
            $pass = new NodesPass($this->w->path('needed.bin'), $this->profile, $s->neededIndex);
            $lastSave = hrtime(true);
            $finished = true;
            foreach (PbfReader::blobs($this->o->input, $s->nodesOffset) as $blob) {
                if ($blob['type'] === 'OSMData') {
                    $b = Block::decode($blob['data']);
                    $hasNodes = false;
                    foreach ($b->groups as [$field]) {
                        if ($field === 1 || $field === 2) {
                            $hasNodes = true;
                            break;
                        }
                    }
                    if ($hasNodes) {
                        $s->seenNodes = true;
                        $rec = $pass->process($b);
                        Io::write($fn, $rec, 'nodes.bin');
                        $s->nodesSize += \strlen($rec);
                    } elseif ($s->seenNodes) {
                        break; // nodes come first in a sorted extract: past them
                    }
                }
                $s->nodesOffset = $blob['next'];
                $s->neededIndex = $pass->neededIndex();
                if ($s->neededIndex >= $s->needed) {
                    break; // every id asked for was met
                }
                $now = hrtime(true);
                if ($now > $deadline) {
                    $finished = false;
                    break;
                }
                if (($now - $lastSave) / 1e9 > self::SAVE_EVERY) {
                    Io::flush($fn, 'nodes.bin');
                    $this->save();
                    $lastSave = $now;
                }
            }
            $s->neededIndex = $pass->neededIndex();
            Io::close($fn, 'nodes.bin');
            $this->save();
        } finally {
            if (\is_resource($fn)) {
                fclose($fn);
            }
        }
        if ($finished) {
            $this->say('nodes: ' . intdiv($s->nodesSize, NodesPass::RECORD) . " of {$s->needed} found");
        }

        return $finished;
    }

    /**
     * `graph` and the start of `contract` cannot be resumed inside, and a process killed for memory would do the same
     * again at every launch: refuse here, with the reason, a zone the limit cannot hold.
     */
    private function preflight(string $phase, int $need, string $size): void
    {
        $limit = $this->memoryLimit();
        if ($limit > 0 && $need > self::MEMORY_SHARE * $limit) {
            throw new RuntimeException(sprintf(
                'the %s phase of this extract (%s) needs about %d MB and the memory limit is %d MB (the builder stays under %d%% of it): raise --memory or cut the zone into smaller ones',
                $phase,
                $size,
                (int) ceil($need / 1048576),
                intdiv($limit, 1048576),
                (int) (100 * self::MEMORY_SHARE),
            ));
        }
    }

    private function phaseGraph(): bool
    {
        $nodes = intdiv($this->state->nodesSize, NodesPass::RECORD);
        $this->preflight('graph', self::GRAPH_BASE_BYTES + ($this->profile->edgeBased() ? self::GRAPH_BYTES_PER_NODE_EDGE : self::GRAPH_BYTES_PER_NODE_NODE) * $nodes, "$nodes kept nodes");
        $log = GraphPhase::run($this->w, $this->profile, $this->tileSet, $this->tileSet?->index($this->o->zone) ?? -1, $this->o->junctions);
        foreach ($log as $k => $v) {
            $this->say("graph: $k = $v");
        }
        if ($this->o->dumpArcs !== null) {
            GraphPhase::dumpArcs($this->w, $this->o->dumpArcs);
        }

        return true;
    }

    private function phaseContract(int $deadline): bool
    {
        $s = $this->state;
        if ($s->nn < 0) {
            $nn = 0;
            $f = fopen($this->w->path('arcs.bin'), 'rb');
            if ($f === false) {
                throw new RuntimeException('cannot open arcs.bin');
            }
            try {
                while (($raw = fread($f, 24 * 8192)) !== false && $raw !== '') {
                    for ($o = 0, $e = \strlen($raw); $o + 24 <= $e; $o += 24) {
                        $u = Bin::u32s($raw, 2, $o);
                        $nn = max($nn, $u[0] + 1, $u[1] + 1);
                    }
                }
            } finally {
                fclose($f);
            }
            $s->nn = $nn;
            $this->save();
        }
        $paths = [$this->w->path('up.bin'), $this->w->path('upidx.bin'), $this->w->path('order.bin')];
        $checkpoint = $this->w->path('contract.state');
        if ($this->w->has('contract.state')) {
            $ch = Contractor::restoreFrom($checkpoint, $this->state->key, $s->nn, ...$paths);
        } else {
            $arcs = intdiv((int) filesize($this->w->path('arcs.bin')), 24);
            $this->preflight('contract', self::GRAPH_BASE_BYTES + self::CONTRACT_BYTES_PER_NODE * $s->nn + self::SORT_BYTES_PER_ARC * $arcs, "{$s->nn} graph nodes, $arcs arcs");
            $ch = Contractor::fromArcs($s->nn, $this->w->path('arcs.bin'), $this->profile->edgeBased(), ...$paths);
        }
        if ($this->tileSet !== null) {
            if (!$this->w->has('contract.state')) {
                $ch->setFreeze(substr((string) file_get_contents($this->w->path('frozen.bin')), 0, $s->nn), true, $this->o->exportInterior);
            }
            $ch->setCorePath($this->w->path('core.bin'));
        }
        $ch->setMemoryLimit($this->memoryLimit());
        $finished = $ch->run($deadline);
        $s->rank = $ch->rank();
        if (!$finished) {
            $ch->checkpointTo($checkpoint, $this->state->key);
            $this->say(sprintf('contract: %d of %d nodes', $ch->rank(), $s->nn));
        } else {
            $this->say("contract: {$s->nn} nodes, " . $ch->shortcuts . ' shortcuts');
            @unlink($checkpoint);
        }

        return $finished;
    }

    private function phaseFinish(): bool
    {
        $polygon = [];
        if ($this->o->poly !== null) {
            $text = @file_get_contents($this->o->poly);
            if ($text === false) {
                throw new RuntimeException("cannot read {$this->o->poly}");
            }
            $polygon = Poly::parse($text);
        }
        $builtAt = $this->o->builtAt ?? time();
        $r = FinishPhase::run($this->w, $this->profile, $this->o->zone, $this->state->nn, $this->state->osmTimestamp, $builtAt, $polygon, $this->o->output, $this->tileSet, $this->tileSet?->index($this->o->zone) ?? -1);
        $this->say('wrote ' . $this->o->output . ' (' . $r['bytes'] . ' bytes, ' . $r['nodes'] . ' nodes)');

        return true;
    }
}
