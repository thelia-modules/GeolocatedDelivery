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

/**
 * A point on the globe, in decimal degrees.
 */
final readonly class Coordinates
{
    private function __construct(
        public float $latitude,
        public float $longitude,
    ) {
    }

    /**
     * Null when either value is not a number or lies outside the globe.
     */
    public static function from(mixed $latitude, mixed $longitude): ?self
    {
        if (!is_numeric($latitude) || !is_numeric($longitude)) {
            return null;
        }

        $latitude = (float) $latitude;
        $longitude = (float) $longitude;

        if ($latitude < -90.0 || $latitude > 90.0 || $longitude < -180.0 || $longitude > 180.0) {
            return null;
        }

        return new self($latitude, $longitude);
    }
}
