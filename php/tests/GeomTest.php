<?php

declare(strict_types=1);

namespace MapCova\GraphBuilder\Tests;

use MapCova\GraphBuilder\Net\Geom;
use PHPUnit\Framework\TestCase;

final class GeomTest extends TestCase
{
    public function testHaversineOneDegreeOfLatitude(): void
    {
        self::assertEqualsWithDelta(111226.0, Geom::haversine(0, 0, 1000000, 0), 50.0);
    }

    public function testBearingCardinals(): void
    {
        self::assertEqualsWithDelta(0.0, Geom::bearing(0, 0, 1000, 0), 1e-6);
        self::assertEqualsWithDelta(90.0, Geom::bearing(0, 0, 0, 1000), 1e-6);
        self::assertEqualsWithDelta(180.0, Geom::bearing(0, 0, -1000, 0), 1e-6);
        self::assertEqualsWithDelta(270.0, Geom::bearing(0, 0, 0, -1000), 1e-6);
    }

    public function testSimplifyDropsCollinearPoints(): void
    {
        $ring = [[0, 0], [0, 5000], [0, 10000], [10000, 10000], [0, 0]];
        self::assertCount(4, Geom::simplify($ring, 10.0));
    }

    public function testCellKeyUsesTheFloorOfTheOffsetCoordinates(): void
    {
        self::assertSame((int) ((49600000 + 90000000) / 5000) * 100000 + (int) ((6100000 + 180000000) / 5000), Geom::cellKey(49600000, 6100000));
        self::assertSame(-1, Geom::floorDiv(-1, 5000));
        self::assertSame(2, Geom::floorDiv(10001, 5000));
    }

    public function testZigzagAndVarint(): void
    {
        self::assertSame([0, 1, 2, 3, 4294967295], [Geom::zigzag(0), Geom::zigzag(-1), Geom::zigzag(1), Geom::zigzag(-2), Geom::zigzag(-2147483648)]);
        self::assertSame("\x00", Geom::varint(0));
        self::assertSame("\x80\x01", Geom::varint(128));
        self::assertSame("\xac\x02", Geom::varint(300));
    }
}
