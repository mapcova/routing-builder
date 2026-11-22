<?php

declare(strict_types=1);

namespace MapCova\GraphBuilder\Osm;

use MapCova\GraphBuilder\Pbf\Block;
use MapCova\GraphBuilder\Pbf\Wire;
use MapCova\GraphBuilder\Profile\Profile;
use MapCova\GraphBuilder\Support\Bin;
use RuntimeException;

/**
 * Second pass over an extract: coordinates and flags of the nodes the ways use, found by a sorted merge of the
 * extract (nodes are sorted by id) with the sorted list of needed ids. Memory is one block, whatever the extract.
 *
 * Node record (`nodes.bin`, 17 bytes): id u64, lat u32, lon u32 (1e-6 degree, two's complement), flags u8.
 */
final class NodesPass
{
    public const RECORD = 17;
    private const CHUNK = 65536;

    /** @var resource */
    private $needed;
    /** @var list<int> */
    private array $buf = [];
    private int $bufPos = 0;
    private int $neededIndex;
    private int $lastId = -1;

    public function __construct(string $neededPath, private readonly Profile $profile, int $neededIndex = 0)
    {
        $f = @fopen($neededPath, 'rb');
        if ($f === false) {
            throw new RuntimeException("cannot open $neededPath");
        }
        $this->needed = $f;
        fseek($f, $neededIndex * 8);
        $this->neededIndex = $neededIndex;
    }

    public function __destruct()
    {
        fclose($this->needed);
    }

    /** Index in the needed list of the next id to find (the resume point after a block). */
    public function neededIndex(): int
    {
        return $this->neededIndex;
    }

    private function peek(): ?int
    {
        if ($this->bufPos >= \count($this->buf)) {
            $raw = fread($this->needed, 8 * self::CHUNK);
            if ($raw === false || $raw === '') {
                return null;
            }
            $this->buf = Bin::allU64($raw);
            $this->bufPos = 0;
            if ($this->buf === []) {
                return null;
            }
        }

        return $this->buf[$this->bufPos];
    }

    /**
     * Needed ids in `[lo, hi]`, consumed (smaller ones are dropped: they are not in the extract).
     *
     * @return list<int>
     */
    private function takeUpTo(int $lo, int $hi): array
    {
        $out = [];
        while (($id = $this->peek()) !== null && $id <= $hi) {
            $this->bufPos++;
            $this->neededIndex++;
            if ($id >= $lo) {
                $out[] = $id;
            }
        }

        return $out;
    }

    /** Records (see the class) for the needed nodes of one block, in id order. */
    public function process(Block $b): string
    {
        $out = '';
        $d = $b->data;
        foreach ($b->groups as [$field, $s, $e]) {
            if ($field === 2) {
                $out .= $this->dense($b, $s, $e);
            } elseif ($field === 1) {
                $out .= $this->plain($b, $s, $e);
            }
        }

        return $out;
    }

    private function check(int $first, int $last): void
    {
        if ($first <= $this->lastId) {
            throw new RuntimeException('the nodes of the extract are not sorted by id (sort it with osmium sort)');
        }
        $this->lastId = $last;
    }

    private static function coord(int $nano): int
    {
        $dm = intdiv($nano, 100);

        return intdiv($dm + ($dm >= 0 ? 5 : -5), 10);
    }

    private function dense(Block $b, int $p, int $e): string
    {
        $d = $b->data;
        $ids = null;
        $las = null;
        $los = null;
        $kv = null;
        while ($p < $e) {
            $key = \ord($d[$p++]);
            $field = $key >> 3;
            $wt = $key & 7;
            if ($wt !== 2) {
                Wire::skip($d, $p, $wt);
                continue;
            }
            $len = Wire::varint($d, $p);
            if ($field === 1) {
                $ids = Wire::packedDelta($d, $p, $p + $len);
            } elseif ($field === 8) {
                $las = [$p, $p + $len];
            } elseif ($field === 9) {
                $los = [$p, $p + $len];
            } elseif ($field === 10) {
                $kv = [$p, $p + $len];
            }
            $p += $len;
        }
        if ($ids === null || $ids === [] || $las === null || $los === null) {
            return '';
        }
        $count = \count($ids);
        $this->check($ids[0], $ids[$count - 1]);
        $want = $this->takeUpTo($ids[0], $ids[$count - 1]);
        if ($want === []) {
            return '';
        }
        $set = array_flip($want);
        $lat = Wire::packedDelta($d, $las[0], $las[1]);
        $lon = Wire::packedDelta($d, $los[0], $los[1]);
        $kvs = $kv !== null ? Wire::packed($d, $kv[0], $kv[1]) : [];
        $nkv = \count($kvs);
        $st = $b->strings;
        $out = '';
        $kp = 0;
        foreach ($ids as $i => $id) {
            $tagStart = $kp;
            if ($nkv > 0) {
                while ($kp < $nkv && $kvs[$kp] !== 0) {
                    $kp += 2;
                }
                $kp++;
            }
            if (!isset($set[$id])) {
                continue;
            }
            $flags = 0;
            if ($nkv > 0 && $kvs[$tagStart] !== 0) {
                $tags = [];
                for ($q = $tagStart; $q < $kp - 1; $q += 2) {
                    $tags[] = [$st[$kvs[$q]], $st[$kvs[$q + 1]]];
                }
                $flags = $this->profile->nodeFlags($tags);
            }
            $out .= pack(
                'PVVC',
                $id,
                self::coord($b->latOffset + $b->granularity * $lat[$i]) & 0xFFFFFFFF,
                self::coord($b->lonOffset + $b->granularity * $lon[$i]) & 0xFFFFFFFF,
                $flags,
            );
        }

        return $out;
    }

    private function plain(Block $b, int $p, int $e): string
    {
        $d = $b->data;
        $id = 0;
        $la = 0;
        $lo = 0;
        $ks = null;
        $vs = null;
        while ($p < $e) {
            $key = \ord($d[$p++]);
            $field = $key >> 3;
            $wt = $key & 7;
            if ($wt === 0) {
                $v = Wire::varint($d, $p);
                if ($field === 1) {
                    $id = Wire::zigzag($v);
                } elseif ($field === 8) {
                    $la = Wire::zigzag($v);
                } elseif ($field === 9) {
                    $lo = Wire::zigzag($v);
                }
            } elseif ($wt === 2) {
                $len = Wire::varint($d, $p);
                if ($field === 2) {
                    $ks = [$p, $p + $len];
                } elseif ($field === 3) {
                    $vs = [$p, $p + $len];
                }
                $p += $len;
            } else {
                Wire::skip($d, $p, $wt);
            }
        }
        $this->check($id, $id);
        if ($this->takeUpTo($id, $id) === []) {
            return '';
        }
        $flags = 0;
        if ($ks !== null && $vs !== null) {
            $keys = Wire::packed($d, $ks[0], $ks[1]);
            $vals = Wire::packed($d, $vs[0], $vs[1]);
            $tags = [];
            foreach ($keys as $i => $k) {
                $tags[] = [$b->strings[$k], $b->strings[$vals[$i]]];
            }
            $flags = $this->profile->nodeFlags($tags);
        }

        return pack(
            'PVVC',
            $id,
            self::coord($b->latOffset + $b->granularity * $la) & 0xFFFFFFFF,
            self::coord($b->lonOffset + $b->granularity * $lo) & 0xFFFFFFFF,
            $flags,
        );
    }
}
