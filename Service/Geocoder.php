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
 *
 * The answer for a delivery town is cached, a failure included, so that an outage or an
 * unknown town does not make every checkout page wait on the service again: a located town
 * for thirty days, a town the service does not know for an hour, a technical failure (no
 * answer, an HTTP error, an answer that is not JSON or has no usable coordinates) for a
 * minute.
 *
 * A town that is not cached costs a token of GeocodingRateLimiter before the service is
 * called. A refused call is not cached: the visitor who went over the limit gets no local
 * delivery for that address, the next customer of the same town is answered.
 */
final readonly class Geocoder
{
    public const ENDPOINT = 'https://api-adresse.data.gouv.fr/search/';

    /** A town does not move: a located destination is kept for thirty days. */
    public const CACHE_LIFETIME_SECONDS = 2_592_000;

    /** A town the service does not know is asked for again after an hour. */
    public const NOT_FOUND_CACHE_LIFETIME_SECONDS = 3_600;

    /** After a technical failure, the service is asked again a minute later. */
    public const FAILURE_CACHE_LIFETIME_SECONDS = 60;

    /** What locate() answers instead of coordinates. */
    private const NOT_FOUND = 'not_found';
    private const FAILURE = 'failure';

    /** Not a cached status: a call the rate limiter refused is never kept. */
    private const REFUSED = 'refused';

    /** Seconds a request may wait for the first byte, then for the whole answer. */
    private const TIMEOUT_SECONDS = 2.0;
    private const MAX_DURATION_SECONDS = 3.0;

    public function __construct(
        private HttpClientInterface $httpClient,
        private CacheInterface $cache,
        private LoggerInterface $logger,
        private GeocodingRateLimiter $rateLimiter,
    ) {
    }

    /**
     * The town of a delivery address: only the postal code and the city are sent. Arrondissements
     * share the city name (Lyon, Paris, Marseille), so the answer carrying the postal code
     * asked for wins over the first one.
     */
    public function locateDestination(Destination $destination): ?Coordinates
    {
        // "v2": the entries of the first version hold no status.
        $cached = $this->cache->get(
            'geolocated_delivery.destination.v2.'.$destination->key(),
            function (ItemInterface $item, bool &$save) use ($destination): array {
                if (null !== $this->rateLimiter->refusalOfACall()) {
                    $this->logger->info('GeolocatedDelivery: too many new towns asked for, the address service is not called.');
                    $save = false;

                    return ['status' => self::REFUSED];
                }

                $located = $this->locate([
                    'q' => $destination->city,
                    'postcode' => $destination->zipCode,
                    'type' => 'municipality',
                    'limit' => 5,
                ], $destination->zipCode);

                if (!$located instanceof Coordinates) {
                    $item->expiresAfter(self::NOT_FOUND === $located ? self::NOT_FOUND_CACHE_LIFETIME_SECONDS : self::FAILURE_CACHE_LIFETIME_SECONDS);

                    return ['status' => $located];
                }

                $item->expiresAfter(self::CACHE_LIFETIME_SECONDS);

                return ['status' => 'found', 'latitude' => $located->latitude, 'longitude' => $located->longitude];
            },
        );

        if ('found' !== $cached['status']) {
            return null;
        }

        return Coordinates::from($cached['latitude'], $cached['longitude']);
    }

    /**
     * A store, from its full address: a shop's address is not personal data, and the street
     * makes the distance exact. Not cached, a store is located once, when it is saved.
     */
    public function locateStore(string $street, string $zipCode, string $city): ?Coordinates
    {
        $located = $this->locate([
            'q' => trim($street.' '.$zipCode.' '.$city),
            'postcode' => $zipCode,
            'limit' => 1,
        ], $zipCode);

        return $located instanceof Coordinates ? $located : null;
    }

    /**
     * @param array<string, string|int> $query
     *
     * @return Coordinates|self::NOT_FOUND|self::FAILURE
     */
    private function locate(array $query, string $zipCode): Coordinates|string
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

            return self::FAILURE;
        }

        $features = $answer['features'] ?? null;

        if ([] === $features) {
            $this->logger->info('GeolocatedDelivery: the address service found no place for an address.');

            return self::NOT_FOUND;
        }

        $coordinates = \is_array($features) ? $this->coordinatesOf($this->featureFor($features, $zipCode)) : null;

        if (null === $coordinates) {
            $this->logger->warning('GeolocatedDelivery: the address service answered without usable coordinates.');

            return self::FAILURE;
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
