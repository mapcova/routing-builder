<?php

declare(strict_types=1);

namespace MapCova\GraphBuilder\Profile;

/** What a profile makes of an OSM way: the two directions (speed in km/h as an f32 value, 0 = unusable). */
final class Way
{
    public function __construct(
        public readonly float $fwdSpeed,
        public readonly float $fwdRate,
        public readonly float $bwdSpeed,
        public readonly float $bwdRate,
        /** Local access only (destination, private, ...), both directions. */
        public readonly bool $restricted,
        /** Fixed duration of the whole way in seconds (ferries, shuttle trains). */
        public readonly ?float $duration,
        /** May a route start or end on this way? */
        public readonly bool $startpoint,
        public readonly bool $toll,
    ) {
    }
}
