<?php

declare(strict_types=1);

namespace MapCova\GraphBuilder\Tests;

use MapCova\GraphBuilder\Build\Workdir;
use MapCova\GraphBuilder\Ch\ChunkReader;
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

    public function testACopyNeverWritesMoreThanExpectedAndRefusesALongerSource(): void
    {
        $in = fopen('php://memory', 'w+b');
        $out = fopen('php://memory', 'w+b');
        self::assertIsResource($in);
        self::assertIsResource($out);
        fwrite($in, 'abcdef');
        rewind($in);
        try {
            Io::copy($in, $out, 3, 'section');
            self::fail('a longer source must be refused');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('longer than 3 bytes', $e->getMessage());
        }
        rewind($out);
        self::assertSame('abc', stream_get_contents($out));
    }

    public function testAChunkReaderRefusesALengthLongerThanTheFileBeforeAllocating(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'chunk');
        self::assertIsString($path);
        file_put_contents($path, 'abcd');
        try {
            $r = new ChunkReader($path);
            self::assertSame('ab', $r->take(2));
            $before = memory_get_usage();
            try {
                $r->take(0x7FFFFFFF);
                self::fail('a damaged length must be refused');
            } catch (RuntimeException $e) {
                self::assertStringContainsString('2 left', $e->getMessage());
            }
            self::assertLessThan(1 << 20, memory_get_usage() - $before);
            self::assertSame('cd', $r->take(2));
            self::assertTrue($r->atEnd());
            $r->close();
        } finally {
            unlink($path);
        }
    }

    public function testCleaningALockedWorkdirKeepsTheLockFile(): void
    {
        $dir = sys_get_temp_dir() . '/wd-' . bin2hex(random_bytes(4));
        $w = new Workdir($dir);
        $w->lock();
        try {
            file_put_contents($dir . '/phase.bin', 'x');
            $w->clean();
            self::assertFileExists($dir . '/.lock');
            self::assertFileDoesNotExist($dir . '/phase.bin');
            $other = new Workdir($dir);
            $this->expectException(\MapCova\GraphBuilder\Build\BuilderBusy::class);
            $other->lock();
        } finally {
            $w->unlock();
            @unlink($dir . '/.lock');
            @rmdir($dir);
        }
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
