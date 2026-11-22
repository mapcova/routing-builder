<?php

declare(strict_types=1);

namespace MapCova\GraphBuilder\Net;

use MapCova\GraphBuilder\Profile\Bike;
use MapCova\GraphBuilder\Profile\Profile;

/**
 * Node-based graph (bike, foot): a node is a junction, an arc follows one segment in one direction. No turn costs.
 * Traffic signals cost 2 s on arriving at the junction; a barrier junction is a dead end. Port of `src/node.rs`.
 */
final class NodeGraph
{
    public const SIGNAL_PENALTY = 20;
    /**
     * Bikes: OSRM charges up to 6 s for a turn at a junction, and a route mostly goes straight on. A node-based graph
     * has no turns, so each arrival at a junction of three roads or more costs a flat 1 s instead (tenths of a
     * second).
     */
    public const BIKE_JUNCTION_PENALTY = 10;

    public static function build(Net $net, Profile $profile): Arcs
    {
        $arcs = new Arcs();
        $bike = $profile instanceof Bike;
        for ($s = 0, $ns = $net->nseg(); $s < $ns; $s++) {
            $a = $net->segA[$s];
            $b = $net->segB[$s];
            $ja = $net->junc($a);
            $jb = $net->junc($b);
            if ($ja === Net::NONE || $jb === Net::NONE) {
                continue;
            }
            if (($net->flags($a) & Profile::NODE_BARRIER) !== 0 || ($net->flags($b) & Profile::NODE_BARRIER) !== 0) {
                continue;
            }
            for ($dir = 0; $dir < 2; $dir++) {
                $d = $s * 2 + $dir;
                if ($net->dur[$d] === 0) {
                    continue;
                }
                if ($dir === 0) {
                    [$src, $dst, $to] = [$ja, $jb, $b];
                } else {
                    [$src, $dst, $to] = [$jb, $ja, $a];
                }
                $pen = ($net->flags($to) & Profile::NODE_SIGNALS) !== 0 ? self::SIGNAL_PENALTY : 0;
                if ($bike && $net->incFirst[$dst + 1] - $net->incFirst[$dst] > 2) {
                    $pen += self::BIKE_JUNCTION_PENALTY;
                }
                $arcs->add($src, $dst, $net->w[$d] + $pen, $net->dur[$d] + $pen, $net->lenM[$s], $d);
            }
        }

        return $arcs;
    }
}
