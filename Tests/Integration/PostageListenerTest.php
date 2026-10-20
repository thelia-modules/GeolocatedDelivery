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

use GeolocatedDelivery\EventListeners\PostageListener;
use GeolocatedDelivery\Model\GeolocatedDeliveryStoreQuery;
use Symfony\Component\HttpClient\Response\MockResponse;
use Thelia\Core\Event\Delivery\DeliveryPostageEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Domain\Checkout\Enum\DeliveryMode;
use Thelia\Model\Address;
use Thelia\Model\Cart;
use Thelia\Model\Country;

/**
 * The quote the checkout asks every carrier for, dispatched as the core dispatches it: the
 * core answers first for the module, the module listener then decides on the address.
 */
final class PostageListenerTest extends RadiusTestCase
{
    public function testAnAddressInReachIsQuotedThePriceOfItsSlice(): void
    {
        $this->geocoderAnswers(self::townAt(self::LYON_THIRD, '69003'));
        $country = $this->country();

        $event = $this->quote($this->cartShippedTo('69003', 'Lyon', $country), null, $country);

        self::assertTrue($event->isValidModule());
        self::assertSame(5.0, $event->getPostage()?->getAmount());
        self::assertSame(DeliveryMode::DELIVERY, $event->getDeliveryMode());
    }

    public function testAnAddressInTheSecondSliceIsQuotedItsPrice(): void
    {
        // About 17 km south of the store.
        $this->geocoderAnswers(self::townAt([4.8357, 45.6110], '69700'));
        $country = $this->country();

        $event = $this->quote($this->cartShippedTo('69700', 'Givors', $country), null, $country);

        self::assertTrue($event->isValidModule());
        self::assertSame(12.5, $event->getPostage()?->getAmount());
    }

    public function testAnAddressOutOfReachTurnsTheModuleDown(): void
    {
        $this->geocoderAnswers(self::townAt(self::MARSEILLE, '13001'));
        $country = $this->country();

        $event = $this->quote($this->cartShippedTo('13001', 'Marseille', $country), null, $country);

        self::assertFalse($event->isValidModule());
    }

    public function testAGeocoderInErrorTurnsTheModuleDownWithoutAnException(): void
    {
        $this->geocoderAnswers(new MockResponse('Service Unavailable', ['http_code' => 503]));
        $country = $this->country();

        $event = $this->quote($this->cartShippedTo('69003', 'Lyon', $country), null, $country);

        self::assertFalse($event->isValidModule());
        self::assertCount(1, $this->requestedUrls);
    }

    public function testACartWithoutAnAddressTurnsTheModuleDownWithoutAskingTheGeocoder(): void
    {
        $this->geocoderAnswers(self::townAt(self::LYON_THIRD, '69003'));

        $event = $this->quote($this->factory->cart(), null, $this->country());

        self::assertFalse($event->isValidModule());
        self::assertSame([], $this->requestedUrls);
    }

    public function testWithoutAStoreTheModuleIsTurnedDown(): void
    {
        $this->geocoderAnswers(self::townAt(self::LYON_THIRD, '69003'));
        GeolocatedDeliveryStoreQuery::create()->deleteAll($this->getPropelConnection());
        $country = $this->country();

        $event = $this->quote($this->cartShippedTo('69003', 'Lyon', $country), null, $country);

        self::assertFalse($event->isValidModule());
    }

    public function testTheAddressOfTheEventIsUsedWhenTheCartHasNone(): void
    {
        $this->geocoderAnswers(self::townAt(self::LYON_THIRD, '69003'));
        $country = $this->country();
        $customer = $this->factory->customer($this->factory->customerTitle());
        $address = $this->factory->address($customer, $country, null, ['zipcode' => '69003', 'city' => 'Lyon']);

        $event = $this->quote($this->factory->cart($customer), $address, $country);

        self::assertTrue($event->isValidModule());
        self::assertSame(5.0, $event->getPostage()?->getAmount());
    }

    public function testTheCartAddressInAnotherCountryIsNotQuotedForThisOne(): void
    {
        $this->geocoderAnswers(self::townAt(self::LYON_THIRD, '69003'));
        $country = $this->country();
        $otherCountry = $this->factory->country(['isocode' => '999', 'isoalpha2' => 'ZZ', 'isoalpha3' => 'ZZZ']);

        $event = $this->quote($this->cartShippedTo('69003', 'Lyon', $otherCountry), null, $country);

        self::assertFalse($event->isValidModule());
        self::assertSame([], $this->requestedUrls);
    }

    public function testAModuleTurnedDownBeforeTheListenerStaysTurnedDown(): void
    {
        $this->geocoderAnswers(self::townAt(self::LYON_THIRD, '69003'));
        $country = $this->country();
        $refuse = static fn (DeliveryPostageEvent $event) => $event->setValidModule(false);
        $dispatcher = static::getContainer()->get('event_dispatcher');
        // Between the core (128) and the module listener: what an inactive module, or another
        // module, decides there is not overturned.
        $dispatcher->addListener(TheliaEvents::MODULE_DELIVERY_GET_POSTAGE, $refuse, PostageListener::PRIORITY + 1);

        try {
            $event = $this->quote($this->cartShippedTo('69003', 'Lyon', $country), null, $country);
        } finally {
            $dispatcher->removeListener(TheliaEvents::MODULE_DELIVERY_GET_POSTAGE, $refuse);
        }

        self::assertFalse($event->isValidModule());
    }

    public function testTheSameTownIsLocatedOnceForTheRequest(): void
    {
        $this->geocoderAnswers(new MockResponse('Service Unavailable', ['http_code' => 503]));
        $country = $this->country();
        $cart = $this->cartShippedTo('69003', 'Lyon', $country);

        $this->quote($cart, null, $country);
        $this->quote($cart, null, $country);

        self::assertCount(1, $this->requestedUrls, 'A geocoder down is waited for once per request.');
    }

    private function quote(Cart $cart, ?Address $address, Country $country): DeliveryPostageEvent
    {
        $event = new DeliveryPostageEvent($this->moduleInstance(), $cart, $address, $country, null);
        static::getContainer()->get('event_dispatcher')->dispatch($event, TheliaEvents::MODULE_DELIVERY_GET_POSTAGE);

        return $event;
    }
}
