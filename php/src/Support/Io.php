<?php

declare(strict_types=1);

namespace MapCova\GraphBuilder\Support;

use RuntimeException;

/** File writes that are checked: a full disk or a short write throws instead of leaving a silently truncated file. */
final class Io
{
    /**
     * Writes all of `$data`, going on after a short write and failing when the stream accepts nothing more.
     *
     * @param resource $f
     */
    public static function write($f, string $data, string $what): void
    {
        $len = \strlen($data);
        $done = 0;
        while ($done < $len) {
            $n = @fwrite($f, $done === 0 ? $data : substr($data, $done));
            if ($n === false || $n === 0) {
                throw new RuntimeException("cannot write $what: wrote $done of $len bytes (disk full?)");
            }
            $done += $n;
        }
    }

    /**
     * Flushes the stream to the operating system, failing when that does not work.
     *
     * @param resource $f
     */
    public static function flush($f, string $what): void
    {
        if (!@fflush($f)) {
            throw new RuntimeException("cannot flush $what (disk full?)");
        }
    }

    /**
     * Flushes and closes.
     *
     * @param resource $f
     */
    public static function close($f, string $what): void
    {
        $flushed = @fflush($f);
        $closed = @fclose($f);
        if (!$flushed || !$closed) {
            throw new RuntimeException("cannot write $what (disk full?)");
        }
    }

    /**
     * Copies `$in` to `$out` whole and checks the number of bytes against `$expected`.
     *
     * @param resource $in
     * @param resource $out
     */
    public static function copy($in, $out, int $expected, string $what): void
    {
        $n = @stream_copy_to_stream($in, $out);
        if ($n !== $expected) {
            throw new RuntimeException("cannot copy $what: " . ($n === false ? 'failed' : "$n of $expected bytes") . ' (disk full?)');
        }
    }

    /** Writes `$path` through a temporary file with a random name and a rename: readers see the old file or the new one. */
    public static function putAtomic(string $path, string $content): void
    {
        $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
        $f = @fopen($tmp, 'wb');
        if ($f === false) {
            throw new RuntimeException("cannot write $tmp");
        }
        try {
            self::write($f, $content, $tmp);
            self::close($f, $tmp);
            $f = null;
            if (filesize($tmp) !== \strlen($content) || !rename($tmp, $path)) {
                throw new RuntimeException("cannot move $tmp to $path");
            }
        } catch (\Throwable $e) {
            if (\is_resource($f)) {
                @fclose($f);
            }
            @unlink($tmp);
            throw $e;
        }
    }
}
