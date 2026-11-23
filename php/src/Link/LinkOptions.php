<?php

declare(strict_types=1);

namespace MapCova\GraphBuilder\Link;

use MapCova\GraphBuilder\Tiles\TileSet;

/** What one link build is asked to do (the same on every launch of a resumable build). */
final class LinkOptions
{
    public function __construct(
        public readonly TileSet $tiles,
        /** The link of the tree to build. */
        public readonly string $link,
        /** Directory of the children's files: `<id>.mcr` for a tile, `<id>.mco` for a link. */
        public readonly string $dir,
        public readonly string $output,
        public readonly string $workDir,
        public readonly float $slice = 240.0,
        /** Vertices left uncontracted when the link has no outer border (the top): searched by Dijkstra at query time. */
        public readonly int $remainder = 300,
        public readonly ?int $builtAt = null,
        public readonly int $memoryLimit = 0,
        public readonly string $profile = 'car',
        /** Interior vertices a link with an outer border exports besides its outer border: the K_L most important ones. */
        public readonly int $exportInterior = 1000,
    ) {
    }
}
