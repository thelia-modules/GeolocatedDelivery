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

namespace GeolocatedDelivery\Tests\Unit\Service;

use GeolocatedDelivery\Service\RateLimiterLockFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Lock\Key;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\PersistingStoreInterface;
use Symfony\Component\Lock\Store\InMemoryStore;

final class RateLimiterLockFactoryTest extends TestCase
{
    /** @var \ArrayObject<int, array{resource: string, lifetime: float}> */
    private \ArrayObject $shopLocks;

    protected function setUp(): void
    {
        $this->shopLocks = new \ArrayObject();
    }

    public function testALockByResourceComesFromTheShopFactoryAndLivesFiveSeconds(): void
    {
        $lock = $this->factory()->createLock('geolocated_delivery_shop-shop');

        self::assertTrue($lock->acquire());
        self::assertSame([['resource' => 'geolocated_delivery_shop-shop', 'lifetime' => RateLimiterLockFactory::LOCK_LIFETIME_SECONDS]], $this->shopLocks->getArrayCopy());
        $lock->release();
    }

    public function testALockByKeyComesFromTheShopFactoryAndLivesFiveSeconds(): void
    {
        $lock = $this->factory()->createLockFromKey(new Key('geolocated_delivery_shop-shop'), 300.0);

        self::assertTrue($lock->acquire());
        self::assertSame([['resource' => 'geolocated_delivery_shop-shop', 'lifetime' => RateLimiterLockFactory::LOCK_LIFETIME_SECONDS]], $this->shopLocks->getArrayCopy());
        $lock->release();
    }

    private function factory(): RateLimiterLockFactory
    {
        return new RateLimiterLockFactory(new LockFactory(new class($this->shopLocks) implements PersistingStoreInterface {
            private InMemoryStore $store;

            /**
             * @param \ArrayObject<int, array{resource: string, lifetime: float}> $shopLocks
             */
            public function __construct(private readonly \ArrayObject $shopLocks)
            {
                $this->store = new InMemoryStore();
            }

            public function save(Key $key): void
            {
                $this->store->save($key);
            }

            public function delete(Key $key): void
            {
                $this->store->delete($key);
            }

            public function exists(Key $key): bool
            {
                return $this->store->exists($key);
            }

            public function putOffExpiration(Key $key, float $ttl): void
            {
                $this->shopLocks->append(['resource' => (string) $key, 'lifetime' => $ttl]);
                $this->store->putOffExpiration($key, $ttl);
            }
        }));
    }
}
