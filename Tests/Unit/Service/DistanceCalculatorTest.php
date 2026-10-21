<?php

declare(strict_types=1);

/*
 * This file is part of the Thelia package.
 * http://www.thelia.net
 *
 * (c) OpenStudio <info@thelia.net>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace GeolocatedDelivery\Tests\Unit\Service;

use GeolocatedDelivery\Service\Coordinates;
use GeolocatedDelivery\Service\DistanceCalculator;
use PHPUnit\Framework\TestCase;

final class DistanceCalculatorTest extends TestCase
{
    public function testTheDistanceBetweenParisAndLyonIsAboutThreeHundredAndNinetyTwoKilometres(): void
    {
        $paris = Coordinates::from(48.8566, 2.3522);
        $lyon = Coordinates::from(45.7640, 4.8357);
        self::assertNotNull($paris);
        self::assertNotNull($lyon);

        $distance = (new DistanceCalculator())->kilometresBetween($paris, $lyon);

        self::assertEqualsWithDelta(391.5, $distance, 1.0);
        self::assertEqualsWithDelta($distance, (new DistanceCalculator())->kilometresBetween($lyon, $paris), 0.000001);
    }

    public function testAShortDistanceAcrossATownIsMeasuredToTenMetres(): void
    {
        // Lyon city hall to the Part-Dieu station, about 2.1 km.
        $cityHall = Coordinates::from(45.767299, 4.833566);
        $station = Coordinates::from(45.760585, 4.859435);
        self::assertNotNull($cityHall);
        self::assertNotNull($station);

        self::assertEqualsWithDelta(2.14, (new DistanceCalculator())->kilometresBetween($cityHall, $station), 0.01);
    }

    public function testAPlaceIsAtNoDistanceFromItself(): void
    {
        $place = Coordinates::from(45.7640, 4.8357);
        self::assertNotNull($place);

        self::assertSame(0.0, (new DistanceCalculator())->kilometresBetween($place, $place));
    }

    public function testCoordinatesOutsideTheGlobeOrNotNumbersAreRefused(): void
    {
        self::assertNull(Coordinates::from(91, 2));
        self::assertNull(Coordinates::from(45, 181));
        self::assertNull(Coordinates::from('', '4.8'));
        self::assertNull(Coordinates::from(null, 4.8));
        self::assertNotNull(Coordinates::from('45.7640', '4.8357'), 'Stores keep their coordinates as strings.');
    }
}
