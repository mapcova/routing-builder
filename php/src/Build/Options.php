<?php

declare(strict_types=1);

namespace MapCova\GraphBuilder\Build;

/** What one build is asked to do; the same options on every launch of a resumable build. */
final class Options
{
    public function __construct(
        public readonly string $input,
        public readonly string $output,
        public readonly string $workDir,
        public readonly string $zone,
        public readonly string $profile,
        /** Seconds one launch may run before it saves its state and stops. */
        public readonly float $slice = 240.0,
        /** Osmosis `.poly` file of the zone, or null for the bounding box. */
        public readonly ?string $poly = null,
        /** Unix time stamped as "built at" (null = when the file is written). */
        public readonly ?int $builtAt = null,
        /** Where to write the sorted arc list before contraction (to compare with `mcr build --dump-arcs`). */
        public readonly ?string $dumpArcs = null,
        /** Keep the work directory after the file is written. */
        public readonly bool $keepWork = false,
        /** Memory limit in bytes the build is sized for (0 = the PHP limit); reported against, not enforced. */
        public readonly int $memoryLimit = 0,
    ) {
    }
}
