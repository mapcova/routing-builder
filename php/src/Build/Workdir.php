<?php

declare(strict_types=1);

namespace MapCova\GraphBuilder\Build;

use MapCova\GraphBuilder\Support\Io;
use RuntimeException;

/** The work directory of one build: phase files and the small state file that makes a build resumable. */
final class Workdir
{
    /** @var resource|null */
    private $lock = null;

    public function __construct(public readonly string $dir)
    {
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException("cannot create the work directory $dir");
        }
    }

    /**
     * Takes the exclusive lock of the work directory (`.lock`), for the whole of a launch: two launches that overlap
     * would write the same files. The operating system drops the lock with the process, however it ends.
     *
     * @throws BuilderBusy when another process holds it
     */
    public function lock(): void
    {
        if ($this->lock !== null) {
            throw new RuntimeException('the work directory is already locked by this process');
        }
        $f = @fopen($this->path('.lock'), 'cb');
        if ($f === false) {
            throw new RuntimeException("cannot open the lock file {$this->path('.lock')}");
        }
        if (!flock($f, LOCK_EX | LOCK_NB)) {
            fclose($f);
            throw new BuilderBusy("another build is running in the work directory {$this->dir}");
        }
        $this->lock = $f;
    }

    public function unlock(): void
    {
        if ($this->lock !== null) {
            flock($this->lock, LOCK_UN);
            fclose($this->lock);
            $this->lock = null;
        }
    }

    /** Removes the temporary files a killed launch left (call with the lock held). */
    public function sweepTemporaries(): void
    {
        foreach (glob($this->dir . '/*.tmp.*') ?: [] as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
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

    /** Writes `$name` through a temporary file with a random name, then renames it: readers see the old or the new file. */
    public function writeAtomic(string $name, string $content): void
    {
        Io::putAtomic($this->path($name), $content);
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
            fclose($f);
            throw new RuntimeException("$name is shorter than its checkpoint: the work directory is damaged");
        }
        if (!ftruncate($f, $size)) {
            fclose($f);
            throw new RuntimeException("cannot cut $p back to $size bytes");
        }
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
