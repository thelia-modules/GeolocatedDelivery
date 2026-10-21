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

namespace GeolocatedDelivery\Service;

use GeolocatedDelivery\Model\GeolocatedDeliveryRadius;
use GeolocatedDelivery\Model\GeolocatedDeliveryRadiusQuery;
use GeolocatedDelivery\Model\GeolocatedDeliveryStoreQuery;
use Symfony\Contracts\Service\ResetInterface;
use Thelia\Model\Address;
use Thelia\Model\Cart;
use Thelia\Model\Country;

/**
 * The price of the local delivery to a cart's address: the slice of the distance between
 * the address and the nearest store.
 *
 * Null when there is nothing to quote: no address, no located store, no slice for that
 * distance, or an address the geocoder could not locate.
 *
 * A checkout page asks for the same cart several times (the list of carriers, their options,
 * the postage estimate): the location of a destination is kept for the request, a failure
 * included, so that a geocoder down is waited for once.
 */
final class RadiusQuote implements ResetInterface
{
    /** @var array<string, Coordinates|null> */
    private array $locatedDestinations = [];

    public function __construct(
        private readonly Geocoder $geocoder,
        private readonly DistanceCalculator $distanceCalculator,
    ) {
    }

    public function priceFor(?Cart $cart, ?Address $address, ?Country $country): ?float
    {
        $destination = Destination::ofTheCart($cart, $address, $country);

        return null === $destination ? null : $this->priceTo($destination);
    }

    public function priceTo(Destination $destination): ?float
    {
        $distance = $this->distanceToTheNearestStore($destination);

        if (null === $distance) {
            return null;
        }

        return self::sliceContaining($distance, GeolocatedDeliveryRadiusQuery::create()->inMatchingOrder()->find())?->getPrice();
    }

    /**
     * The first slice, in the order given, whose bounds contain the distance, bounds included
     * as in the Thelia 2 version.
     *
     * @param iterable<GeolocatedDeliveryRadius> $slices
     */
    public static function sliceContaining(float $distance, iterable $slices): ?GeolocatedDeliveryRadius
    {
        foreach ($slices as $slice) {
            if ($distance >= $slice->getMinRadius() && $distance <= $slice->getMaxRadius()) {
                return $slice;
            }
        }

        return null;
    }

    public function reset(): void
    {
        $this->locatedDestinations = [];
    }

    private function distanceToTheNearestStore(Destination $destination): ?float
    {
        $stores = GeolocatedDeliveryStoreQuery::create()->located()->find();

        if (0 === $stores->count()) {
            return null;
        }

        $destinationCoordinates = $this->locate($destination);

        if (null === $destinationCoordinates) {
            return null;
        }

        $shortest = null;

        foreach ($stores as $store) {
            $storeCoordinates = Coordinates::from($store->getLatitude(), $store->getLongitude());

            if (null === $storeCoordinates) {
                continue;
            }

            $distance = $this->distanceCalculator->kilometresBetween($storeCoordinates, $destinationCoordinates);
            $shortest = null === $shortest ? $distance : min($shortest, $distance);
        }

        return $shortest;
    }

    private function locate(Destination $destination): ?Coordinates
    {
        $key = $destination->key();

        if (!\array_key_exists($key, $this->locatedDestinations)) {
            $this->locatedDestinations[$key] = $this->geocoder->locateDestination($destination);
        }

        return $this->locatedDestinations[$key];
    }
}
