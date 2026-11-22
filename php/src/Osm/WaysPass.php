<?php

declare(strict_types=1);

namespace MapCova\GraphBuilder\Osm;

use MapCova\GraphBuilder\Pbf\Block;
use MapCova\GraphBuilder\Pbf\Wire;
use MapCova\GraphBuilder\Profile\Profile;
use MapCova\GraphBuilder\Support\Bin;
use RuntimeException;

/**
 * First pass over an extract: the ways the profile keeps and the turn restrictions, as binary records.
 *
 * Way record (`ways.bin`): id u64, node count u32, speed and rate of both directions as four f32, flags u8
 * (bit 0 local access only, bit 1 start point, bit 2 toll, bit 3 has a duration), duration f32, then the node ids
 * as u64 each. Restriction record (`restrictions.bin`): from way u64, to way u64, via node u64 (-1 = none), only
 * u8, via way count u32, then the via ways as u64 each.
 */
final class WaysPass
{
    public const WAY_HEADER = 33;
    public const RESTRICTION_HEADER = 29;

    /** @param int $lastWayId id of the last way kept by an earlier launch (-1 at the start): ids must keep rising */
    public function __construct(private readonly Profile $profile, private int $lastWayId = -1)
    {
    }

    public function lastWayId(): int
    {
        return $this->lastWayId;
    }

    /** @return array{ways:string,restrictions:string,nWays:int,nRestrictions:int} */
    public function process(Block $b): array
    {
        $ways = '';
        $restrictions = '';
        $nWays = 0;
        $nRestr = 0;
        $st = $b->strings;
        $d = $b->data;
        $interest = [];
        foreach ($st as $i => $s) {
            if (Profile::wayKeyOfInterest($s)) {
                $interest[$i] = true;
            }
        }
        $restrIdx = array_search('restriction', $st, true);
        $typeIdx = array_search('type', $st, true);
        foreach ($b->groups as [$field, $s, $e]) {
            if ($field === 3 && $interest !== []) {
                $rec = $this->way($d, $st, $interest, $s, $e);
                if ($rec !== null) {
                    $ways .= $rec;
                    $nWays++;
                }
            } elseif ($field === 4 && $this->profile->edgeBased() && $restrIdx !== false && $typeIdx !== false) {
                $rec = $this->relation($d, $st, $s, $e);
                if ($rec !== null) {
                    $restrictions .= $rec;
                    $nRestr++;
                }
            }
        }

        return ['ways' => $ways, 'restrictions' => $restrictions, 'nWays' => $nWays, 'nRestrictions' => $nRestr];
    }

    /**
     * @param list<string> $st
     * @param array<int,true> $interest
     */
    private function way(string $d, array $st, array $interest, int $p, int $e): ?string
    {
        $id = 0;
        $ks = null;
        $vs = null;
        $refs = null;
        while ($p < $e) {
            $key = \ord($d[$p++]);
            $field = $key >> 3;
            $wt = $key & 7;
            if ($wt === 0) {
                $v = Wire::varint($d, $p);
                if ($field === 1) {
                    $id = $v;
                }
            } elseif ($wt === 2) {
                $len = Wire::varint($d, $p);
                if ($field === 2) {
                    $ks = [$p, $p + $len];
                } elseif ($field === 3) {
                    $vs = [$p, $p + $len];
                } elseif ($field === 8) {
                    $refs = [$p, $p + $len];
                }
                $p += $len;
            } else {
                Wire::skip($d, $p, $wt);
            }
        }
        if ($ks === null || $vs === null || $refs === null) {
            return null;
        }
        $keys = Wire::packed($d, $ks[0], $ks[1]);
        $any = false;
        foreach ($keys as $k) {
            if (isset($interest[$k])) {
                $any = true;
                break;
            }
        }
        if (!$any) {
            return null;
        }
        $vals = Wire::packed($d, $vs[0], $vs[1]);
        $tags = [];
        foreach ($keys as $i => $k) {
            $key = $st[$k];
            if (!isset($tags[$key])) {
                $tags[$key] = $st[$vals[$i]];
            }
        }
        $way = $this->profile->way($tags);
        if ($way === null) {
            return null;
        }
        if ($id <= $this->lastWayId) {
            // the Rust builder sorts the ways it reads; reading in file order numbers the same only if they are sorted
            throw new RuntimeException("the ways of the extract are not sorted by id (way $id after {$this->lastWayId}): sort it with osmium sort");
        }
        $this->lastWayId = $id;
        $nodes = Wire::packedDelta($d, $refs[0], $refs[1]);
        $flags = ($way->restricted ? 1 : 0) | ($way->startpoint ? 2 : 0) | ($way->toll ? 4 : 0)
            | ($way->duration !== null ? 8 : 0);

        return pack('PVggggCg', $id, \count($nodes), $way->fwdSpeed, $way->fwdRate, $way->bwdSpeed, $way->bwdRate, $flags, $way->duration ?? 0.0)
            . Bin::packAll('P', $nodes);
    }

    /** @param list<string> $st */
    private function relation(string $d, array $st, int $p, int $e): ?string
    {
        $ks = null;
        $vs = null;
        $roles = null;
        $mem = null;
        $types = null;
        while ($p < $e) {
            $key = \ord($d[$p++]);
            $field = $key >> 3;
            $wt = $key & 7;
            if ($wt === 0) {
                Wire::varint($d, $p);
            } elseif ($wt === 2) {
                $len = Wire::varint($d, $p);
                match ($field) {
                    2 => $ks = [$p, $p + $len],
                    3 => $vs = [$p, $p + $len],
                    8 => $roles = [$p, $p + $len],
                    9 => $mem = [$p, $p + $len],
                    10 => $types = [$p, $p + $len],
                    default => null,
                };
                $p += $len;
            } else {
                Wire::skip($d, $p, $wt);
            }
        }
        if ($ks === null || $vs === null) {
            return null;
        }
        $keys = Wire::packed($d, $ks[0], $ks[1]);
        $vals = Wire::packed($d, $vs[0], $vs[1]);
        $isR = false;
        $val = '';
        $exempt = false;
        foreach ($keys as $i => $k) {
            $v = $st[$vals[$i]];
            switch ($st[$k]) {
                case 'type':
                    $isR = $v === 'restriction';
                    break;
                case 'restriction':
                    $val = $v;
                    break;
                case 'restriction:motorcar':
                case 'restriction:motor_vehicle':
                    $val = $v; // the profile is edge-based: the callers only read relations for those
                    break;
                case 'except':
                    $exempt = $this->profile->exempt($v);
                    break;
            }
        }
        if (!$isR || $exempt || !(str_starts_with($val, 'no_') || str_starts_with($val, 'only_'))
            || $roles === null || $mem === null || $types === null) {
            return null;
        }
        $roleIdx = Wire::packed($d, $roles[0], $roles[1]);
        $ids = Wire::packedDelta($d, $mem[0], $mem[1]);
        $kinds = Wire::packed($d, $types[0], $types[1]);
        $from = null;
        $to = null;
        $viaNode = null;
        $viaWays = [];
        foreach ($ids as $i => $id) {
            $role = $st[$roleIdx[$i]] ?? '';
            $kind = $kinds[$i] ?? -1;
            if ($kind === 1 && $role === 'from') {
                $from = $id;
            } elseif ($kind === 1 && $role === 'to') {
                $to = $id;
            } elseif ($kind === 0 && $role === 'via') {
                $viaNode = $id;
            } elseif ($kind === 1 && $role === 'via') {
                $viaWays[] = $id;
            }
        }
        if ($from === null || $to === null || ($viaNode !== null) === ($viaWays !== [])) {
            return null;
        }

        return pack('PPPCV', $from, $to, $viaNode ?? -1, str_starts_with($val, 'only_') ? 1 : 0, \count($viaWays))
            . ($viaWays === [] ? '' : Bin::packAll('P', $viaWays));
    }
}
