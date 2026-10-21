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
 * Distance as the crow flies, by the haversine formula on a spherical Earth.
 */
final readonly class DistanceCalculator
{
    /** The mean radius of the Earth (IUGG), the one the Thelia 2 version used. */
    private const EARTH_RADIUS_KILOMETRES = 6371.0088;

    public function kilometresBetween(Coordinates $from, Coordinates $to): float
    {
        $fromLatitude = deg2rad($from->latitude);
        $toLatitude = deg2rad($to->latitude);
        $latitudeDelta = $toLatitude - $fromLatitude;
        $longitudeDelta = deg2rad($to->longitude - $from->longitude);

        $haversine = sin($latitudeDelta / 2) ** 2
            + cos($fromLatitude) * cos($toLatitude) * sin($longitudeDelta / 2) ** 2;

        return 2 * self::EARTH_RADIUS_KILOMETRES * asin(min(1.0, sqrt($haversine)));
    }
}
