<?php

declare(strict_types=1);

namespace MapCova\GraphBuilder\Mcr;

use MapCova\GraphBuilder\Support\Bin;
use RuntimeException;

/**
 * A `.mcr` tile or `.mco` link opened for reading: the header and the section table (version 1 readers read nine entries;
 * a tile has eleven, a link eight, and the rest of the table is zero).
 */
class Container
{
    public const MAGIC_TILE = "MCR\0";
    public const MAGIC_LINK = "MCO\0";
    public const HEADER = 4096;

    /** @var resource */
    protected $fh;
    /** @var list<array{int,int}> offset and length of each section */
    public array $sec = [];
    public int $profile;
    public int $nodes;
    public int $upFwd;
    public int $upBwd;
    public int $reads = 0;

    public function __construct(public readonly string $path, string $magic)
    {
        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            throw new RuntimeException("cannot open $path");
        }
        $this->fh = $fh;
        $h = $this->read(0, self::HEADER);
        if (substr($h, 0, 4) !== $magic) {
            throw new RuntimeException("$path is not a " . ($magic === self::MAGIC_TILE ? '.mcr' : '.mco') . ' file');
        }
        $u = Bin::ints('Vversion/Vprofile/Vtype/Vnodes/Vupf/Vupb', $h, 4);
        if ($u['version'] !== 1) {
            throw new RuntimeException("$path: unsupported format version");
        }
        $this->profile = $u['profile'];
        $this->nodes = $u['nodes'];
        $this->upFwd = $u['upf'];
        $this->upBwd = $u['upb'];
        for ($i = 0; $i < 12; $i++) {
            $s = Bin::ints('Poff/Plen', $h, 128 + $i * 16);
            $this->sec[] = [$s['off'], $s['len']];
        }
    }

    public function __destruct()
    {
        if (\is_resource($this->fh)) {
            fclose($this->fh);
        }
    }

    public function read(int $off, int $len): string
    {
        ++$this->reads;
        fseek($this->fh, $off);
        $d = $len > 0 ? fread($this->fh, $len) : '';
        if ($d === false || \strlen($d) < $len) {
            throw new RuntimeException("{$this->path}: short read (truncated file?)");
        }

        return $d;
    }

    public function section(int $i): string
    {
        return $this->read($this->sec[$i][0], $this->sec[$i][1]);
    }

    /** Section `$i` is present (a tile has sections 9 and 10, a version 1 file has not). */
    public function has(int $i): bool
    {
        return ($this->sec[$i][1] ?? 0) > 0;
    }
}
