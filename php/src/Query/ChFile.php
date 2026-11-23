<?php

declare(strict_types=1);

namespace MapCova\GraphBuilder\Query;

use MapCova\GraphBuilder\Mcr\Container;
use MapCova\GraphBuilder\Support\Bin;
use RuntimeException;

/** A file that holds a contraction hierarchy (sections 0 to 2 are `nodes`, `up_fwd`, `up_bwd` in tiles and links alike). */
abstract class ChFile extends Container
{
    public const ARC = 20;
    public const ORIGINAL = 0x80000000;

    /** @var array<int,list<int>> */
    private array $fwdCache = [];
    /** @var array<int,list<int>> */
    private array $bwdCache = [];

    /**
     * The upward arcs of a node, flat: target, weight, duration, distance, middle, ... (`$fwd`: leaving it; else entering it
     * from a higher node).
     *
     * @return list<int>
     */
    public function arcs(int $node, bool $fwd): array
    {
        if ($fwd) {
            if (isset($this->fwdCache[$node])) {
                return $this->fwdCache[$node];
            }
        } elseif (isset($this->bwdCache[$node])) {
            return $this->bwdCache[$node];
        }
        $x = Bin::u32s($this->read($this->sec[0][0] + $node * 12, 24), 6);
        $from = $fwd ? $x[0] : $x[1];
        $to = $fwd ? $x[3] : $x[4];
        $out = $to > $from ? Bin::allU32($this->read($this->sec[$fwd ? 1 : 2][0] + $from * self::ARC, ($to - $from) * self::ARC)) : [];
        if ($fwd) {
            $this->fwdCache[$node] = $out;
        } else {
            $this->bwdCache[$node] = $out;
        }

        return $out;
    }

    public function clearCaches(): void
    {
        $this->fwdCache = [];
        $this->bwdCache = [];
    }

    /**
     * Calls `$original(u, v, middle)` for every original arc the arc `u -> v` (with middle `$mid`) stands for, in travel order.
     *
     * @param callable(int,int,int):void $original
     */
    public function unpackArc(int $u, int $v, int $mid, callable $original): void
    {
        if (($mid & self::ORIGINAL) !== 0) {
            $original($u, $v, $mid);

            return;
        }
        $bw = $this->arcs($mid, false);
        $fw = $this->arcs($mid, true);
        $m1 = $m2 = null;
        for ($i = 0, $c = \count($bw); $i < $c; $i += 5) {
            if ($bw[$i] === $u && ($m1 === null || $bw[$i + 1] < $m1[0])) {
                $m1 = [$bw[$i + 1], $bw[$i + 4]];
            }
        }
        for ($i = 0, $c = \count($fw); $i < $c; $i += 5) {
            if ($fw[$i] === $v && ($m2 === null || $fw[$i + 1] < $m2[0])) {
                $m2 = [$fw[$i + 1], $fw[$i + 4]];
            }
        }
        if ($m1 === null || $m2 === null) {
            throw new RuntimeException("{$this->path}: corrupt shortcut");
        }
        $this->unpackArc($u, $mid, $m1[1], $original);
        $this->unpackArc($mid, $v, $m2[1], $original);
    }
}
