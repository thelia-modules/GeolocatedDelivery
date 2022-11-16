<?php

namespace GeolocatedDelivery\Utils;

use Exception;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Thelia\Model\Address;
use GeolocatedDelivery\Model\GeolocatedDeliveryRadiusQuery;
use GeolocatedDelivery\Model\GeolocatedDeliveryStoreQuery;
use GeolocatedDelivery\GeolocatedDelivery;

class GeolocManager
{
    public static function getGeolocFromAddress(?Address $address){
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
            if ($statusCode != 200){
                throw new Exception("Bad status code: " . $statusCode);
            }

            $content = $response->getContent();

            if (!$content){
                throw new Exception("No content");
            }
            return $response->toArray();
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }
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
                $distance = self::getDistance($lat, $lng, $store->getLatitude(), $store->getLongitude());
                if ($distance <= 20) {
                    foreach ($radiues as $radius) {
                        if ($distance >= $radius->getMinRadius() && $distance <= $radius->getMaxRadius()) {
                            return $radius->getPrice();
                        }
                    }
                }
            }
        }catch (Exception $e){
        }
        return null;
    }

    public static function getDistance(?string $lat1, ?string $lon1, ?string $lat2, ?string $lon2): float
    {
        if (!$lat1 || !$lon1 || !$lat2 || !$lon2) {
            throw new Exception("Missing coordinates");
        }
        $theta = $lon1 - $lon2;
        $dist = sin(deg2rad($lat1)) * sin(deg2rad($lat2)) + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * cos(deg2rad($theta));
        $dist = acos($dist);
        $dist = rad2deg($dist);
        $miles = $dist * 60 * 1.1515;
        // convert to km
        return ($miles * 1.609344);
    }
}