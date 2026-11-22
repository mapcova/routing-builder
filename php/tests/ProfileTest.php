<?php

declare(strict_types=1);

namespace MapCova\GraphBuilder\Tests;

use MapCova\GraphBuilder\Profile\Bike;
use MapCova\GraphBuilder\Profile\Car;
use MapCova\GraphBuilder\Profile\Foot;
use MapCova\GraphBuilder\Profile\Profile;
use MapCova\GraphBuilder\Profile\Way;
use PHPUnit\Framework\TestCase;

/** The unit tests of `src/profile/*.rs`, ported. */
final class ProfileTest extends TestCase
{
    /** @param array<string,string> $t */
    private static function car(array $t, bool $noToll = false): ?Way
    {
        return (new Car($noToll))->way($t);
    }

    /** @param array<string,string> $t */
    private static function bike(array $t): ?Way
    {
        return (new Bike())->way($t);
    }

    /** @param array<string,string> $t */
    private static function foot(array $t): ?Way
    {
        return (new Foot())->way($t);
    }

    /** @param list<array{string,string}> $tags */
    private static function flags(Profile $p, array $tags): int
    {
        return $p->nodeFlags($tags);
    }

    private static function must(?Way $w): Way
    {
        self::assertNotNull($w);

        return $w;
    }

    public function testDurations(): void
    {
        self::assertSame(5400.0, Profile::parseDuration('PT1H30M'));
        self::assertSame(2700.0, Profile::parseDuration('PT45M'));
        self::assertSame(5400.0, Profile::parseDuration('01:30'));
        self::assertSame(5400.0, Profile::parseDuration('90'));
        self::assertSame(93600.0, Profile::parseDuration('P1DT2H'));
        self::assertNull(Profile::parseDuration('soon'));
        self::assertSame(5430.0, Profile::parseDuration('01:30:30'));
        self::assertSame(1.0, Profile::parseDuration('PT0.5S'));
        self::assertNull(Profile::parseDuration('PT1H30'));
        self::assertNull(Profile::parseDuration('P1S'));
    }

    public function testMaxspeeds(): void
    {
        self::assertSame(50.0, Profile::parseMaxspeed('50'));
        self::assertEqualsWithDelta(48.27, Profile::parseMaxspeed('30 mph'), 0.1);
        self::assertSame(80.0, Profile::parseMaxspeed('ch:rural'));
        self::assertSame(50.0, Profile::parseMaxspeed('LU:urban'));
        self::assertSame(0.0, Profile::parseMaxspeed('signals'));
    }

    public function testProfileNamesRoundTrip(): void
    {
        foreach (['car', 'car-notoll', 'bike', 'foot'] as $name) {
            self::assertSame($name, Profile::parse($name)?->name());
        }
        self::assertNull(Profile::parse('boat'));
        self::assertSame([0, 3, 1, 2], array_map(static fn (string $n): int => (int) Profile::parse($n)?->id(), ['car', 'car-notoll', 'bike', 'foot']));
    }

    public function testCarResidentialTwoWay(): void
    {
        $x = self::must(self::car(['highway' => 'residential']));
        self::assertSame(25.0, $x->fwdSpeed);
        self::assertSame(25.0, $x->bwdSpeed);
        self::assertSame(1.0, $x->fwdRate);
        self::assertFalse($x->restricted);
    }

    public function testCarOnewayAndMotorwayAreOneWay(): void
    {
        self::assertSame(0.0, self::must(self::car(['highway' => 'primary', 'oneway' => 'yes']))->bwdSpeed);
        self::assertSame(0.0, self::must(self::car(['highway' => 'primary', 'oneway' => '-1']))->fwdSpeed);
        self::assertSame(0.0, self::must(self::car(['highway' => 'motorway']))->bwdSpeed);
    }

    public function testCarMaxspeedIsReduced(): void
    {
        self::assertEqualsWithDelta(40.0, self::must(self::car(['highway' => 'primary', 'maxspeed' => '50']))->fwdSpeed, 1e-4);
    }

    public function testCarPrivateIsRestrictedNotBlocked(): void
    {
        self::assertTrue(self::must(self::car(['highway' => 'service', 'access' => 'private']))->restricted);
        self::assertTrue(self::must(self::car(['highway' => 'residential', 'motor_vehicle' => 'destination']))->restricted);
        self::assertNull(self::car(['highway' => 'residential', 'access' => 'no']));
        self::assertGreaterThan(0.0, self::must(self::car(['highway' => 'service', 'access' => 'private']))->fwdSpeed);
        self::assertFalse(self::must(self::car(['highway' => 'service', 'access' => 'private']))->startpoint);
    }

    public function testCarServiceRoadsArePenalised(): void
    {
        self::assertSame(0.5, self::must(self::car(['highway' => 'service', 'service' => 'driveway']))->fwdRate);
        self::assertSame(1.0, self::must(self::car(['highway' => 'service']))->fwdRate);
        self::assertNull(self::car(['highway' => 'service', 'service' => 'emergency_access']));
    }

    public function testCarFerryWithDuration(): void
    {
        $x = self::must(self::car(['route' => 'ferry', 'duration' => '00:20']));
        self::assertSame(1200.0, $x->duration);
        self::assertGreaterThan(0.0, $x->fwdSpeed);
        self::assertGreaterThan(0.0, $x->bwdSpeed);
        self::assertFalse($x->startpoint);
        self::assertSame(900.0, self::must(self::car(['route' => 'shuttle_train', 'duration' => 'PT15M']))->duration);
        self::assertNull(self::must(self::car(['route' => 'ferry']))->duration);
        self::assertNull(self::car(['route' => 'ferry', 'motor_vehicle' => 'no']));
        self::assertNull(self::car(['route' => 'bus']));
    }

    public function testCarTollWays(): void
    {
        $t = ['highway' => 'motorway', 'toll' => 'yes'];
        self::assertNotNull(self::car($t));
        self::assertNull(self::car($t, true));
        self::assertNotNull(self::car(['highway' => 'motorway', 'toll' => 'no'], true));
        self::assertTrue(Car::isToll(['highway' => 'motorway', 'toll:motorcar' => 'yes']));
        self::assertTrue(self::must(self::car($t))->toll);
    }

    public function testCarNodes(): void
    {
        $car = new Car(false);
        self::assertSame(Profile::NODE_BARRIER, self::flags($car, [['barrier', 'bollard']]));
        self::assertSame(Profile::NODE_GATE, self::flags($car, [['barrier', 'gate']]));
        self::assertSame(0, self::flags($car, [['barrier', 'gate'], ['access', 'yes']]));
        self::assertSame(Profile::NODE_SIGNALS, self::flags($car, [['highway', 'traffic_signals']]));
        self::assertSame(0, self::flags($car, [['barrier', 'cattle_grid']]));
    }

    public function testCarRightCheaperThanLeft(): void
    {
        self::assertLessThan(Car::turnPenalty(-90.0, 4, false, false), Car::turnPenalty(90.0, 4, false, false));
        self::assertSame(0.0, Car::turnPenalty(0.0, 2, false, false));
        self::assertGreaterThan(25.0, Car::turnPenalty(180.0, 1, false, true));
    }

    public function testCarAccessListsKeepTheMostPermissivePart(): void
    {
        self::assertNotNull(self::car(['highway' => 'residential', 'access' => 'no;yes']));
        self::assertNull(self::car(['highway' => 'residential', 'access' => 'no;no']));
    }

    public function testCarSingleLaneAndWidthSlowTheWeightNotTheDuration(): void
    {
        $x = self::must(self::car(['highway' => 'residential', 'width' => '2.5 m']));
        self::assertSame(0.5, $x->fwdRate);
        self::assertSame(25.0, $x->fwdSpeed);
        self::assertEqualsWithDelta(0.6, self::must(self::car(['highway' => 'residential', 'width' => '3.5']))->fwdRate, 1e-6);
    }

    public function testBikeSpeeds(): void
    {
        self::assertSame(15.0, self::must(self::bike(['highway' => 'residential']))->fwdSpeed);
        self::assertSame(12.0, self::must(self::bike(['highway' => 'track']))->fwdSpeed);
        self::assertNull(self::bike(['highway' => 'motorway']));
        self::assertNull(self::bike(['highway' => 'trunk']));
    }

    public function testBikePushedAgainstOneway(): void
    {
        $x = self::must(self::bike(['highway' => 'residential', 'oneway' => 'yes']));
        self::assertSame(15.0, $x->fwdSpeed);
        self::assertSame(4.0, $x->bwdSpeed);
        self::assertSame(15.0, self::must(self::bike(['highway' => 'residential', 'oneway' => 'yes', 'oneway:bicycle' => 'no']))->bwdSpeed);
        self::assertSame(15.0, self::must(self::bike(['highway' => 'residential', 'oneway' => 'yes', 'cycleway' => 'opposite']))->bwdSpeed);
        self::assertSame(0.0, self::must(self::bike(['highway' => 'residential', 'oneway' => 'yes', 'foot' => 'no']))->bwdSpeed);
    }

    public function testBikeFootwaysAndSteps(): void
    {
        self::assertSame(4.0, self::must(self::bike(['highway' => 'footway']))->fwdSpeed);
        self::assertSame(2.0, self::must(self::bike(['highway' => 'steps']))->fwdSpeed);
        self::assertNull(self::bike(['highway' => 'footway', 'bicycle' => 'no']));
        self::assertSame(15.0, self::must(self::bike(['highway' => 'footway', 'bicycle' => 'yes']))->fwdSpeed);
    }

    public function testBikeSurfaceAndMaxspeedLimit(): void
    {
        self::assertSame(6.0, self::must(self::bike(['highway' => 'residential', 'surface' => 'gravel']))->fwdSpeed);
        self::assertSame(10.0, self::must(self::bike(['highway' => 'residential', 'maxspeed' => '10']))->fwdSpeed);
    }

    public function testBikeFerryIsNotAStartPoint(): void
    {
        $x = self::must(self::bike(['route' => 'ferry', 'duration' => '00:10']));
        self::assertSame(600.0, $x->duration);
        self::assertFalse($x->startpoint);
    }

    public function testBikeNodes(): void
    {
        $bike = new Bike();
        self::assertSame(Profile::NODE_BARRIER, self::flags($bike, [['barrier', 'wall']]));
        self::assertSame(0, self::flags($bike, [['barrier', 'bollard']]));
        self::assertSame(Profile::NODE_BARRIER, self::flags($bike, [['barrier', 'gate'], ['bicycle', 'no']]));
        self::assertSame(Profile::NODE_SIGNALS, self::flags($bike, [['highway', 'traffic_signals']]));
    }

    public function testFootWalking(): void
    {
        self::assertSame(5.0, self::must(self::foot(['highway' => 'residential']))->fwdSpeed);
        self::assertSame(5.0, self::must(self::foot(['highway' => 'steps']))->bwdSpeed);
        self::assertNull(self::foot(['highway' => 'motorway']));
        self::assertNull(self::foot(['highway' => 'cycleway']));
        self::assertSame(5.0, self::must(self::foot(['highway' => 'cycleway', 'foot' => 'yes']))->fwdSpeed);
        self::assertNull(self::foot(['highway' => 'footway', 'foot' => 'no']));
    }

    public function testFootPlainOnewayIsIgnoredButOnewayFootIsNot(): void
    {
        self::assertSame(5.0, self::must(self::foot(['highway' => 'residential', 'oneway' => 'yes']))->bwdSpeed);
        self::assertSame(0.0, self::must(self::foot(['highway' => 'footway', 'oneway:foot' => 'yes']))->bwdSpeed);
    }

    public function testFootSurfacesAndFerries(): void
    {
        self::assertSame(2.5, self::must(self::foot(['highway' => 'path', 'surface' => 'sand']))->fwdSpeed);
        $x = self::must(self::foot(['route' => 'ferry', 'duration' => 'PT5M']));
        self::assertSame(300.0, $x->duration);
        self::assertFalse($x->startpoint);
    }

    public function testFootNodes(): void
    {
        $foot = new Foot();
        self::assertSame(Profile::NODE_BARRIER, self::flags($foot, [['barrier', 'fence']]));
        self::assertSame(0, self::flags($foot, [['barrier', 'gate']]));
        self::assertSame(Profile::NODE_BARRIER, self::flags($foot, [['barrier', 'gate'], ['foot', 'private']]));
    }

    public function testExemptions(): void
    {
        self::assertTrue((new Car(false))->exempt('psv; motorcar'));
        self::assertFalse((new Car(false))->exempt('bicycle'));
        self::assertTrue((new Bike())->exempt('bicycle'));
        self::assertTrue((new Foot())->exempt('foot;psv'));
    }
}
