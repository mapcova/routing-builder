<?php

declare(strict_types=1);

namespace MapCova\GraphBuilder\Build;

use MapCova\GraphBuilder\Net\Arcs;
use MapCova\GraphBuilder\Net\EdgeGraph;
use MapCova\GraphBuilder\Net\Net;
use MapCova\GraphBuilder\Support\Bin;
use MapCova\GraphBuilder\Tiles\Key;
use MapCova\GraphBuilder\Tiles\TileSet;
use RuntimeException;

/**
 * Cuts the graph of an extract (the tile and a margin around it) down to the tile: a road segment belongs to the tile that
 * owns its first point (`TileSet::ownerOf`), a graph node is kept when its segment is, an arc is kept when both its ends
 * are. An arc with one end in another tile is a *cross arc*; its end in this tile is a *border vertex*, and the border
 * vertices are frozen during the contraction of the tile (`frozen.bin`). Both tiles that share a cross arc compute it from
 * the same roads, so it is the same arc in both (`tools/tile-extract.sh` keeps every road that touches a node of the
 * extract, and `--junctions` fixes where the roads are cut, so that the segments, their keys and the turn costs agree);
 * the tile writes it with the stable key (`Key`) of the other end.
 *
 * Files: `frozen.bin` (one byte per kept node), `border.bin` (sorted by key: key, node, flags 1 = a cross arc leaves it,
 * 2 = one enters it), `cross.bin` (node, kind 0 out / 1 in, key of the other end, tile index of the other end, weight,
 * duration, distance), `keys.bin` (the key of every kept node, 28 bytes each in node order: the tile exports, besides its
 * border vertices, interior nodes that the contraction chooses, and they need their keys too).
 */
final class TileCut
{
    public const CROSS_BYTES = 52;
    public const BORDER_BYTES = 36;

    /** @var list<int> directed segment of each kept node */
    public array $segdir = [];
    /** @var array<int,int> directed segment -> kept node, `NONE` when absent or in another tile */
    public array $cid = [];
    /** @var list<array{int,int}> */
    public array $shadows = [];
    public Arcs $arcs;
    /** @var list<bool> segment owned by the tile */
    public array $owned = [];
    public int $borderVertices = 0;
    public int $crossArcs = 0;
    public int $foreignNodes = 0;
    /** @var list<int> nodes where a cross arc leaves the tile */
    public array $exits = [];
    /** @var list<int> nodes where a cross arc enters the tile */
    public array $entries = [];

    public static function apply(Workdir $w, Net $net, EdgeGraph $g, TileSet $ts, int $mine): self
    {
        $r = new self();
        $nseg = $net->nseg();
        $segOwner = [];
        for ($s = 0; $s < $nseg; $s++) {
            [$la, $lo] = $net->coord($net->segA[$s]);
            $segOwner[] = $ts->ownerOf($la, $lo);
            $r->owned[] = $segOwner[$s] === $mine;
        }
        $n = \count($g->cnodes);
        $newId = array_fill(0, $n, -1);
        /** @var list<int> $old graph node of the extract of each kept node */
        $old = [];
        foreach ($g->cnodes as $i => $sd) {
            if ($r->owned[$sd >> 1]) {
                $newId[$i] = \count($r->segdir);
                $r->segdir[] = $sd;
                $old[] = $i;
            }
        }
        $r->foreignNodes = $n - \count($r->segdir);
        $r->cid = array_fill(0, $nseg * 2, Net::NONE);
        foreach ($g->cid as $d => $c) {
            if ($c !== Net::NONE && $newId[$c] >= 0) {
                $r->cid[$d] = $newId[$c];
            }
        }
        foreach ($g->shadows as [$o, $c]) {
            if ($newId[$o] >= 0 && $newId[$c] >= 0) {
                $r->shadows[] = [$newId[$o], $newId[$c]];
            }
        }

        // keys of the copies: numbered by the key of the segment the restricted sequence starts from
        $copyOrd = [];
        $byOrig = [];
        foreach ($g->copyFrom as $copy => $a) {
            $byOrig[$g->cnodes[$copy]][] = [self::plain($net, $a), $copy];
        }
        foreach ($byOrig as $list) {
            sort($list);
            foreach ($list as $k => [, $copy]) {
                $copyOrd[$copy] = $k + 1;
            }
        }
        $keyOf = static function (int $node) use ($net, $g, $copyOrd): string {
            return self::plain($net, $g->cnodes[$node], $copyOrd[$node] ?? 0);
        };

        $r->arcs = new Arcs();
        $frozen = str_repeat("\0", \count($r->segdir));
        /** @var array<int,int> $flags border node => 1 out, 2 in */
        $flags = [];
        $cross = [];
        foreach ($g->arcs->rows() as [$a, $b, $wt, $t, $d, $sd]) {
            $na = $newId[$a];
            $nb = $newId[$b];
            if ($na >= 0 && $nb >= 0) {
                $r->arcs->add($na, $nb, $wt, $t, $d, $sd);
            } elseif ($na >= 0) {
                $flags[$na] = ($flags[$na] ?? 0) | 1;
                $cross[] = pack('VV', $na, 0) . $keyOf($b) . pack('VVVV', $segOwner[$g->cnodes[$b] >> 1], $wt, $t, $d);
            } elseif ($nb >= 0) {
                $flags[$nb] = ($flags[$nb] ?? 0) | 2;
                $cross[] = pack('VV', $nb, 1) . $keyOf($a) . pack('VVVV', $segOwner[$g->cnodes[$a] >> 1], $wt, $t, $d);
            }
        }
        $border = [];
        foreach ($flags as $node => $f) {
            if (($f & 1) !== 0) {
                $r->exits[] = $node;
            }
            if (($f & 2) !== 0) {
                $r->entries[] = $node;
            }
            $frozen[$node] = "\1";
            $border[] = $keyOf($old[$node]) . pack('VV', $node, $f);
        }
        sort($border, SORT_STRING);
        for ($i = 1, $m = \count($border); $i < $m; $i++) {
            if (substr($border[$i], 0, Key::BYTES) === substr($border[$i - 1], 0, Key::BYTES)) {
                throw new RuntimeException('two border vertices have the key ' . Key::show(substr($border[$i], 0, Key::BYTES)) . ': cannot tell them apart');
            }
        }
        $w->writeAtomic('frozen.bin', $frozen);
        $w->writeAtomic('border.bin', implode('', $border));
        $keys = '';
        foreach ($old as $i => $o) {
            $keys .= $keyOf($o);
        }
        $w->writeAtomic('keys.bin', $keys);
        $w->writeAtomic('cross.bin', implode('', $cross));
        $r->borderVertices = \count($border);
        $r->crossArcs = \count($cross);

        return $r;
    }

    /** Key of directed segment `$sd` (copy number `$copy`, 0 for the segment itself). */
    private static function plain(Net $net, int $sd, int $copy = 0): string
    {
        $s = $sd >> 1;
        $first = Bin::u32($net->ptNode, $net->segG0[$s] * 4);
        $last = Bin::u32($net->ptNode, ($net->segG0[$s] + $net->segCnt[$s] - 1) * 4);

        return Key::make($net->osmId($first), $net->osmId($last), $net->wayIds[$net->segWay[$s]], ($sd & 1) | ($copy << 1));
    }
}
