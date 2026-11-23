<?php

declare(strict_types=1);

namespace MapCova\GraphBuilder\Ch;

use RuntimeException;

/** Reads a file in pieces of about 1 MB, so that a big file is never in memory whole. */
final class ChunkReader
{
    private const CHUNK = 1 << 20;

    /** @var resource */
    private $f;
    private string $buf = '';
    private int $pos = 0;
    private int $size;
    private int $taken = 0;

    public function __construct(private readonly string $path)
    {
        $f = @fopen($path, 'rb');
        if ($f === false) {
            throw new RuntimeException("cannot open $path");
        }
        $this->f = $f;
        $st = fstat($f);
        $this->size = $st === false ? PHP_INT_MAX : $st['size'];
    }

    public function __destruct()
    {
        $this->close();
    }

    public function close(): void
    {
        if (\is_resource($this->f)) {
            fclose($this->f);
        }
        $this->buf = '';
    }

    /**
     * Exactly `$n` bytes, or an exception when the file ends first. A length longer than what is left of the file
     * (a damaged length field) is refused before anything is read or allocated.
     */
    public function take(int $n): string
    {
        if ($n < 0 || $n > $this->size - $this->taken) {
            throw new RuntimeException("{$this->path} is truncated or damaged: $n bytes asked, " . max(0, $this->size - $this->taken) . ' left');
        }
        $this->taken += $n;
        while (\strlen($this->buf) - $this->pos < $n) {
            $more = fread($this->f, max(self::CHUNK, $n));
            if ($more === false || $more === '') {
                throw new RuntimeException("{$this->path} is truncated");
            }
            $this->buf = ($this->pos > 0 ? substr($this->buf, $this->pos) : $this->buf) . $more;
            $this->pos = 0;
        }
        $s = substr($this->buf, $this->pos, $n);
        $this->pos += $n;
        if ($this->pos >= self::CHUNK) {
            $this->buf = substr($this->buf, $this->pos);
            $this->pos = 0;
        }

        return $s;
    }

    public function atEnd(): bool
    {
        if ($this->pos < \strlen($this->buf)) {
            return false;
        }
        $more = fread($this->f, 1);
        if ($more === false || $more === '') {
            return true;
        }
        $this->buf = $more;
        $this->pos = 0;

        return false;
    }
}
