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
use GeolocatedDelivery\Model\GeolocatedDeliveryRadius;
use GeolocatedDelivery\Model\GeolocatedDeliveryRadiusQuery;
use GeolocatedDelivery\Model\GeolocatedDeliveryStore;
use GeolocatedDelivery\Model\GeolocatedDeliveryStoreQuery;
use GeolocatedDelivery\Service\Geocoder;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Thelia\Model\Cart;
use Thelia\Model\Country;
use Thelia\Model\Module;
use Thelia\Model\ModuleQuery;
use Thelia\Test\FixtureFactory;
use Thelia\Test\IntegrationTestCase;

/**
 * One store in the centre of Lyon, a slice up to 10 km at 5.00 and one from 10 to 30 km at
 * 12.50, and a geocoder that answers without the network.
 */
abstract class RadiusTestCase extends IntegrationTestCase
{
    /** The arrondissement 69003, about 3 km from the store. */
    protected const LYON_THIRD = [4.869577, 45.751578];

    /** Marseille, about 278 km from the store. */
    protected const MARSEILLE = [5.369780, 43.296482];

    protected FixtureFactory $factory;

    /** @var list<string> */
    protected array $requestedUrls = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->factory = $this->createFixtureFactory();

        // The stores and slices a shop already has must not decide the tests.
        GeolocatedDeliveryStoreQuery::create()->deleteAll($this->getPropelConnection());
        GeolocatedDeliveryRadiusQuery::create()->deleteAll($this->getPropelConnection());

        (new GeolocatedDeliveryStore())
            ->setName('Lyon centre')
            ->setStreet('1 place Bellecour')
            ->setZipCode('69002')
            ->setCity('Lyon')
            ->setLatitude('45.7640')
            ->setLongitude('4.8357')
            ->save($this->getPropelConnection());

        $this->slice(0, 10, 5.0);
        $this->slice(10, 30, 12.5);
    }

    /**
     * Puts a geocoder answering these responses, in this order, in place of the real one. To
     * be called before the first quote of the test: the services are built on first use.
     */
    protected function geocoderAnswers(MockResponse ...$responses): void
    {
        $responses = array_values($responses);
        $client = new MockHttpClient(function (string $method, string $url) use (&$responses): MockResponse {
            $this->requestedUrls[] = $url;

            return array_shift($responses) ?? new MockResponse('', ['http_code' => 500]);
        });

        static::getContainer()->set(Geocoder::class, new Geocoder($client, new ArrayAdapter(), new NullLogger()));
    }

    /**
     * @param array{0: float, 1: float} $place longitude, latitude
     */
    protected static function townAt(array $place, string $zipCode): MockResponse
    {
        return new MockResponse(json_encode([
            'type' => 'FeatureCollection',
            'features' => [[
                'type' => 'Feature',
                'geometry' => ['type' => 'Point', 'coordinates' => $place],
                'properties' => ['postcode' => $zipCode, 'type' => 'municipality'],
            ]],
        ], \JSON_THROW_ON_ERROR));
    }

    protected function slice(int $minimum, int $maximum, float $price): GeolocatedDeliveryRadius
    {
        $slice = (new GeolocatedDeliveryRadius())->setMinRadius($minimum)->setMaxRadius($maximum)->setPrice($price);
        $slice->save($this->getPropelConnection());

        return $slice;
    }

    /**
     * A guest's cart: its own copy of the delivery address, with no customer address behind it.
     */
    protected function cartShippedTo(string $zipCode, string $city, Country $country): Cart
    {
        $cartAddress = $this->factory->cartAddress(null, $country, null, ['zipcode' => $zipCode, 'city' => $city]);

        $cart = $this->factory->cart();
        $cart->setAddressDeliveryId($cartAddress->getId())->save($this->getPropelConnection());

        return $cart;
    }

    protected function country(): Country
    {
        return $this->factory->country();
    }

    protected function moduleModel(): Module
    {
        $module = ModuleQuery::create()->findOneByCode(GeolocatedDelivery::getModuleCode());
        self::assertNotNull($module, 'The module is registered in the test database.');
        self::assertSame(1, (int) $module->getActivate(), 'The module is active in the test database.');

        return $module;
    }

    protected function moduleInstance(): GeolocatedDelivery
    {
        $instance = $this->moduleModel()->getDeliveryModuleInstance(static::getContainer());
        self::assertInstanceOf(GeolocatedDelivery::class, $instance);

        return $instance;
    }
}
