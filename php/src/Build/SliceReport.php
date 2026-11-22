<?php

declare(strict_types=1);

namespace MapCova\GraphBuilder\Build;

/** What one launch did. */
final class SliceReport
{
    /**
     * @param list<string> $phases phases finished or advanced in this launch
     */
    public function __construct(
        public readonly bool $done,
        public readonly string $phase,
        public readonly float $seconds,
        public readonly int $peakBytes,
        public readonly array $phases,
    ) {
    }
}
