<?php

declare(strict_types=1);

namespace MapCova\GraphBuilder\Tests;

use MapCova\GraphBuilder\Support\Bin;
use MapCova\GraphBuilder\Support\Io;
use MapCova\GraphBuilder\Tests\Support\LimitedStream;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class IoTest extends TestCase
{
    protected function setUp(): void
    {
        stream_wrapper_register('limited', LimitedStream::class);
    }

    protected function tearDown(): void
    {
        stream_wrapper_unregister('limited');
    }

    /** @return resource */
    private function open()
    {
        $f = fopen('limited://x', 'wb');
        self::assertIsResource($f);

        return $f;
    }

    public function testAShortWriteIsContinuedUntilEverythingIsWritten(): void
    {
        LimitedStream::reset(3, 1000);
        Io::write($this->open(), 'abcdefghij', 'x');
        self::assertSame('abcdefghij', LimitedStream::$data);
    }

    public function testAWriteThatTheDiskRefusesIsAnError(): void
    {
        LimitedStream::reset(1 << 20, 6);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('wrote 6 of 10 bytes');
        Io::write($this->open(), 'abcdefghij', 'x');
    }

    public function testACopyThatIsShortIsAnError(): void
    {
        $in = fopen('php://memory', 'w+b');
        $out = fopen('php://memory', 'w+b');
        self::assertIsResource($in);
        self::assertIsResource($out);
        fwrite($in, 'abc');
        rewind($in);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('3 of 10 bytes');
        Io::copy($in, $out, 10, 'section');
    }

    public function testACopyOfTheRightLengthPasses(): void
    {
        $in = fopen('php://memory', 'w+b');
        $out = fopen('php://memory', 'w+b');
        self::assertIsResource($in);
        self::assertIsResource($out);
        fwrite($in, 'abc');
        rewind($in);
        Io::copy($in, $out, 3, 'section');
        rewind($out);
        self::assertSame('abc', stream_get_contents($out));
    }

    public function testAnAtomicWriteLeavesNoTemporaryFile(): void
    {
        $dir = sys_get_temp_dir() . '/mcr-io-' . bin2hex(random_bytes(4));
        mkdir($dir);
        Io::putAtomic($dir . '/a.json', '{"a":1}');
        Io::putAtomic($dir . '/a.json', '{"a":2}');
        self::assertSame('{"a":2}', file_get_contents($dir . '/a.json'));
        self::assertSame([$dir . '/a.json'], glob($dir . '/*'));
        unlink($dir . '/a.json');
        rmdir($dir);
        $this->expectException(RuntimeException::class);
        Io::putAtomic($dir . '/missing/a.json', 'x');
    }

    public function testPackAllEqualsPackWithoutTheSpread(): void
    {
        $v = range(0, 10000);
        self::assertSame(pack('V*', ...$v), Bin::packAll('V', $v));
        self::assertSame('', Bin::packAll('V', []));
        self::assertSame(pack('P3', 1, 2, 3), Bin::packAll('P', [1, 2, 3]));
    }
}
