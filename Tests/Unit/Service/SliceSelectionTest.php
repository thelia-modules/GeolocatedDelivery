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

use GeolocatedDelivery\Model\GeolocatedDeliveryRadius;
use GeolocatedDelivery\Service\RadiusQuote;
use PHPUnit\Framework\TestCase;

final class SliceSelectionTest extends TestCase
{
    public function testTheSliceContainingTheDistanceGivesThePrice(): void
    {
        $slices = [$this->slice(0, 10, 5.0), $this->slice(10, 30, 12.5)];

        self::assertSame(5.0, RadiusQuote::sliceContaining(2.9, $slices)?->getPrice());
        self::assertSame(12.5, RadiusQuote::sliceContaining(17.0, $slices)?->getPrice());
    }

    public function testBoundsAreIncludedAndTheFirstSliceWinsOnASharedBound(): void
    {
        $slices = [$this->slice(0, 10, 5.0), $this->slice(10, 30, 12.5)];

        self::assertSame(5.0, RadiusQuote::sliceContaining(0.0, $slices)?->getPrice());
        self::assertSame(5.0, RadiusQuote::sliceContaining(10.0, $slices)?->getPrice());
        self::assertSame(12.5, RadiusQuote::sliceContaining(30.0, $slices)?->getPrice());
    }

    public function testADistanceBeyondEverySliceHasNoPrice(): void
    {
        $slices = [$this->slice(0, 10, 5.0), $this->slice(10, 30, 12.5)];

        self::assertNull(RadiusQuote::sliceContaining(30.01, $slices));
        self::assertNull(RadiusQuote::sliceContaining(1.0, []));
    }

    public function testAGapBetweenTwoSlicesHasNoPrice(): void
    {
        $slices = [$this->slice(0, 5, 5.0), $this->slice(8, 30, 12.5)];

        self::assertNull(RadiusQuote::sliceContaining(6.5, $slices));
    }

    private function slice(int $minimum, int $maximum, float $price): GeolocatedDeliveryRadius
    {
        return (new GeolocatedDeliveryRadius())->setMinRadius($minimum)->setMaxRadius($maximum)->setPrice($price);
    }
}
