<?php

declare(strict_types=1);

namespace MapCova\GraphBuilder\Osm;

use MapCova\GraphBuilder\Support\Bin;
use MapCova\GraphBuilder\Support\IntMinHeap;
use RuntimeException;

/**
 * The sorted, distinct OSM node ids the kept ways use, written to a file of u64: an external sort (sorted runs of
 * bounded size, then one k-way merge), so that memory does not grow with the extract.
 */
final class NeededIds
{
    private const MAX_RUNS = 255;

    /** @param int $chunk ids per sorted run (at most; more when the extract has too many for 255 runs) */
    public static function build(string $waysPath, string $outPath, string $tmpDir, int $chunk = 1 << 20): int
    {
        $total = 0;
        $prev = -1;
        $in = self::open($waysPath, 'rb');
        while (($h = fread($in, WaysPass::WAY_HEADER)) !== false && \strlen($h) === WaysPass::WAY_HEADER) {
            $u = Bin::ints('Pid/Vn', $h);
            if ($u['id'] <= $prev) {
                // the Rust builder sorts the ways it reads; reading in file order numbers the same only if they are sorted
                throw new RuntimeException('the ways of the extract are not sorted by id (sort it with osmium sort)');
            }
            $prev = $u['id'];
            $total += $u['n'];
            fseek($in, $u['n'] * 8, SEEK_CUR);
        }
        $chunk = max($chunk, intdiv($total, self::MAX_RUNS) + 1);
        rewind($in);
        $runs = [];
        $ids = [];
        while (($h = fread($in, WaysPass::WAY_HEADER)) !== false && \strlen($h) === WaysPass::WAY_HEADER) {
            $n = Bin::ints('Pid/Vn', $h)['n'];
            if ($n > 0) {
                foreach (Bin::list('P' . $n, (string) fread($in, $n * 8)) as $r) {
                    $ids[] = $r;
                }
            }
            if (\count($ids) >= $chunk) {
                $runs[] = self::writeRun($ids, $tmpDir . '/run-' . \count($runs) . '.bin');
                $ids = [];
            }
        }
        if ($ids !== []) {
            $runs[] = self::writeRun($ids, $tmpDir . '/run-' . \count($runs) . '.bin');
        }
        fclose($in);

        $count = self::merge($runs, $outPath);
        foreach ($runs as $r) {
            @unlink($r);
        }

        return $count;
    }

    /** @param list<int> $ids */
    private static function writeRun(array $ids, string $path): string
    {
        sort($ids);
        $f = self::open($path, 'wb');
        $prev = -1;
        $buf = '';
        foreach ($ids as $id) {
            if ($id !== $prev) {
                $buf .= pack('P', $id);
                $prev = $id;
            }
            if (\strlen($buf) >= 1 << 16) {
                fwrite($f, $buf);
                $buf = '';
            }
        }
        fwrite($f, $buf);
        fclose($f);

        return $path;
    }

    /** @param list<string> $runs */
    private static function merge(array $runs, string $outPath): int
    {
        if (\count($runs) > self::MAX_RUNS) {
            throw new RuntimeException('too many sorted runs to merge');
        }
        /** @var array<int,resource> $files */
        $files = [];
        /** @var array<int,list<int>> $bufs */
        $bufs = [];
        /** @var array<int,int> $pos */
        $pos = [];
        foreach ($runs as $i => $path) {
            $files[$i] = self::open($path, 'rb');
            $bufs[$i] = [];
            $pos[$i] = 0;
        }
        $heap = new IntMinHeap();
        foreach (array_keys($runs) as $i) {
            $bufs[$i] = self::fill($files[$i]);
            if ($bufs[$i] !== []) {
                $heap->insert(($bufs[$i][0] << 8) | $i);
            }
        }
        $out = self::open($outPath . '.part', 'wb');
        $buf = '';
        $prev = -1;
        $count = 0;
        while (!$heap->isEmpty()) {
            $k = $heap->extract();
            $i = $k & 0xFF;
            $id = $k >> 8;
            if ($id !== $prev) {
                $buf .= pack('P', $id);
                $prev = $id;
                $count++;
                if (\strlen($buf) >= 1 << 16) {
                    fwrite($out, $buf);
                    $buf = '';
                }
            }
            if (++$pos[$i] >= \count($bufs[$i])) {
                $bufs[$i] = self::fill($files[$i]);
                $pos[$i] = 0;
                if ($bufs[$i] === []) {
                    continue;
                }
            }
            $heap->insert(($bufs[$i][$pos[$i]] << 8) | $i);
        }
        fwrite($out, $buf);
        fclose($out);
        foreach ($files as $f) {
            fclose($f);
        }
        rename($outPath . '.part', $outPath);

        return $count;
    }

    /**
     * @param resource $f
     * @return list<int> the next ids of a run
     */
    private static function fill($f): array
    {
        $raw = fread($f, 8 * 8192);

        return $raw === false || $raw === '' ? [] : Bin::allU64($raw);
    }

    /** @return resource */
    private static function open(string $path, string $mode)
    {
        $f = @fopen($path, $mode);
        if ($f === false) {
            throw new RuntimeException("cannot open $path");
        }

        return $f;
    }
}
