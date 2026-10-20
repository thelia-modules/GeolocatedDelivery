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

use Thelia\Model\Address;
use Thelia\Model\Cart;
use Thelia\Model\Country;

/**
 * Where a parcel goes, as far as this module needs to know: the postal code and the city.
 * The street never leaves the shop, the geocoding service only learns the town.
 */
final readonly class Destination
{
    private function __construct(
        public string $zipCode,
        public string $city,
    ) {
    }

    public static function of(?string $zipCode, ?string $city): ?self
    {
        $zipCode = trim((string) $zipCode);
        $city = trim((string) $city);

        if ('' === $zipCode || '' === $city) {
            return null;
        }

        return new self($zipCode, $city);
    }

    /**
     * The cart's own copy of its delivery address is the address the customer ships to, a
     * guest's included; the core hands the delivery event no address in most cases. The copy
     * counts only when it is in the country being quoted, the way the core reads its postal
     * code (ShippingFacade::cartZipCodeIn()). Otherwise the address of the event, when there
     * is one.
     */
    public static function ofTheCart(?Cart $cart, ?Address $address, ?Country $country): ?self
    {
        $cartAddress = $cart?->getCartAddressRelatedByAddressDeliveryId();

        if (null !== $cartAddress && (null === $country || $cartAddress->getCountryId() === $country->getId())) {
            return self::of($cartAddress->getZipcode(), $cartAddress->getCity());
        }

        if (null !== $address && (null === $country || $address->getCountryId() === $country->getId())) {
            return self::of($address->getZipcode(), $address->getCity());
        }

        return null;
    }

    /**
     * Same town, same key, whatever the case and the spaces the customer typed.
     */
    public function key(): string
    {
        return sha1(mb_strtolower($this->zipCode).'|'.mb_strtolower(preg_replace('/\s+/u', ' ', $this->city) ?? $this->city));
    }
}
