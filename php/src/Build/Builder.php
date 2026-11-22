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
    private const VERSION = 1;
    /** Seconds between two saves of the state inside a long phase. */
    private const SAVE_EVERY = 2.0;

    private Workdir $w;
    private Profile $profile;
    private State $state;
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

        return sha1(implode('|', [self::VERSION, realpath($in) ?: $in, (string) filesize($in), (string) filemtime($in), $this->o->profile, $this->o->zone, (string) $poly]));
    }

    private function loadState(): ?State
    {
        $a = $this->w->readJson('state.json');

        return $a === null ? null : State::fromArray($a);
    }

    /** True when the build is finished (the file is written). */
    public function isDone(): bool
    {
        $s = $this->loadState();

        return $s !== null && $s->phase === 'done';
    }

    public function runSlice(): SliceReport
    {
        $t0 = hrtime(true);
        $deadline = $t0 + (int) ($this->o->slice * 1e9);
        memory_reset_peak_usage();
        $state = $this->loadState();
        if ($state === null) {
            if (!is_file($this->o->input)) {
                throw new RuntimeException("cannot read {$this->o->input}");
            }
            $state = new State();
            $state->key = $this->key();
            $state->osmTimestamp = max(PbfReader::timestamp($this->o->input) ?? 0, 0);
        } elseif ($state->key !== $this->key()) {
            throw new RuntimeException("the work directory {$this->o->workDir} belongs to another build (other input, profile or zone): remove it or pick another");
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
        file_put_contents($this->o->output . '.slices.json', json_encode(['slices' => $this->state->slices], JSON_PRETTY_PRINT));
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
        if ($fw === false || $fr === false) {
            throw new RuntimeException('cannot open the way files');
        }
        $pass = new WaysPass($this->profile);
        $lastSave = hrtime(true);
        $finished = true;
        foreach (PbfReader::blobs($this->o->input, $s->waysOffset) as $blob) {
            if ($blob['type'] === 'OSMData') {
                $r = $pass->process(Block::decode($blob['data']));
                fwrite($fw, $r['ways']);
                fwrite($fr, $r['restrictions']);
                $s->waysSize += \strlen($r['ways']);
                $s->restrictionsSize += \strlen($r['restrictions']);
                $s->ways += $r['nWays'];
                $s->restrictions += $r['nRestrictions'];
            }
            $s->waysOffset = $blob['next'];
            $now = hrtime(true);
            if ($now > $deadline) {
                $finished = false;
                break;
            }
            if (($now - $lastSave) / 1e9 > self::SAVE_EVERY) {
                fflush($fw);
                fflush($fr);
                $this->save();
                $lastSave = $now;
            }
        }
        fflush($fw);
        fflush($fr);
        $this->save();
        fclose($fw);
        fclose($fr);
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
                    fwrite($fn, $rec);
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
                fflush($fn);
                $this->save();
                $lastSave = $now;
            }
        }
        $s->neededIndex = $pass->neededIndex();
        fflush($fn);
        $this->save();
        fclose($fn);
        if ($finished) {
            $this->say('nodes: ' . intdiv($s->nodesSize, NodesPass::RECORD) . " of {$s->needed} found");
        }

        return $finished;
    }

    private function phaseGraph(): bool
    {
        $log = GraphPhase::run($this->w, $this->profile);
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
            while (($raw = fread($f, 24 * 8192)) !== false && $raw !== '') {
                for ($o = 0, $e = \strlen($raw); $o + 24 <= $e; $o += 24) {
                    $u = Bin::u32s($raw, 2, $o);
                    $nn = max($nn, $u[0] + 1, $u[1] + 1);
                }
            }
            fclose($f);
            $s->nn = $nn;
            $this->save();
        }
        $paths = [$this->w->path('up.bin'), $this->w->path('upidx.bin'), $this->w->path('order.bin')];
        if ($this->w->has('contract.state')) {
            $ch = Contractor::restore((string) file_get_contents($this->w->path('contract.state')), ...$paths);
        } else {
            $ch = Contractor::fromArcs($s->nn, $this->w->path('arcs.bin'), $this->profile->edgeBased(), ...$paths);
        }
        $finished = $ch->run($deadline);
        $s->rank = $ch->rank();
        if (!$finished) {
            $this->w->writeAtomic('contract.state', $ch->checkpoint());
            $this->say(sprintf('contract: %d of %d nodes', $ch->rank(), $s->nn));
        } else {
            $this->say("contract: {$s->nn} nodes, " . $ch->shortcuts . ' shortcuts');
            @unlink($this->w->path('contract.state'));
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
        $r = FinishPhase::run($this->w, $this->profile, $this->o->zone, $this->state->nn, $this->state->osmTimestamp, $builtAt, $polygon, $this->o->output);
        $this->say('wrote ' . $this->o->output . ' (' . $r['bytes'] . ' bytes, ' . $r['nodes'] . ' nodes)');

        return true;
    }
}
