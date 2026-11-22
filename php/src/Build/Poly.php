<?php

declare(strict_types=1);

namespace MapCova\GraphBuilder\Build;

use MapCova\GraphBuilder\Net\Geom;
use MapCova\GraphBuilder\Support\Text;

/** Osmosis `.poly` file: rings of `lon lat`, holes (`!name`) ignored; port of `parse_poly` in `src/lib.rs`. */
final class Poly
{
    /** @return list<list<array{int,int}>> rings of (lat, lon) in 1e-6 degree, simplified to 200 units */
    public static function parse(string $text): array
    {
        $rings = [];
        /** @var array{bool,list<array{int,int}>}|null $cur */
        $cur = null;
        foreach (preg_split('/\r?\n/', $text) ?: [] as $i => $line) {
            $l = Text::trim($line);
            if ($i === 0 || $l === '') {
                continue;
            }
            if ($l === 'END') {
                if ($cur !== null && !$cur[0] && \count($cur[1]) >= 3) {
                    $rings[] = $cur[1];
                }
                $cur = null;
            } elseif ($cur === null) {
                $cur = [str_starts_with($l, '!'), []];
            } else {
                $nums = [];
                foreach (preg_split('/\s+/', $l) ?: [] as $x) {
                    if (is_numeric($x)) {
                        $nums[] = (float) $x;
                    }
                }
                if (\count($nums) >= 2) {
                    $cur[1][] = [(int) round($nums[1] * 1e6), (int) round($nums[0] * 1e6)];
                }
            }
        }

        return array_map(static fn (array $r): array => Geom::simplify($r, 200.0), $rings);
    }
}
