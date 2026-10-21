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
use Symfony\Component\Lock\LockFactory;
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
 * the located towns, and each one is read and written under a lock of the shop's lock
 * factory (framework.lock, the store LOCK_DSN names, which the core already uses for the
 * checkout): without it, requests in parallel would all read the same count and pass
 * together. Those locks live five seconds, not the five minutes of LockFactory (see
 * RateLimiterLockFactory).
 *
 * An IPv6 visitor is counted by the /64 prefix of the address: a single connection usually
 * receives a whole /64, and would otherwise get a fresh limit with every address of it.
 */
final readonly class GeocodingRateLimiter
{
    /** New towns a visitor may have located per minute. */
    public const VISITOR_LIMIT = 10;
    public const VISITOR_INTERVAL = '1 minute';

    /** Calls the whole shop may make to the address service per second. */
    public const SHOP_LIMIT = 40;
    public const SHOP_INTERVAL = '1 second';

    /** What refusalOfACall() answers when a limit refuses the call. */
    public const REFUSED_BY_VISITOR_LIMIT = 'visitor';
    public const REFUSED_BY_SHOP_LIMIT = 'shop';

    private RateLimiterFactory $perVisitor;
    private RateLimiterFactory $perShop;

    public function __construct(
        #[Autowire(service: 'cache.app')]
        CacheItemPoolInterface $cache,
        private RequestStack $requestStack,
        #[\SensitiveParameter]
        #[Autowire('%kernel.secret%')]
        private string $secret,
        LockFactory $lockFactory,
    ) {
        $storage = new CacheStorage($cache);
        $shortLivedLocks = new RateLimiterLockFactory($lockFactory);

        $this->perVisitor = new RateLimiterFactory([
            'id' => 'geolocated_delivery_visitor',
            'policy' => 'sliding_window',
            'limit' => self::VISITOR_LIMIT,
            'interval' => self::VISITOR_INTERVAL,
        ], $storage, $shortLivedLocks);

        $this->perShop = new RateLimiterFactory([
            'id' => 'geolocated_delivery_shop',
            'policy' => 'sliding_window',
            'limit' => self::SHOP_LIMIT,
            'interval' => self::SHOP_INTERVAL,
        ], $storage, $shortLivedLocks);
    }

    /**
     * Takes a token of each limit for one call to the address service. Null when the call may
     * go, otherwise the limit that refused it.
     *
     * Both limits are looked at before anything is taken, the visitor's first: a visitor over
     * his limit is turned away without touching the shop counter, whose lock every visitor
     * shares. Then the visitor's token is taken, then the shop's. A call the shop refuses
     * costs the visitor nothing, and a call the visitor's limit refuses costs the shop
     * nothing. Only two requests racing for the very last token, between the look and the
     * take, can cost one of them a token of the other limit; the limiters offer no way to
     * give a token back.
     */
    public function refusalOfACall(): ?string
    {
        $visitorKey = $this->visitorKey();
        $visitor = null === $visitorKey ? null : $this->perVisitor->create($visitorKey);

        if (null !== $visitor && $visitor->consume(0)->getRemainingTokens() <= 0) {
            return self::REFUSED_BY_VISITOR_LIMIT;
        }

        $shop = $this->perShop->create('shop');

        if ($shop->consume(0)->getRemainingTokens() <= 0) {
            return self::REFUSED_BY_SHOP_LIMIT;
        }

        if (null !== $visitor && !$visitor->consume()->isAccepted()) {
            return self::REFUSED_BY_VISITOR_LIMIT;
        }

        return $shop->consume()->isAccepted() ? null : self::REFUSED_BY_SHOP_LIMIT;
    }

    /**
     * The hashed IP of the visitor, an IPv6 cut to its /64 prefix. Null without a request.
     *
     * An IPv6 address that carries the IPv4 of the visitor is counted as that IPv4, whole (see
     * embeddedIpv4()): cut to /64, all the visitors behind one prefix would share a counter.
     */
    private function visitorKey(): ?string
    {
        $clientIp = $this->requestStack->getMainRequest()?->getClientIp();

        if (null === $clientIp || '' === $clientIp) {
            return null;
        }

        $packed = @inet_pton($clientIp);

        if (\is_string($packed) && 16 === \strlen($packed)) {
            $ipv4 = self::embeddedIpv4($packed);
            $clientIp = null !== $ipv4
                ? (string) inet_ntop($ipv4)
                : (string) inet_ntop(substr($packed, 0, 8).str_repeat("\0", 8)).'/64';
        }

        return hash_hmac('sha256', $clientIp, $this->secret);
    }

    /**
     * The IPv4 (packed) an IPv6 address stands for, or null.
     *
     * - IPv4-mapped, ::ffff:a.b.c.d (RFC 4291 2.5.5.2): what a dual-stack server hands for an
     *   IPv4 client.
     * - IPv4-translated, ::ffff:0:a.b.c.d (RFC 2765 2.1).
     * - IPv4-compatible, ::a.b.c.d (RFC 4291 2.5.5.1, deprecated). The unspecified address ::
     *   and the loopback ::1 are not IPv4 ones: they keep the /64 of every other address,
     *   ::/64, and share it.
     * - NAT64 well-known prefix, 64:ff9b::a.b.c.d (RFC 6052 2.1), under which a translator in
     *   front of an IPv6-only server (SIIT-DC, RFC 7755) shows each IPv4 client.
     * - 6to4, 2002:AABB:CCDD::/48 (RFC 3056 2): the IPv4 of the site in bytes 2 to 5.
     * - Teredo, 2001:0:SSSS:SSSS:FFFF:PPPP:CCCC:CCCC (RFC 4380 4): bytes 4 to 7 hold the IPv4
     *   of the Teredo server and bytes 12 to 15 the public IPv4 of the client, every bit
     *   inverted. The /64 is the server: every client of one server would share a counter, so
     *   the client's IPv4 is decoded instead.
     */
    private static function embeddedIpv4(string $packedIpv6): ?string
    {
        $first12 = substr($packedIpv6, 0, 12);
        $last4 = substr($packedIpv6, 12);

        return match (true) {
            str_repeat("\0", 10)."\xff\xff" === $first12,
            str_repeat("\0", 8)."\xff\xff\0\0" === $first12,
            "\x00\x64\xff\x9b".str_repeat("\0", 8) === $first12 => $last4,
            str_repeat("\0", 12) === $first12 => \in_array($last4, ["\0\0\0\0", "\0\0\0\x01"], true) ? null : $last4,
            str_starts_with($packedIpv6, "\x20\x02") => substr($packedIpv6, 2, 4),
            str_starts_with($packedIpv6, "\x20\x01\x00\x00") => ~$last4,
            default => null,
        };
    }
}
