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

use GeolocatedDelivery\Service\Destination;
use PHPUnit\Framework\TestCase;

final class DestinationTest extends TestCase
{
    public function testAPostalCodeAndACityWithinTheirLengthMakeADestination(): void
    {
        $destination = Destination::of(' 1234567890 ', str_repeat('é', 80));

        self::assertNotNull($destination);
        self::assertSame('1234567890', $destination->zipCode);
    }

    public function testAPostalCodeLongerThanTenCharactersIsNotLocated(): void
    {
        self::assertNull(Destination::of('12345678901', 'Lyon'));
    }

    public function testACityLongerThanEightyCharactersIsNotLocated(): void
    {
        self::assertNull(Destination::of('69003', str_repeat('é', 81)));
    }

    public function testAMissingPostalCodeOrCityIsNotLocated(): void
    {
        self::assertNull(Destination::of(' ', 'Lyon'));
        self::assertNull(Destination::of('69003', null));
    }
}
