<?php

declare(strict_types=1);

namespace MapCova\GraphBuilder\Tests;

use MapCova\GraphBuilder\Support\F32;
use MapCova\GraphBuilder\Support\Text;
use PHPUnit\Framework\TestCase;

final class F32Test extends TestCase
{
    public function testRoundsToSinglePrecision(): void
    {
        self::assertSame(0.10000000149011612, F32::r(0.1));
        self::assertSame(0.800000011920929, F32::r(0.8));
        self::assertSame(0.75, F32::r(0.75));
        self::assertSame(50.0, F32::r(50.0));
        self::assertSame(F32::r(1609.0 * 30.0 / 1000.0), F32::r(F32::r(30.0 * 1609.0) / 1000.0));
    }

    public function testParseF32FollowsTheRustGrammar(): void
    {
        self::assertSame(5.0, Text::parseF32('5.'));
        self::assertSame(0.5, Text::parseF32('.5'));
        self::assertSame(1500.0, Text::parseF32('1.5e3'));
        self::assertNull(Text::parseF32('.'));
        self::assertNull(Text::parseF32(''));
        self::assertNull(Text::parseF32('1,5'));
        self::assertNull(Text::parseF32(' 1'));
        self::assertSame(INF, Text::parseF32('inf'));
        self::assertNan(Text::parseF32('NaN') ?? 0.0);
        self::assertSame(F32::r(3.3), Text::parseF32('3.3'));
    }

    public function testTrimStripsUnicodeWhiteSpace(): void
    {
        self::assertSame('a b', Text::trim("\u{a0} a b\u{3000}\n"));
    }
}
