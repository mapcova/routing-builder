<?php

declare(strict_types=1);

namespace MapCova\GraphBuilder\Link;

use MapCova\GraphBuilder\Build\Builder;
use MapCova\GraphBuilder\Build\BuilderBusy;
use MapCova\GraphBuilder\Build\SliceReport;
use MapCova\GraphBuilder\Build\Workdir;
use MapCova\GraphBuilder\Ch\Contractor;
use MapCova\GraphBuilder\Mcr\Container;
use MapCova\GraphBuilder\Mcr\Exports;
use MapCova\GraphBuilder\Mcr\Format;
use MapCova\GraphBuilder\Support\Bin;
use MapCova\GraphBuilder\Support\Io;
use MapCova\GraphBuilder\Support\Json;
use MapCova\GraphBuilder\Tiles\Key;
use RuntimeException;

/**
 * Builds the link (`.mco`) of one node of the tree, over its children (tiles or links), resumable like a tile.
 *
 * The vertices of the link are the vertices its children export (sorted by key); its arcs are the core arcs of
 * the children (the shortest ways between a child's exported vertices inside the child) and the cross arcs between
 * two of its children (tiles side by side). A vertex that has a cross arc to a tile outside the link is on its outer
 * border: it is frozen, and left uncontracted with the K_L most important interior vertices (`exportInterior`); they are
 * exported in their turn (`border`, `core`) to the link above. A link without outer border (the top) is contracted except
 * for a remainder of R vertices, left for a Dijkstra at query time. A cross arc must be in the files of both its tiles,
 * with the same cost: the build stops otherwise. Each original arc
 * records where it comes from (child and keys, or the tile of a cross arc), so that a path can be unpacked.
 *
 * Phases: `load` (children to `arcs.bin`), `contract` (the same contractor as the tiles), `finish` (the file).
 */
final class LinkBuilder
{
    private const CODE_VERSION = 2;
    public const ORIG_BYTES = 12;
    /** A cross arc is recorded in `orig` with this bit set and the index of its tile in the rest of the kind field. */
    public const CROSS = 0x80000000;

    private Workdir $w;
    /** @var callable(string):void */
    private $log;

    /** @param (callable(string):void)|null $log */
    public function __construct(private readonly LinkOptions $o, ?callable $log = null)
    {
        $this->w = new Workdir($o->workDir);
        $this->log = $log ?? static function (string $m): void {
        };
    }

    private function say(string $m): void
    {
        ($this->log)($m);
    }

    /** @return list<string> the files the link is made of, in a fixed order */
    private function inputs(): array
    {
        $files = [];
        foreach ($this->o->tiles->childrenOf($this->o->link) as $c) {
            $files[] = $this->childPath($c);
        }
        foreach ($this->o->tiles->tilesBelow($this->o->link) as $t) {
            $files[] = $this->o->dir . "/$t.mcr";
        }

        return array_values(array_unique($files));
    }

    private function childPath(string $id): string
    {
        return $this->o->dir . '/' . $id . ($this->o->tiles->isTile($id) ? '.mcr' : '.mco');
    }

    /** The `core_hash` of a tile or a link file ('missing' when there is none). */
    private static function coreHash(string $path): string
    {
        if (!is_file($path)) {
            return 'missing';
        }
        $tile = str_ends_with($path, '.mcr');
        $f = new Container($path, $tile ? Container::MAGIC_TILE : Container::MAGIC_LINK);
        $sec = $f->sec[$tile ? 10 : 6];

        return bin2hex($f->read($sec[0] + 16, 20));
    }

    /**
     * The build key: the code, the options and the `core_hash` of every input. A child rebuilt with the same core hash (its
     * border, the ways between its border vertices, its cross arcs: nothing the link is made of has moved) leaves the key, and
     * the link, as they were.
     */
    private function key(): string
    {
        $parts = [self::CODE_VERSION, Builder::CODE_VERSION, $this->o->link, (string) $this->o->remainder, (string) $this->o->exportInterior, $this->o->profile, Builder::absolute($this->o->output)];
        foreach ($this->inputs() as $f) {
            $parts[] = basename($f) . ':' . self::coreHash($f);
        }

        return sha1(implode('|', $parts));
    }

    public function isDone(): bool
    {
        $s = $this->w->readJson('state.json');

        return $s !== null && ($s['phase'] ?? '') === 'done' && ($s['key'] ?? '') === $this->key() && is_file($this->o->output);
    }

    /** @throws BuilderBusy */
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
        $st = $this->w->readJson('state.json');
        if ($st !== null && ($st['phase'] ?? '') === 'done' && ($st['key'] ?? '') === $key && is_file($this->o->output)) {
            return new SliceReport(true, 'done', 0.0, 0, []);
        }
        if ($st === null || ($st['key'] ?? '') !== $key) {
            $this->w->clean();
            $st = ['phase' => 'load', 'key' => $key, 'nn' => 0, 'rank' => 0, 'slices' => []];
        }
        $done = [];
        $phase = Json::string($st, 'phase');
        while ($phase !== 'done') {
            if ($done !== [] && hrtime(true) > $deadline) {
                break;
            }
            memory_reset_peak_usage();
            $before = hrtime(true);
            $finished = match ($phase) {
                'load' => $this->load($st),
                'contract' => $this->contract($st, $deadline),
                'finish' => $this->finish($st),
                default => throw new RuntimeException("unknown phase $phase"),
            };
            $this->say(sprintf('phase %s %s in %.1fs, peak %.0f MB', $phase, $finished ? 'finished' : 'paused', (hrtime(true) - $before) / 1e9, memory_get_peak_usage(true) / 1048576));
            $done[] = $phase . ($finished ? '' : '*');
            if (!$finished) {
                break;
            }
            $phase = ['load' => 'contract', 'contract' => 'finish', 'finish' => 'done'][$phase];
            $st['phase'] = $phase;
            $this->w->writeJson('state.json', $st);
        }
        $seconds = (hrtime(true) - $t0) / 1e9;
        $peak = memory_get_peak_usage(true);
        $slices = Json::arr($st, 'slices');
        $slices[] = ['seconds' => round($seconds, 2), 'peak_bytes' => $peak, 'phases' => $done];
        $st['slices'] = $slices;
        $this->w->writeJson('state.json', $st);
        Io::putAtomic($this->o->output . '.slices.json', json_encode(['slices' => $slices], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return new SliceReport($phase === 'done', $phase, $seconds, $peak, $done);
    }

    /**
     * Reads the children and the cross arcs of the tiles below, and writes the arcs, the frozen flags, the vertex keys and the
     * table of original arcs.
     *
     * @param array<mixed> $st
     */
    private function load(array &$st): bool
    {
        $ts = $this->o->tiles;
        $link = $this->o->link;
        $children = $ts->childrenOf($link);
        if ($children === []) {
            throw new RuntimeException("$link is not a link of the tree");
        }
        $tileOfChild = [];
        foreach ($children as $ci => $c) {
            foreach ($ts->tilesBelow($c) as $t) {
                $tileOfChild[$ts->index($t)] = $ci;
            }
        }
        // exports of the children
        $keys = [];
        $exports = [];
        $childHashes = [];
        foreach ($children as $ci => $c) {
            $f = new Container($this->childPath($c), $ts->isTile($c) ? Container::MAGIC_TILE : Container::MAGIC_LINK);
            $bi = $ts->isTile($c) ? 9 : 5;
            $ci2 = $ts->isTile($c) ? 10 : 6;
            if (!$f->has($bi)) {
                throw new RuntimeException("{$f->path} has no border section: not a tile of format level 2");
            }
            $b = Exports::border($f->section($bi));
            $core = Exports::core($f->section($ci2));
            $exports[$ci] = ['b' => $b, 'core' => $core];
            $childHashes[] = ['id' => $c, 'core_hash' => bin2hex($core['hash'])];
            foreach ($b['keys'] as $k) {
                $keys[$k] = 0;
            }
        }
        ksort($keys, SORT_STRING);
        $vkeys = array_keys($keys);
        $n = \count($vkeys);
        $vidx = array_flip($vkeys);
        // cross arcs and the outer border, from the tiles below
        $below = [];
        foreach ($ts->tilesBelow($link) as $t) {
            $below[$ts->index($t)] = $t;
        }
        $frozen = str_repeat("\0", $n);
        $arcs = '';
        $orig = '';
        $na = 0;
        $tileHashes = [];
        /** @var array<int,array{int,int,int,int}> $seen cross arc (source * 2^32 + target) => weight, duration, distance, times seen */
        $seen = [];
        foreach ($below as $ti => $t) {
            $f = new Container($this->o->dir . "/$t.mcr", Container::MAGIC_TILE);
            $b = Exports::border($f->section(9));
            $tileHashes[] = ['id' => $t, 'core_hash' => bin2hex(substr($f->section(10), 16, 20))];
            foreach ($b['cross'] as $x) {
                $local = $b['keys'][$x['idx']];
                if (!isset($below[$x['tile']])) {
                    if (isset($vidx[$local])) {
                        $frozen[$vidx[$local]] = "\1"; // a way out of the link: on its outer border
                    }
                    continue;
                }
                if ($tileOfChild[$ti] === $tileOfChild[$x['tile']]) {
                    continue; // inside a child: its core has it
                }
                // each arc is in the files of both its tiles (leaving in one, entering in the other): it must be in both, alike
                if (!isset($vidx[$local]) || !isset($vidx[$x['other']])) {
                    throw new RuntimeException(sprintf(
                        'the cross arc %s -> %s of %s (kind %d, towards %s) has no twin: the other tile has no such vertex',
                        Key::show($x['kind'] === 0 ? $local : $x['other']),
                        Key::show($x['kind'] === 0 ? $x['other'] : $local),
                        $t,
                        $x['kind'],
                        $ts->ids[$x['tile']],
                    ));
                }
                [$src, $dst] = $x['kind'] === 0 ? [$vidx[$local], $vidx[$x['other']]] : [$vidx[$x['other']], $vidx[$local]];
                $pair = $src * 0x100000000 + $dst;
                if (isset($seen[$pair])) {
                    if (\array_slice($seen[$pair], 0, 3) !== [$x['w'], $x['t'], $x['d']]) {
                        throw new RuntimeException(sprintf('the two tiles see the cross arc %s -> %s differently', Key::show($vkeys[$src]), Key::show($vkeys[$dst])));
                    }
                    ++$seen[$pair][3];
                    continue;
                }
                $seen[$pair] = [$x['w'], $x['t'], $x['d'], 1];
                $arcs .= pack('V6', $src, $dst, $x['w'], $x['t'], $x['d'], $na);
                $orig .= pack('VVV', self::CROSS | ($x['kind'] === 0 ? $ti : $x['tile']), $src, $dst);
                ++$na;
            }
        }
        foreach ($seen as $pair => $v) {
            if ($v[3] !== 2) {
                throw new RuntimeException(sprintf('the cross arc %s -> %s is in the file of one tile only (it has no twin)', Key::show($vkeys[intdiv($pair, 0x100000000)]), Key::show($vkeys[$pair % 0x100000000])));
            }
        }
        foreach ($children as $ci => $c) {
            $b = $exports[$ci]['b'];
            $core = $exports[$ci]['core'];
            $local = [];
            foreach ($b['keys'] as $i => $k) {
                $local[$i] = $vidx[$k];
            }
            foreach ($b['keys'] as $i => $k) {
                for ($a = $core['first'][$i]; $a < $core['first'][$i + 1]; $a++) {
                    [$dst, $wt, $t, $d] = \array_slice($core['arcs'], $a * 5, 4);
                    $arcs .= pack('V6', $local[$i], $local[$dst], $wt, $t, $d, $na);
                    $orig .= pack('VVV', $ci, $local[$i], $local[$dst]);
                    ++$na;
                }
            }
        }
        $this->w->writeAtomic('arcs.bin', $arcs);
        $this->w->writeAtomic('orig.bin', $orig);
        $this->w->writeAtomic('frozen.bin', $frozen);
        $this->w->writeAtomic('vertices.bin', implode('', $vkeys));
        $this->w->writeJson('inputs.json', ['children' => $childHashes, 'tiles' => $tileHashes]);
        $st['nn'] = $n;
        $nf = substr_count($frozen, "\1");
        $this->say("load: $n vertices ($nf on the outer border), $na arcs from " . \count($children) . ' children, ' . \count($seen) . ' cross arcs, each in the files of both tiles');

        return true;
    }

    /** @param array<mixed> $st */
    private function contract(array &$st, int $deadline): bool
    {
        $nn = Json::int($st, 'nn');
        $key = Json::string($st, 'key');
        $paths = [$this->w->path('up.bin'), $this->w->path('upidx.bin'), $this->w->path('order.bin')];
        $checkpoint = $this->w->path('contract.state');
        if ($this->w->has('contract.state')) {
            $ch = Contractor::restoreFrom($checkpoint, $key, $nn, ...$paths);
        } else {
            $ch = Contractor::fromArcs($nn, $this->w->path('arcs.bin'), true, ...$paths);
            $frozen = (string) file_get_contents($this->w->path('frozen.bin'));
            $ch->setFreeze($frozen, false, substr_count($frozen, "\1") === 0 ? $this->o->remainder : $this->o->exportInterior);
        }
        $ch->setCorePath($this->w->path('core.bin'));
        $ch->setMemoryLimit($this->o->memoryLimit);
        $finished = $ch->run($deadline);
        $st['rank'] = $ch->rank();
        if (!$finished) {
            $ch->checkpointTo($checkpoint, $key);
            $this->say(sprintf('contract: %d of %d vertices', $ch->rank(), $nn));
        } else {
            $this->say("contract: {$ch->rank()} of $nn vertices contracted, {$ch->shortcuts} shortcuts");
            @unlink($checkpoint);
        }

        return $finished;
    }

    /** @param array<mixed> $st */
    private function finish(array &$st): bool
    {
        $n = Json::int($st, 'nn');
        $order = Bin::allU32((string) file_get_contents($this->w->path('order.bin')));
        $idx = Bin::allU64((string) file_get_contents($this->w->path('upidx.bin')));
        $nc = \count($order);
        $u = $n - $nc; // vertices left uncontracted: the outer border and the K_L interior vertices, or the remainder
        $contracted = array_fill(0, $n, false);
        foreach ($order as $x) {
            $contracted[$x] = true;
        }
        $newId = array_fill(0, $n, 0);
        $next = 0;
        for ($x = 0; $x < $n; $x++) {
            if (!$contracted[$x]) {
                $newId[$x] = $next++;
            }
        }
        for ($r = 0; $r < $nc; $r++) {
            $newId[$order[$r]] = $u + $nc - 1 - $r;
        }
        $up = (string) file_get_contents($this->w->path('up.bin'));
        $upSize = \strlen($up);
        $nodesSec = '';
        $fwd = '';
        $bwd = '';
        $nf = 0;
        $nb = 0;
        for ($ni = 0; $ni < $n; $ni++) {
            $nodesSec .= pack('VVV', $nf, $nb, 0);
            if ($ni < $u) {
                continue;
            }
            $r = $nc - 1 - ($ni - $u);
            $len = ($r + 1 < $nc ? $idx[$r + 1] : $upSize) - $idx[$r];
            $rec = Bin::allU32(substr($up, $idx[$r], $len));
            $cf = $rec[1];
            $cb = $rec[2];
            for ($k = 0, $p = 3; $k < $cf + $cb; $k++, $p += 5) {
                $mid = $rec[$p + 2];
                $a = pack('VVVVV', $newId[$rec[$p]], $rec[$p + 1], $rec[$p + 3], $rec[$p + 4], ($mid & Contractor::ORIGINAL) !== 0 ? $mid : $newId[$mid]);
                if ($k < $cf) {
                    $fwd .= $a;
                    ++$nf;
                } else {
                    $bwd .= $a;
                    ++$nb;
                }
            }
        }
        $nodesSec .= pack('VVV', $nf, $nb, 0);
        unset($up);
        $vkeysRaw = (string) file_get_contents($this->w->path('vertices.bin'));
        $frozenRaw = (string) file_get_contents($this->w->path('frozen.bin'));
        $vertices = '';
        $border = '';
        $hash = hash_init('sha1');
        $uKeys = [];
        $uIdx = [];
        for ($x = 0; $x < $n; $x++) {
            $k = substr($vkeysRaw, $x * Key::BYTES, Key::BYTES);
            $vertices .= $k . pack('V', $newId[$x]);
            if (!$contracted[$x]) {
                $uIdx[$x] = \count($uKeys);
                $uKeys[] = $k;
                $border .= $k . pack('VV', $newId[$x], $frozenRaw[$x] === "\1" ? Exports::OUTER : Exports::INTERIOR);
                hash_update($hash, $k);
            }
        }
        // core: the arcs among the uncontracted vertices
        $snap = (string) file_get_contents($this->w->path('core.bin'));
        /** @var array<int,list<array{int,int,int,int,int}>> $out */
        $out = [];
        for ($o = 0, $e = \strlen($snap); $o < $e;) {
            [$node, $cnt] = Bin::u32s($snap, 2, $o);
            $o += 8;
            for ($j = 0; $j < $cnt; $j++, $o += 20) {
                [$dst, $wt, $t, $d, $mid] = Bin::u32s($snap, 5, $o);
                $out[$uIdx[$node]][] = [$uIdx[$dst], $wt, $t, $d, ($mid & Contractor::ORIGINAL) !== 0 ? $mid : $newId[$mid]];
            }
        }
        $first = '';
        $arcs = '';
        $na = 0;
        foreach ($uKeys as $i => $k) {
            $first .= pack('V', $na);
            $list = $out[$i] ?? [];
            usort($list, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
            foreach ($list as [$dst, $wt, $t, $d, $mid]) {
                $arcs .= pack('VVVVV', $dst, $wt, $t, $d, $mid);
                hash_update($hash, 'a' . $k . $uKeys[$dst] . pack('NNN', $wt, $t, $d));
                ++$na;
            }
        }
        $first .= pack('V', $na);
        $digest = hash_final($hash, true);
        $frozenCount = substr_count($frozenRaw, "\1");
        $core = pack('VVVV', $u, $na, $frozenCount === 0 ? 1 : 0, 0) . str_pad($digest, 32, "\0") . $first . $arcs;
        $borderSec = pack('VVVV', $u, 0, 0, 0) . $border;
        $inputs = $this->w->readJson('inputs.json') ?? [];
        $meta = json_encode([
            'format' => 1,
            'format_level' => 2,
            'kind' => 'link',
            'link' => $this->o->link,
            'profile' => $this->o->profile,
            'vertices' => $n,
            'contracted' => $nc,
            'outer_border' => $frozenCount,
            'remainder' => $frozenCount === 0 ? $u : 0,
            'core_arcs' => $na,
            'core_hash' => bin2hex($digest),
            'exported' => $u,
            'children' => $inputs['children'] ?? [],
            'tiles' => $inputs['tiles'] ?? [],
            'built_at' => $this->o->builtAt ?? time(),
            'builder' => Format::BUILDER,
            'license' => Format::LICENSE,
            'attribution' => Format::ATTRIBUTION,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $orig = (string) file_get_contents($this->w->path('orig.bin'));
        $sections = [$nodesSec, $fwd, $bwd, $vertices, $orig, $borderSec, $core, $meta];
        $head = str_repeat("\0", Container::HEADER);
        $put = static function (int $off, int $v) use (&$head): void {
            $head = substr_replace($head, pack('V', $v & 0xFFFFFFFF), $off, 4);
        };
        $head = substr_replace($head, Container::MAGIC_LINK, 0, 4);
        $put(4, 1);
        $put(8, ['car' => 0, 'bike' => 1, 'foot' => 2, 'car-notoll' => 3][$this->o->profile] ?? 0);
        $put(12, 1);
        $put(16, $n);
        $put(20, $nf);
        $put(24, $nb);
        $put(28, $n);
        $put(32, intdiv(\strlen($orig), self::ORIG_BYTES));
        $put(36, $u);
        $put(60, $this->o->builtAt ?? time());
        $head = substr_replace($head, Format::BUILDER, 64, \strlen(Format::BUILDER));
        $z = substr($this->o->link, 0, 31);
        $head = substr_replace($head, $z, 96, \strlen($z));
        $l = substr(Format::LICENSE, 0, Format::LICENSE_LEN - 1);
        $head = substr_replace($head, $l, Format::LICENSE_OFFSET, \strlen($l));
        $off = Container::HEADER;
        $body = '';
        foreach ($sections as $i => $s) {
            $head = substr_replace($head, pack('PP', $off, \strlen($s)), 128 + $i * 16, 16);
            $pad = (4096 - \strlen($s) % 4096) % 4096;
            $body .= $s . str_repeat("\0", $pad);
            $off += \strlen($s) + $pad;
        }
        Io::putAtomic($this->o->output, $head . $body);
        $this->say("wrote {$this->o->output} ($n vertices, $nf + $nb arcs, $u uncontracted)");

        return true;
    }
}
