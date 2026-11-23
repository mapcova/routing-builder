<?php

declare(strict_types=1);

namespace MapCova\GraphBuilder\Tests;

use MapCova\GraphBuilder\Net\Arcs;
use MapCova\GraphBuilder\Net\Scc;
use PHPUnit\Framework\TestCase;

final class SccTest extends TestCase
{
    private function arcs(): Arcs
    {
        // a loop 0 <-> 1, and a way 2 -> 3 -> 4 that is not a loop
        $a = new Arcs();
        foreach ([[0, 1], [1, 0], [2, 3], [3, 4]] as [$s, $d]) {
            $a->add($s, $d, 1, 1, 1, 0xFFFFFFFF);
        }

        return $a;
    }

    public function testTheLargestComponentOfAGraph(): void
    {
        self::assertSame([true, true, false, false, false], Scc::largest(5, $this->arcs()));
    }

    public function testRoadsThatLeaveAndEnterTheTileAreOneWholeThroughTheOutside(): void
    {
        // the way 2 -> 3 -> 4 is entered at 2 and left at 4: seen from outside it is a loop, bigger than 0 <-> 1
        self::assertSame([false, false, true, true, true], Scc::largest(5, $this->arcs(), [4], [2]));
        // no entry, no exit: the same as without the outside
        self::assertSame([true, true, false, false, false], Scc::largest(5, $this->arcs(), [], []));
    }
}
