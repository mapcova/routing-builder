<?php

declare(strict_types=1);

namespace MapCova\GraphBuilder\Build;

use RuntimeException;

/** The work directory of one build: phase files and the small state file that makes a build resumable. */
final class Workdir
{
    public function __construct(public readonly string $dir)
    {
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException("cannot create the work directory $dir");
        }
    }

    public function path(string $name): string
    {
        return $this->dir . '/' . $name;
    }

    public function has(string $name): bool
    {
        return is_file($this->path($name));
    }

    /** @return array<mixed>|null */
    public function readJson(string $name): ?array
    {
        $raw = @file_get_contents($this->path($name));
        if ($raw === false) {
            return null;
        }
        $v = json_decode($raw, true);

        return \is_array($v) ? $v : null;
    }

    /** @param array<mixed> $data */
    public function writeJson(string $name, array $data): void
    {
        $this->writeAtomic($name, json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /** Writes `$name.tmp`, then renames it over `$name`: readers see the old or the new file, never half of one. */
    public function writeAtomic(string $name, string $content): void
    {
        $tmp = $this->path($name . '.tmp');
        if (file_put_contents($tmp, $content) === false) {
            throw new RuntimeException("cannot write $tmp");
        }
        if (!rename($tmp, $this->path($name))) {
            throw new RuntimeException("cannot rename $tmp");
        }
    }

    /** Cuts a file back to `$size` bytes (what the last checkpoint recorded); creates it when `$size` is 0. */
    public function truncate(string $name, int $size): void
    {
        $p = $this->path($name);
        clearstatcache(true, $p);
        if (!is_file($p)) {
            touch($p);
        }
        $f = fopen($p, 'r+b');
        if ($f === false) {
            throw new RuntimeException("cannot open $p");
        }
        if ($size < 0 || filesize($p) < $size) {
            throw new RuntimeException("$name is shorter than its checkpoint: the work directory is damaged");
        }
        ftruncate($f, $size);
        fclose($f);
        clearstatcache(true, $p);
    }

    /**
     * Removes the files of the build, except those named in `$keep`.
     *
     * @param list<string> $keep
     */
    public function clean(array $keep = []): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            if (is_file($f) && !\in_array(basename($f), $keep, true)) {
                unlink($f);
            }
        }
        if ($keep === []) {
            @rmdir($this->dir);
        }
    }
}
