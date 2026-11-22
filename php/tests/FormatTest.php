<?php

declare(strict_types=1);

namespace MapCova\GraphBuilder\Tests;

use MapCova\GraphBuilder\Build\Poly;
use MapCova\GraphBuilder\Build\State;
use MapCova\GraphBuilder\Mcr\Format;
use MapCova\GraphBuilder\Support\Bin;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class FormatTest extends TestCase
{
    public function testJsonStringsAreEscapedAsTheRustBuilderDoes(): void
    {
        self::assertSame('"a\\"b\\\\c\\nd\\u0009e/f é"', Format::jsonStr("a\"b\\c\nd\te/f é"));
        self::assertSame('"\\u000d"', Format::jsonStr("\r"));
    }

    public function testHeaderAndSectionTable(): void
    {
        [$head, $table] = Format::header([
            'profile' => 3,
            'edgeBased' => true,
            'nodes' => 10,
            'upFwd' => 20,
            'upBwd' => 30,
            'segments' => 4,
            'cells' => 5,
            'bbox' => [-5, 6, 7, 8],
            'osmTimestamp' => 1700000000,
            'builtAt' => 1700000001,
            'builder' => 'x',
            'zone' => 'lu',
        ], [100, 5000, 0, 40, 1, 2, 3, 4, 5]);
        self::assertSame(4096, \strlen($head));
        self::assertSame("MCR\0", substr($head, 0, 4));
        self::assertSame([1, 3, 1, 10, 20, 30, 4, 5], Bin::u32s($head, 8, 4));
        self::assertSame([0xFFFFFFFB, 6, 7, 8, 5000], Bin::u32s($head, 5, 36));
        self::assertSame('lu', rtrim(substr($head, 96, 32), "\0"));
        self::assertSame([[4096, 100], [8192, 5000], [16384, 0], [16384, 40], [20480, 1], [24576, 2], [28672, 3], [32768, 4], [36864, 5]], $table);
        self::assertStringStartsWith('Contains information from OpenStreetMap', rtrim(substr($head, 512, 1024), "\0"));
    }

    public function testHeaderNeedsNineSections(): void
    {
        $this->expectException(RuntimeException::class);
        Format::header(['profile' => 0, 'edgeBased' => true, 'nodes' => 0, 'upFwd' => 0, 'upBwd' => 0, 'segments' => 0, 'cells' => 0, 'bbox' => [0, 0, 0, 0], 'osmTimestamp' => 0, 'builtAt' => 0, 'builder' => '', 'zone' => 'x'], [1, 2]);
    }

    public function testPolyFileRingsAreKeptAndHolesDropped(): void
    {
        $text = "name\nring\n 6.0 49.0\n 6.0 50.0\n 7.0 50.0\n 7.0 49.0\n 6.0 49.0\nEND\n!hole\n 6.2 49.2\n 6.2 49.4\n 6.4 49.4\nEND\nEND\n";
        $rings = Poly::parse($text);
        self::assertCount(1, $rings);
        self::assertSame([49000000, 6000000], $rings[0][0]);
        self::assertSame([49000000, 6000000], $rings[0][\count($rings[0]) - 1]);
    }

    public function testStateRoundTrip(): void
    {
        $s = new State();
        $s->key = 'k';
        $s->phase = 'contract';
        $s->nn = 12;
        $s->slices[] = ['seconds' => 1.5, 'peak_bytes' => 7, 'phases' => ['ways', 'needed*']];
        $decoded = json_decode((string) json_encode($s->toArray()), true);
        self::assertIsArray($decoded);
        $copy = State::fromArray($decoded);
        self::assertEquals($s, $copy);
        $copy->next();
        self::assertSame('finish', $copy->phase);
    }

    public function testBinRefusesTruncatedData(): void
    {
        $this->expectException(RuntimeException::class);
        Bin::u32('abc');
    }
}
