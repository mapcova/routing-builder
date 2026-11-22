<?php

declare(strict_types=1);

namespace MapCova\GraphBuilder\Tests\Support;

/**
 * A stream wrapper (`limited://name`) that takes at most `$perCall` bytes in one write and `$capacity` bytes in all:
 * what a nearly full disk does. What it accepted is in `$data`.
 */
final class LimitedStream
{
    public static int $perCall = 1 << 30;
    public static int $capacity = 1 << 30;
    public static string $data = '';
    /** @var resource|null */
    public $context;

    public static function reset(int $perCall, int $capacity): void
    {
        self::$perCall = $perCall;
        self::$capacity = $capacity;
        self::$data = '';
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$opened): bool
    {
        return true;
    }

    public function stream_write(string $data): int
    {
        $n = min(\strlen($data), self::$perCall, self::$capacity - \strlen(self::$data));
        self::$data .= substr($data, 0, max($n, 0));

        return max($n, 0);
    }

    public function stream_flush(): bool
    {
        return true;
    }

    public function stream_close(): void
    {
    }
}
