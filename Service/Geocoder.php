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

use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Locates addresses with the Base Adresse Nationale (France).
 *
 * Never throws: a service down, slow or answering nonsense gives null and a log line, and
 * the checkout goes on without the local delivery. The log line names neither the address
 * nor the URL, which carries it.
 */
final readonly class Geocoder
{
    public const ENDPOINT = 'https://api-adresse.data.gouv.fr/search/';

    /** A town does not move: a located destination is kept for thirty days. */
    public const CACHE_LIFETIME_SECONDS = 2_592_000;

    /** Seconds a request may wait for the first byte, then for the whole answer. */
    private const TIMEOUT_SECONDS = 2.0;
    private const MAX_DURATION_SECONDS = 3.0;

    public function __construct(
        private HttpClientInterface $httpClient,
        private CacheInterface $cache,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * The town of a delivery address: only the postal code and the city are sent. Arrondissements
     * share the city name (Lyon, Paris, Marseille), so the answer carrying the postal code
     * asked for wins over the first one.
     */
    public function locateDestination(Destination $destination): ?Coordinates
    {
        $cached = $this->cache->get(
            'geolocated_delivery.destination.'.$destination->key(),
            function (ItemInterface $item, bool &$save) use ($destination): ?array {
                $coordinates = $this->locate([
                    'q' => $destination->city,
                    'postcode' => $destination->zipCode,
                    'type' => 'municipality',
                    'limit' => 5,
                ], $destination->zipCode);

                // A failure is not kept: the next quote asks again.
                if (null === $coordinates) {
                    $save = false;

                    return null;
                }

                $item->expiresAfter(self::CACHE_LIFETIME_SECONDS);

                return [$coordinates->latitude, $coordinates->longitude];
            },
        );

        return null === $cached ? null : Coordinates::from($cached[0], $cached[1]);
    }

    /**
     * A store, from its full address: a shop's address is not personal data, and the street
     * makes the distance exact. Not cached, a store is located once, when it is saved.
     */
    public function locateStore(string $street, string $zipCode, string $city): ?Coordinates
    {
        return $this->locate([
            'q' => trim($street.' '.$zipCode.' '.$city),
            'postcode' => $zipCode,
            'limit' => 1,
        ], $zipCode);
    }

    /**
     * @param array<string, string|int> $query
     */
    private function locate(array $query, string $zipCode): ?Coordinates
    {
        try {
            $answer = $this->httpClient->request('GET', self::ENDPOINT, [
                'query' => $query,
                'timeout' => self::TIMEOUT_SECONDS,
                'max_duration' => self::MAX_DURATION_SECONDS,
                'headers' => ['Accept' => 'application/json'],
            ])->toArray();
        } catch (ExceptionInterface $exception) {
            $this->logger->warning('GeolocatedDelivery: the address service did not answer, the local delivery is not offered.', [
                'failure' => $exception::class,
                'status' => $exception instanceof HttpExceptionInterface ? $exception->getResponse()->getStatusCode() : null,
            ]);

            return null;
        }

        $features = $answer['features'] ?? null;

        if (!\is_array($features) || [] === $features) {
            $this->logger->info('GeolocatedDelivery: the address service found no place for an address.');

            return null;
        }

        $coordinates = $this->coordinatesOf($this->featureFor($features, $zipCode));

        if (null === $coordinates) {
            $this->logger->warning('GeolocatedDelivery: the address service answered without usable coordinates.');
        }

        return $coordinates;
    }

    /**
     * @param array<mixed> $features
     */
    private function featureFor(array $features, string $zipCode): mixed
    {
        foreach ($features as $feature) {
            if (\is_array($feature) && ($feature['properties']['postcode'] ?? null) === $zipCode) {
                return $feature;
            }
        }

        return reset($features);
    }

    /**
     * GeoJSON points are written longitude first.
     */
    private function coordinatesOf(mixed $feature): ?Coordinates
    {
        if (!\is_array($feature)) {
            return null;
        }

        $point = $feature['geometry']['coordinates'] ?? null;

        if (!\is_array($point)) {
            return null;
        }

        return Coordinates::from($point[1] ?? null, $point[0] ?? null);
    }
}
