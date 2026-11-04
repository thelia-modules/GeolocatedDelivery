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

use Symfony\Component\Lock\Key;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\SharedLockInterface;

/**
 * The shop's lock factory, with locks that expire after a few seconds.
 *
 * The rate limiter asks for its locks with the default lifetime of LockFactory, 300 seconds,
 * and waits for them. With a store shared between servers (Redis, a database), a lock left
 * by a process that died would hold every checkout of the shop for five minutes. Reading and
 * writing a counter takes milliseconds: a lock that outlives a few seconds is a dead one.
 *
 * LockFactory is not final, and RateLimiterFactory only accepts a LockFactory: this class
 * extends it to hand the rate limiter the locks of the shop's factory with that lifetime. It
 * has no store of its own and never reaches the parent's.
 */
final class RateLimiterLockFactory extends LockFactory
{
    public const LOCK_LIFETIME_SECONDS = 5.0;

    public function __construct(
        private readonly LockFactory $shopLockFactory,
    ) {
    }

    public function createLock(string $resource, ?float $ttl = 300.0, bool $autoRelease = true): SharedLockInterface
    {
        return $this->shopLockFactory->createLock($resource, self::LOCK_LIFETIME_SECONDS, $autoRelease);
    }

    public function createLockFromKey(Key $key, ?float $ttl = 300.0, bool $autoRelease = true): SharedLockInterface
    {
        return $this->shopLockFactory->createLockFromKey($key, self::LOCK_LIFETIME_SECONDS, $autoRelease);
    }
}
