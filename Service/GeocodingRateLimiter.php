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

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\CacheStorage;

/**
 * Caps the calls to the address service a visitor can trigger: any visitor reaches the
 * geocoder by typing a delivery address, one new town after another.
 *
 * Two limits, both counted only when the service is really about to be called (a town
 * already cached costs nothing):
 *
 * - per visitor, keyed by the client IP of the main request, hashed with the kernel secret
 *   so that no IP is ever written to the cache in clear;
 * - for the whole shop, under the usage limit of the Base Adresse Nationale (50 calls per
 *   second and per IP, https://adresse.data.gouv.fr/outils/api-doc/adresse, which advises a
 *   cap at 40 or 45). Without a request (command line), only this one applies.
 *
 * Both are sliding windows: a fixed window lets twice the limit through around the change
 * of window (40 calls at the end of one second, 40 at the start of the next), which would
 * break the 50 per second of the service. The counters live in the application cache, like
 * the located towns; no lock is taken, so two requests at the same instant may both pass
 * the last token.
 */
final readonly class GeocodingRateLimiter
{
    /** New towns a visitor may have located per minute. */
    public const VISITOR_LIMIT = 10;
    public const VISITOR_INTERVAL = '1 minute';

    /** Calls the whole shop may make to the address service per second. */
    public const SHOP_LIMIT = 40;
    public const SHOP_INTERVAL = '1 second';

    private RateLimiterFactory $perVisitor;
    private RateLimiterFactory $perShop;

    public function __construct(
        #[Autowire(service: 'cache.app')]
        CacheItemPoolInterface $cache,
        private RequestStack $requestStack,
        #[\SensitiveParameter]
        #[Autowire('%kernel.secret%')]
        private string $secret,
    ) {
        $storage = new CacheStorage($cache);

        $this->perVisitor = new RateLimiterFactory([
            'id' => 'geolocated_delivery_visitor',
            'policy' => 'sliding_window',
            'limit' => self::VISITOR_LIMIT,
            'interval' => self::VISITOR_INTERVAL,
        ], $storage);

        $this->perShop = new RateLimiterFactory([
            'id' => 'geolocated_delivery_shop',
            'policy' => 'sliding_window',
            'limit' => self::SHOP_LIMIT,
            'interval' => self::SHOP_INTERVAL,
        ], $storage);
    }

    /**
     * Takes a token for one call to the address service, and says whether the call may go.
     */
    public function allowsACall(): bool
    {
        $clientIp = $this->requestStack->getMainRequest()?->getClientIp();

        if (null !== $clientIp && '' !== $clientIp
            && !$this->perVisitor->create(hash_hmac('sha256', $clientIp, $this->secret))->consume()->isAccepted()) {
            return false;
        }

        return $this->perShop->create('shop')->consume()->isAccepted();
    }
}
