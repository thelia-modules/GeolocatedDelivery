<?php

namespace GeolocatedDelivery\Utils;

use Exception;
use Symfony\Component\HttpClient\HttpClient;
use Thelia\Model\Address;
use GeolocatedDelivery\Model\GeolocatedDeliveryRadiusQuery;
use GeolocatedDelivery\Model\GeolocatedDeliveryStoreQuery;

class GeolocManager
{
    public static function getGeolocFromAddress(?Address $address)
    {
        try {
            if (!$address) {
                throw new Exception("No addresse found");
            }

            $httpClient = HttpClient::create();

            $response = $httpClient->request(
                'GET',
                "https://api-adresse.data.gouv.fr/search/"
                . "?q=" . $address->getAddress1()
                . "+" . $address->getCity()
                . "&" . "postcode" . $address->getZipcode()
            );


            $statusCode = $response->getStatusCode();

            if ($statusCode != 200) {
                throw new Exception("Bad status code: " . $statusCode);
            }

            $content = $response->getContent();

            if (!$content) {
                throw new Exception("No content");
            }
            return $response->toArray();
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }
    }

    public function check()
    {

    }

    public static function getRadius(?Address $address): ?float
    {
        try {
            $content = self::getGeolocFromAddress($address);


            $lat = $content['features'][0]['geometry']['coordinates'][1];
            $lng = $content['features'][0]['geometry']['coordinates'][0];

            $stores = GeolocatedDeliveryStoreQuery::create()->find();
            $radiues = GeolocatedDeliveryRadiusQuery::create()->find();

            foreach ($stores as $store) {
                $distance = self::getDistance($lat, $store->getLatitude(), $lng, $store->getLongitude());
                if ($distance <= 20) {
                    foreach ($radiues as $radius) {
                        if ($distance >= $radius->getMinRadius() && $distance <= $radius->getMaxRadius()) {
                            return $radius->getPrice();
                        }
                    }
                }
            }
        } catch (Exception $e) {

        }
        return null;
    }

    public static function getDistance($latitude1, $latitude2, $longitude1, $longitude2)
    {
        if (($latitude1 == $latitude2) && ($longitude1 == $longitude2)) {
            return 0;
        }

        $p1 = deg2rad($latitude1);
        $p2 = deg2rad($latitude2);
        $dp = deg2rad($latitude2 - $latitude1);
        $dl = deg2rad($longitude2 - $longitude1);
        $a = (sin($dp / 2) * sin($dp / 2)) + (cos($p1) * cos($p2) * sin($dl / 2) * sin($dl / 2));
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        $r = 6371008; // Earth's average radius, in meters
        $d = $r * $c;

        return $d;

    }

    /*public static function getDistance(?string $lat1, ?string $lon1, ?string $lat2, ?string $lon2): float
    {
        if (!$lat1 || !$lon1 || !$lat2 || !$lon2) {
            throw new Exception("Missing coordinates");
        }

        $delta_lat = $lat2 - $lat1;
        $delta_lon = $lon2 - $lon1;

        $earth_radius = 6372.795477598;

        $alpha = $delta_lat / 2;
        $beta = $delta_lon / 2;
        $a = sin(deg2rad($alpha)) * sin(deg2rad($alpha)) + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin(deg2rad($beta)) * sin(deg2rad($beta));
        $c = asin(min(1, sqrt($a)));
        $distance = 2 * $earth_radius * $c;
        $distance = round($distance, 4);

        return $distance;
    }*/
}