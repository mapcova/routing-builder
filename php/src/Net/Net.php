<?php

declare(strict_types=1);

namespace MapCova\GraphBuilder\Net;

use MapCova\GraphBuilder\Osm\NodesPass;
use MapCova\GraphBuilder\Osm\WaysPass;
use MapCova\GraphBuilder\Profile\Profile;
use MapCova\GraphBuilder\Profile\Way;
use MapCova\GraphBuilder\Support\Bin;
use MapCova\GraphBuilder\Support\F32;
use RuntimeException;

/**
 * The road network cut into segments (the stretch of a way between two junctions), shared by the edge-based (car)
 * and the node-based (bike, foot) graph builders. Port of `src/net.rs`. Directed segments are numbered
 * `segment * 2 + direction` (0 = along the way from its first node, 1 = against); durations and weights are in
 * tenths of a second, 0 = direction not allowed.
 */
final class Net
{
    public const NONE = 0xFFFFFFFF;
    public const SEG_RESTRICTED_FWD = 1;
    public const SEG_RESTRICTED_BWD = 2;
    /** Not a place to start or end a route (ferry, private service road). */
    public const SEG_NO_START = 4;

    /** @var string latitude and longitude of each node (1e-6 degree), two u32 each: strings, not arrays, to spare memory */
    public string $nodeLL = '';
    /** @var string flags of each node (`Profile::NODE_*`), one byte each */
    public string $nflags = '';
    /** @var string node index of each point of the segments (points of a segment are consecutive), u32 each */
    public string $ptNode = '';
    /** @var int number of nodes */
    public int $nNodes = 0;
    /** @var int number of points */
    public int $nPoints = 0;
    /** @var list<int> first point, point count */
    public array $segG0 = [];
    /** @var list<int> */
    public array $segCnt = [];
    /** @var list<int> node index of the start and the end of each segment */
    public array $segA = [];
    /** @var list<int> */
    public array $segB = [];
    /** @var list<int> */
    public array $segWay = [];
    /** @var list<float> metres */
    public array $segLen = [];
    /** @var list<int> metres, rounded */
    public array $lenM = [];
    /** @var list<int> duration of directed segment `s * 2 + d` */
    public array $dur = [];
    /** @var list<int> routing weight of directed segment `s * 2 + d` */
    public array $w = [];
    /** @var list<int> */
    public array $segFlags = [];
    /** @var list<int> */
    public array $wayIds = [];
    /** @var array<int,int> first segment of each way */
    public array $wayFirst = [];
    /** @var array<int,int> segment count of each way */
    public array $wayCnt = [];
    /** @var string junction of each node (`NONE` = not a junction), u32 each */
    public string $nodeJunc = '';
    /** @var list<int> node of each junction */
    public array $juncNode = [];
    /** @var array<int,int> */
    public array $incFirst = [];
    /** @var array<int,int> incident segments per junction: `segment * 2 + side` (side 0: the segment starts here) */
    public array $inc = [];
    /** @var list<array{int,int,int,bool}> restrictions at a node: from way, via node, to way, only */
    public array $nodeRestr = [];
    /** @var list<array{from:int,via:list<int>,to:int,only:bool}> restrictions through one way or more */
    public array $viaRestr = [];
    /** Segments of toll ways. */
    public int $tollSegments = 0;

    public function nseg(): int
    {
        return \count($this->segG0);
    }

    public function nj(): int
    {
        return \count($this->juncNode);
    }

    public function restricted(int $d): bool
    {
        return ($this->segFlags[$d >> 1] & (self::SEG_RESTRICTED_FWD << ($d & 1))) !== 0;
    }

    /** @return array{int,int} latitude and longitude of node `$n`, 1e-6 degree */
    public function coord(int $n): array
    {
        $u = Bin::ints('Vla/Vlo', $this->nodeLL, $n * 8);

        return [Bin::signed32($u['la']), Bin::signed32($u['lo'])];
    }

    public function flags(int $node): int
    {
        return \ord($this->nflags[$node]);
    }

    /** Junction of `$node` (`NONE` when it is not one). */
    public function junc(int $node): int
    {
        return Bin::u32($this->nodeJunc, $node * 4);
    }

    /** @return list<int> the nodes of the points of segment `$s` */
    public function pointNodes(int $s): array
    {
        return Bin::u32s($this->ptNode, $this->segCnt[$s], $this->segG0[$s] * 4);
    }

    /** @return list<array{int,int}> the points of segment `$s` as (lat, lon) */
    public function points(int $s): array
    {
        $out = [];
        foreach ($this->pointNodes($s) as $n) {
            $out[] = $this->coord($n);
        }

        return $out;
    }

    /**
     * @return list<int> the segments (with side) touching junction `$j`
     */
    public function incident(int $j): array
    {
        return \array_slice($this->inc, $this->incFirst[$j], $this->incFirst[$j + 1] - $this->incFirst[$j]);
    }

    /**
     * Reads the node records written by the nodes pass: the index of each OSM id comes back (the only hash table of
     * the build, dropped once the ways are cut), coordinates and flags are kept in strings.
     *
     * @return array<int,int> OSM node id => node index
     */
    private function loadNodes(string $path): array
    {
        $f = self::open($path);
        $index = [];
        $count = 0;
        $ll = '';
        $fl = '';
        while (($raw = fread($f, NodesPass::RECORD * 8192)) !== false && $raw !== '') {
            $n = intdiv(\strlen($raw), NodesPass::RECORD);
            for ($i = 0; $i < $n; $i++) {
                $o = $i * NodesPass::RECORD;
                $index[Bin::u64($raw, $o)] = $count++;
                $ll .= substr($raw, $o + 8, 8);
                $fl .= $raw[$o + 16];
            }
        }
        fclose($f);
        $this->nodeLL = $ll;
        $this->nflags = $fl;
        $this->nNodes = $count;

        return $index;
    }

    /** @return resource */
    private static function open(string $path)
    {
        $f = @fopen($path, 'rb');
        if ($f === false) {
            throw new RuntimeException("cannot open $path");
        }

        return $f;
    }

    /**
     * Reads the way records: `[way id, refs (node ids), Way]`, one at a time.
     *
     * @return \Generator<int,array{int,list<int>,Way}>
     */
    private static function readWays(string $path): \Generator
    {
        $f = self::open($path);
        $i = 0;
        while (($h = fread($f, WaysPass::WAY_HEADER)) !== false && \strlen($h) === WaysPass::WAY_HEADER) {
            $u = Bin::ints('Pid/Vn/x16/Cflags', $h);
            $n = $u['n'];
            $flags = $u['flags'];
            yield $i++ => [
                $u['id'],
                $n > 0 ? Bin::list('P' . $n, (string) fread($f, $n * 8)) : [],
                new Way(
                    Bin::f32($h, 12),
                    Bin::f32($h, 16),
                    Bin::f32($h, 20),
                    Bin::f32($h, 24),
                    ($flags & 1) !== 0,
                    ($flags & 8) !== 0 ? Bin::f32($h, 29) : null,
                    ($flags & 2) !== 0,
                    ($flags & 4) !== 0,
                ),
            ];
        }
        fclose($f);
    }

    /**
     * Restrictions, sorted as the Rust builder sorts them (from, to, via node, via ways, only).
     *
     * @return list<array{from:int,to:int,viaNode:int,only:bool,viaWays:list<int>}>
     */
    private static function readRestrictions(string $path): array
    {
        $out = [];
        if (!is_file($path)) {
            return $out;
        }
        $raw = (string) file_get_contents($path);
        $p = 0;
        $len = \strlen($raw);
        while ($p + WaysPass::RESTRICTION_HEADER <= $len) {
            $u = Bin::ints('Pfrom/Pto/PviaNode/Conly/Vn', $raw, $p);
            $p += WaysPass::RESTRICTION_HEADER;
            $n = $u['n'];
            $via = [];
            if ($n > 0) {
                $via = Bin::list('P' . $n, $raw, $p);
                $p += $n * 8;
            }
            $out[] = [
                'from' => $u['from'],
                'to' => $u['to'],
                'viaNode' => $u['viaNode'],
                'only' => $u['only'] === 1,
                'viaWays' => $via,
            ];
        }
        usort($out, static function (array $a, array $b): int {
            $c = $a['from'] <=> $b['from'] ?: $a['to'] <=> $b['to'] ?: $a['viaNode'] <=> $b['viaNode'];
            if ($c !== 0) {
                return $c;
            }
            $na = \count($a['viaWays']);
            $nb = \count($b['viaWays']);
            for ($i = 0; $i < $na && $i < $nb; $i++) {
                $c = $a['viaWays'][$i] <=> $b['viaWays'][$i];
                if ($c !== 0) {
                    return $c;
                }
            }

            return $na <=> $nb ?: (int) $a['only'] <=> (int) $b['only'];
        });

        return $out;
    }

    /**
     * Duration and weight of one direction of a segment.
     *
     * @return array{int,int}
     */
    private static function durW(Way $way, float $speed, float $rate, float $len, float $wayLen): array
    {
        if ($speed <= 0.0) {
            return [0, 0];
        }
        if ($way->duration !== null) {
            $secs = $wayLen > 0.0 ? $way->duration * $len / $wayLen : $way->duration;
        } else {
            $secs = $len / ($speed / 3.6);
        }
        $dur = max(round($secs * 10.0), 1.0);

        return [(int) $dur, (int) round($dur / max($rate, F32::r(0.01)))];
    }

    public static function build(string $waysPath, string $nodesPath, string $restrictionsPath, Profile $profile): self
    {
        $net = new self();
        $index = $net->loadNodes($nodesPath);
        $n = $net->nNodes;
        $nflags = $net->nflags;

        // way ids and the number of ways each node is on (a byte each, saturating)
        $wayIds = [];
        $cnt = str_repeat("\0", $n);
        foreach (self::readWays($waysPath) as [$id, $refs]) {
            $wayIds[] = $id;
            foreach ($refs as $r) {
                if (isset($index[$r])) {
                    $i = $index[$r];
                    $c = \ord($cnt[$i]);
                    if ($c < 255) {
                        $cnt[$i] = \chr($c + 1);
                    }
                }
            }
        }
        $wayIndex = array_flip($wayIds);
        foreach (self::readRestrictions($restrictionsPath) as $r) {
            if (!isset($wayIndex[$r['from']]) || !isset($wayIndex[$r['to']])) {
                continue;
            }
            $f = $wayIndex[$r['from']];
            $t = $wayIndex[$r['to']];
            if ($r['viaNode'] >= 0) {
                if (isset($index[$r['viaNode']])) {
                    $v = $index[$r['viaNode']];
                    $nflags[$v] = \chr(\ord($nflags[$v]) | Profile::NODE_RESTRICTION);
                    $net->nodeRestr[] = [$f, $v, $t, $r['only']];
                }
            } else {
                $via = [];
                foreach ($r['viaWays'] as $w) {
                    if (isset($wayIndex[$w])) {
                        $via[] = $wayIndex[$w];
                    }
                }
                if (\count($via) === \count($r['viaWays'])) {
                    $net->viaRestr[] = ['from' => $f, 'via' => $via, 'to' => $t, 'only' => $r['only']];
                }
            }
        }
        unset($wayIndex);
        $net->nflags = $nflags;
        $net->wayIds = $wayIds;
        $nWays = \count($wayIds);
        $net->wayFirst = array_fill(0, $nWays, 0);
        $net->wayCnt = array_fill(0, $nWays, 0);

        $segG0 = [];
        $segCnt = [];
        $segA = [];
        $segB = [];
        $segWay = [];
        $segLen = [];
        $lenM = [];
        $dur = [];
        $wt = [];
        $segFlags = [];
        $ptNode = '';
        $nPoints = 0;
        $toll = 0;
        $ll = $net->nodeLL;
        foreach (self::readWays($waysPath) as $wi => [, $refs, $way]) {
            $net->wayFirst[$wi] = \count($segG0);
            $wr = [];
            foreach ($refs as $r) {
                if (isset($index[$r])) {
                    $wr[] = $index[$r];
                }
            }
            $len = \count($wr);
            if ($len < 2) {
                continue;
            }
            $lat = [];
            $lon = [];
            foreach ($wr as $k => $nd) {
                $u = Bin::ints('Vla/Vlo', $ll, $nd * 8);
                $lat[$k] = Bin::signed32($u['la']);
                $lon[$k] = Bin::signed32($u['lo']);
            }
            $wayLen = 0.0;
            for ($k = 1; $k < $len; $k++) {
                $wayLen += Geom::haversine($lat[$k - 1], $lon[$k - 1], $lat[$k], $lon[$k]);
            }
            $start = 0;
            for ($p = 1; $p < $len; $p++) {
                $node = $wr[$p];
                if (!($p + 1 === $len || \ord($cnt[$node]) >= 2 || \ord($nflags[$node]) !== 0)) {
                    continue;
                }
                $g0 = $nPoints;
                $segLength = 0.0;
                for ($q = $start; $q <= $p; $q++) {
                    if ($q > $start) {
                        $segLength += Geom::haversine($lat[$q - 1], $lon[$q - 1], $lat[$q], $lon[$q]);
                    }
                    $ptNode .= pack('V', $wr[$q]);
                    $nPoints++;
                }
                [$df, $wf] = self::durW($way, $way->fwdSpeed, $way->fwdRate, $segLength, $wayLen);
                [$db, $wb] = self::durW($way, $way->bwdSpeed, $way->bwdRate, $segLength, $wayLen);
                $fl = 0;
                if ($way->restricted) {
                    $fl |= self::SEG_RESTRICTED_FWD | self::SEG_RESTRICTED_BWD;
                }
                if (!$way->startpoint) {
                    $fl |= self::SEG_NO_START;
                }
                $segG0[] = $g0;
                $segCnt[] = $p - $start + 1;
                $segA[] = $wr[$start];
                $segB[] = $node;
                $segWay[] = $wi;
                $segLen[] = $segLength;
                $lenM[] = (int) round($segLength);
                $dur[] = $df;
                $dur[] = $db;
                $wt[] = $wf;
                $wt[] = $wb;
                $segFlags[] = $fl;
                $toll += $way->toll ? 1 : 0;
                $start = $p;
            }
            $net->wayCnt[$wi] = \count($segG0) - $net->wayFirst[$wi];
        }
        unset($index, $cnt);
        $net->ptNode = $ptNode;
        $net->nPoints = $nPoints;
        $net->segG0 = $segG0;
        $net->segCnt = $segCnt;
        $net->segA = $segA;
        $net->segB = $segB;
        $net->segWay = $segWay;
        $net->segLen = $segLen;
        $net->lenM = $lenM;
        $net->dur = $dur;
        $net->w = $wt;
        $net->segFlags = $segFlags;
        $net->tollSegments = $toll;

        $nodeJunc = str_repeat("\xFF", $n * 4);
        $juncNode = [];
        $setJunc = static function (int $node, int $j) use (&$nodeJunc): void {
            $o = $node * 4;
            $nodeJunc[$o] = \chr($j & 0xFF);
            $nodeJunc[$o + 1] = \chr(($j >> 8) & 0xFF);
            $nodeJunc[$o + 2] = \chr(($j >> 16) & 0xFF);
            $nodeJunc[$o + 3] = \chr(($j >> 24) & 0xFF);
        };
        $jOf = [];
        foreach ($segA as $s => $a) {
            foreach ([$a, $segB[$s]] as $x) {
                if (!isset($jOf[$x])) {
                    $jOf[$x] = \count($juncNode);
                    $setJunc($x, $jOf[$x]);
                    $juncNode[] = $x;
                }
            }
        }
        $nj = \count($juncNode);
        $first = array_fill(0, $nj + 1, 0);
        foreach ($segA as $s => $a) {
            $first[$jOf[$a] + 1]++;
            $first[$jOf[$segB[$s]] + 1]++;
        }
        for ($i = 0; $i < $nj; $i++) {
            $first[$i + 1] += $first[$i];
        }
        $fill = $first;
        $inc = array_fill(0, $first[$nj], 0);
        foreach ($segA as $s => $a) {
            $ja = $jOf[$a];
            $inc[$fill[$ja]++] = $s * 2;
            $jb = $jOf[$segB[$s]];
            $inc[$fill[$jb]++] = $s * 2 + 1;
        }
        unset($jOf);
        $net->nodeJunc = $nodeJunc;
        $net->juncNode = $juncNode;
        $net->incFirst = $first;
        $net->inc = $inc;

        return $net;
    }
}
