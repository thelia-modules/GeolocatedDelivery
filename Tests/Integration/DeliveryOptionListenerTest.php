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

namespace GeolocatedDelivery\Tests\Integration;

use GeolocatedDelivery\GeolocatedDelivery;
use Symfony\Component\HttpClient\Response\MockResponse;
use Thelia\Api\Resource\DeliveryModuleOption;
use Thelia\Domain\Shipping\Service\DeliveryOptionsProvider;
use Thelia\Model\Cart;
use Thelia\Model\Country;

/**
 * The checkout lists a carrier through its options: the local delivery has one when the
 * address is in reach, none otherwise.
 */
final class DeliveryOptionListenerTest extends RadiusTestCase
{
    public function testAnAddressInReachGetsTheLocalDeliveryOptionAtThePriceOfItsSlice(): void
    {
        $this->geocoderAnswers(self::townAt(self::LYON_THIRD, '69003'));
        $country = $this->country();

        $options = $this->optionsFor($this->cartShippedTo('69003', 'Lyon', $country), $country);

        self::assertCount(1, $options);
        self::assertSame(GeolocatedDelivery::getModuleCode(), $options[0]->getCode());
        self::assertTrue($options[0]->isValid());
        self::assertSame(5.0, $options[0]->getPostage());
        self::assertSame(5.0, $options[0]->getPostageUntaxed(), 'Without a postage tax rule, the price is charged as entered.');
        self::assertNotSame('', $options[0]->getTitle());
    }

    public function testAnAddressOutOfReachGetsNoOption(): void
    {
        $this->geocoderAnswers(self::townAt(self::MARSEILLE, '13001'));
        $country = $this->country();

        self::assertSame([], $this->optionsFor($this->cartShippedTo('13001', 'Marseille', $country), $country));
    }

    public function testAGeocoderInErrorGivesNoOptionAndNoException(): void
    {
        $this->geocoderAnswers(new MockResponse('', ['http_code' => 500]));
        $country = $this->country();

        self::assertSame([], $this->optionsFor($this->cartShippedTo('69003', 'Lyon', $country), $country));
    }

    public function testADatabaseFailureGivesNoOptionAndNoException(): void
    {
        $this->geocoderAnswers(self::townAt(self::LYON_THIRD, '69003'));
        $country = $this->country();
        $cart = $this->cartShippedTo('69003', 'Lyon', $country);

        self::assertSame([], $this->withTheStoreTableUnreadable(fn (): array => $this->optionsFor($cart, $country)));
    }

    /**
     * @return list<DeliveryModuleOption>
     */
    private function optionsFor(Cart $cart, Country $country): array
    {
        /** @var DeliveryOptionsProvider $provider */
        $provider = static::getContainer()->get(DeliveryOptionsProvider::class);

        return array_values($provider->getOptions($this->moduleModel(), null, $cart, $country, null));
    }
}
