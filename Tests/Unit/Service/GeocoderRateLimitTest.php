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

use GeolocatedDelivery\Service\Destination;
use GeolocatedDelivery\Service\Geocoder;
use GeolocatedDelivery\Service\GeocodingRateLimiter;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Bridge\PhpUnit\ClockMock;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Lock\Key;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\PersistingStoreInterface;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\CacheStorage;

/**
 * The calls a visitor can make the shop send to the address service. The rate limiter
 * reads the time through ClockMock (its namespaces are registered by phpunit.xml.dist):
 * the clock stands still unless a test moves it.
 */
final class GeocoderRateLimitTest extends TestCase
{
    private RequestStack $requestStack;
    private int $requestCount = 0;
    private Geocoder $geocoder;

    /** Where the rate limiter keeps its counters. */
    private ArrayAdapter $counters;

    /** @var \ArrayObject<int, array{level: string, message: string, context: array<mixed>}> */
    private \ArrayObject $logRecords;

    /** @var \ArrayObject<int, string> the resources locked, in order */
    private \ArrayObject $lockedResources;

    protected function setUp(): void
    {
        ClockMock::withClockMock(true);
        $this->logRecords = new \ArrayObject();
        $this->lockedResources = new \ArrayObject();
        $this->counters = new ArrayAdapter();
        $this->requestStack = new RequestStack();

        $client = new MockHttpClient(function (string $method, string $url): MockResponse {
            ++$this->requestCount;
            parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);

            return new MockResponse(json_encode(['type' => 'FeatureCollection', 'features' => [[
                'geometry' => ['type' => 'Point', 'coordinates' => [4.8357, 45.7640]],
                'properties' => ['postcode' => $query['postcode'] ?? ''],
            ]]], \JSON_THROW_ON_ERROR));
        });

        $this->geocoder = new Geocoder(
            $client,
            new ArrayAdapter(),
            $this->recordingLogger(),
            new GeocodingRateLimiter($this->counters, $this->requestStack, 'test-secret', new LockFactory($this->recordingLockStore())),
        );
    }

    protected function tearDown(): void
    {
        ClockMock::withClockMock(false);
    }

    public function testTheEleventhNewTownOfAVisitorWithinAMinuteIsNotAskedFor(): void
    {
        $this->visitorAt('203.0.113.7');

        for ($town = 1; $town <= GeocodingRateLimiter::VISITOR_LIMIT; ++$town) {
            self::assertNotNull($this->geocoder->locateDestination($this->town($town)));
        }

        self::assertNull($this->geocoder->locateDestination($this->town(11)));
        self::assertSame(GeocodingRateLimiter::VISITOR_LIMIT, $this->requestCount, 'No request for the eleventh town.');
    }

    public function testAnotherVisitorIsStillServed(): void
    {
        $this->visitorAt('203.0.113.7');
        for ($town = 1; $town <= GeocodingRateLimiter::VISITOR_LIMIT + 1; ++$town) {
            $this->geocoder->locateDestination($this->town($town));
        }

        $this->visitorAt('198.51.100.20');

        self::assertNotNull($this->geocoder->locateDestination($this->town(12)));
        self::assertSame(GeocodingRateLimiter::VISITOR_LIMIT + 1, $this->requestCount);
    }

    public function testTwoAddressesOfTheSameIpv6Slash64ShareTheLimit(): void
    {
        $this->visitorAt('2001:db8:1:2::10');
        for ($town = 1; $town <= 5; ++$town) {
            self::assertNotNull($this->geocoder->locateDestination($this->town($town)));
        }

        $this->visitorAt('2001:db8:1:2:ffff:ffff:ffff:ffff');
        for ($town = 6; $town <= GeocodingRateLimiter::VISITOR_LIMIT; ++$town) {
            self::assertNotNull($this->geocoder->locateDestination($this->town($town)));
        }

        self::assertNull($this->geocoder->locateDestination($this->town(11)), 'The eleventh town of the /64 is refused.');
        self::assertSame(GeocodingRateLimiter::VISITOR_LIMIT, $this->requestCount);
    }

    public function testAnotherIpv6Slash64IsStillServed(): void
    {
        $this->visitorAt('2001:db8:1:2::10');
        for ($town = 1; $town <= GeocodingRateLimiter::VISITOR_LIMIT + 1; ++$town) {
            $this->geocoder->locateDestination($this->town($town));
        }

        $this->visitorAt('2001:db8:1:3::10');

        self::assertNotNull($this->geocoder->locateDestination($this->town(12)));
    }

    public function testAnIpv4AddressIsCountedWhole(): void
    {
        $this->visitorAt('203.0.113.7');
        for ($town = 1; $town <= GeocodingRateLimiter::VISITOR_LIMIT + 1; ++$town) {
            $this->geocoder->locateDestination($this->town($town));
        }

        $this->visitorAt('203.0.113.8');

        self::assertNotNull($this->geocoder->locateDestination($this->town(12)));
    }

    public function testAnIpv4MappedAddressIsCountedAsItsIpv4(): void
    {
        $this->visitorAt('1.2.3.4');
        for ($town = 1; $town <= GeocodingRateLimiter::VISITOR_LIMIT; ++$town) {
            $this->geocoder->locateDestination($this->town($town));
        }

        $this->visitorAt('::ffff:1.2.3.4');

        self::assertNull($this->geocoder->locateDestination($this->town(11)), 'Same visitor, same limit.');
    }

    public function testTwoIpv4MappedAddressesAreTwoVisitors(): void
    {
        $this->visitorAt('::ffff:1.2.3.4');
        for ($town = 1; $town <= GeocodingRateLimiter::VISITOR_LIMIT + 1; ++$town) {
            $this->geocoder->locateDestination($this->town($town));
        }

        $this->visitorAt('::ffff:1.2.3.5');

        self::assertNotNull($this->geocoder->locateDestination($this->town(12)));
    }

    public function testAnIpv4BehindTheNat64PrefixIsCountedAsItsIpv4(): void
    {
        $this->visitorAt('64:ff9b::1.2.3.4');
        for ($town = 1; $town <= GeocodingRateLimiter::VISITOR_LIMIT; ++$town) {
            $this->geocoder->locateDestination($this->town($town));
        }

        $this->visitorAt('1.2.3.4');
        self::assertNull($this->geocoder->locateDestination($this->town(11)), 'Same visitor, same limit.');

        $this->visitorAt('64:ff9b::1.2.3.5');
        self::assertNotNull($this->geocoder->locateDestination($this->town(12)), 'Another IPv4 behind the same prefix.');
    }

    public function testATownAlreadyCachedCostsNoToken(): void
    {
        $this->visitorAt('203.0.113.7');

        for ($quote = 0; $quote < 25; ++$quote) {
            self::assertNotNull($this->geocoder->locateDestination($this->town(1)));
        }
        for ($town = 2; $town <= GeocodingRateLimiter::VISITOR_LIMIT; ++$town) {
            self::assertNotNull($this->geocoder->locateDestination($this->town($town)), 'Town '.$town.' is still within the limit.');
        }

        self::assertSame(GeocodingRateLimiter::VISITOR_LIMIT, $this->requestCount);
    }

    public function testARefusalIsNotCachedAndTheTownIsLocatedOnceTheWindowHasPassed(): void
    {
        $this->visitorAt('203.0.113.7');
        for ($town = 1; $town <= GeocodingRateLimiter::VISITOR_LIMIT; ++$town) {
            $this->geocoder->locateDestination($this->town($town));
        }
        self::assertNull($this->geocoder->locateDestination($this->town(11)));

        // Two windows: a sliding window still weighs the previous minute just after it ends.
        ClockMock::sleep(120);

        self::assertNotNull($this->geocoder->locateDestination($this->town(11)), 'The refusal was not kept.');
        self::assertSame(GeocodingRateLimiter::VISITOR_LIMIT + 1, $this->requestCount);
    }

    public function testTheShopStopsAtFortyCallsPerSecond(): void
    {
        for ($town = 1; $town <= GeocodingRateLimiter::SHOP_LIMIT; ++$town) {
            // A different visitor each time: only the shop limit can refuse.
            $this->visitorAt('198.51.100.'.$town);
            self::assertNotNull($this->geocoder->locateDestination($this->town($town)), 'Call '.$town.' is within the limit.');
        }

        $this->visitorAt('192.0.2.1');
        self::assertNull($this->geocoder->locateDestination($this->town(GeocodingRateLimiter::SHOP_LIMIT + 1)));
        self::assertSame(GeocodingRateLimiter::SHOP_LIMIT, $this->requestCount);
    }

    public function testACallTheShopRefusesCostsTheVisitorNoToken(): void
    {
        for ($town = 1; $town <= GeocodingRateLimiter::SHOP_LIMIT; ++$town) {
            $this->visitorAt('198.51.100.'.$town);
            $this->geocoder->locateDestination($this->town($town));
        }

        $this->visitorAt('203.0.113.7');
        for ($attempt = 1; $attempt <= 3; ++$attempt) {
            self::assertNull($this->geocoder->locateDestination($this->town(100 + $attempt)), 'The shop is at its limit.');
        }

        // Two seconds: the shop's sliding window has emptied, the visitor's minute has not.
        ClockMock::sleep(2);

        for ($town = 201; $town < 201 + GeocodingRateLimiter::VISITOR_LIMIT; ++$town) {
            self::assertNotNull($this->geocoder->locateDestination($this->town($town)), 'The visitor still has all the tokens of the minute.');
        }
        self::assertNull($this->geocoder->locateDestination($this->town(301)));
    }

    public function testACallTheVisitorLimitRefusesCostsTheShopNoToken(): void
    {
        $this->visitorAt('203.0.113.7');
        for ($town = 1; $town <= GeocodingRateLimiter::VISITOR_LIMIT + 20; ++$town) {
            $this->geocoder->locateDestination($this->town($town));
        }

        $left = GeocodingRateLimiter::SHOP_LIMIT - GeocodingRateLimiter::VISITOR_LIMIT;
        for ($visitor = 1; $visitor <= $left; ++$visitor) {
            $this->visitorAt('198.51.100.'.$visitor);
            self::assertNotNull($this->geocoder->locateDestination($this->town(100 + $visitor)), 'The twenty refused calls took nothing from the shop.');
        }

        $this->visitorAt('192.0.2.1');
        self::assertNull($this->geocoder->locateDestination($this->town(200)));
    }

    public function testAShopCounterAboveALoweredLimitCostsTheVisitorNoToken(): void
    {
        // A counter written when the shop limit was higher: more hits than the limit allows now.
        $formerLimit = new RateLimiterFactory(
            ['id' => 'geolocated_delivery_shop', 'policy' => 'sliding_window', 'limit' => 2 * GeocodingRateLimiter::SHOP_LIMIT, 'interval' => GeocodingRateLimiter::SHOP_INTERVAL],
            new CacheStorage($this->counters),
        );
        $formerLimit->create('shop')->consume(GeocodingRateLimiter::SHOP_LIMIT + 5);

        $this->visitorAt('203.0.113.7');
        for ($attempt = 1; $attempt <= 3; ++$attempt) {
            self::assertNull($this->geocoder->locateDestination($this->town($attempt)));
        }

        ClockMock::sleep(2);

        for ($town = 11; $town < 11 + GeocodingRateLimiter::VISITOR_LIMIT; ++$town) {
            self::assertNotNull($this->geocoder->locateDestination($this->town($town)), 'The visitor still has all the tokens of the minute.');
        }
    }

    public function testWithoutARequestOnlyTheShopLimitCounts(): void
    {
        for ($town = 1; $town <= GeocodingRateLimiter::SHOP_LIMIT; ++$town) {
            self::assertNotNull($this->geocoder->locateDestination($this->town($town)), 'Call '.$town.' is within the shop limit.');
        }

        self::assertNull($this->geocoder->locateDestination($this->town(GeocodingRateLimiter::SHOP_LIMIT + 1)));
        self::assertSame(GeocodingRateLimiter::SHOP_LIMIT, $this->requestCount);
    }

    public function testEachCounterIsReadAndWrittenUnderALock(): void
    {
        $this->visitorAt('203.0.113.7');

        $this->geocoder->locateDestination($this->town(1));

        self::assertNotEmpty(array_filter($this->lockedResources->getArrayCopy(), static fn (string $resource): bool => str_starts_with($resource, 'geolocated_delivery_visitor-')), 'The visitor counter is locked.');
        self::assertContains('geolocated_delivery_shop-shop', $this->lockedResources->getArrayCopy(), 'The shop counter is locked.');
    }

    public function testAVisitorRefusalIsLoggedAsInformationAndAShopRefusalAsAWarning(): void
    {
        $this->visitorAt('203.0.113.7');
        for ($town = 1; $town <= GeocodingRateLimiter::VISITOR_LIMIT + 1; ++$town) {
            $this->geocoder->locateDestination($this->town($town));
        }
        self::assertSame(['info'], array_column($this->logRecords->getArrayCopy(), 'level'), 'The visitor refusal.');

        // The ten towns of the first visitor count for the shop too: the 31st other call is refused.
        for ($visitor = 1; $visitor <= GeocodingRateLimiter::SHOP_LIMIT - GeocodingRateLimiter::VISITOR_LIMIT + 1; ++$visitor) {
            $this->visitorAt('198.51.100.'.$visitor);
            $this->geocoder->locateDestination($this->town(100 + $visitor));
        }

        self::assertSame(['info', 'warning'], array_column($this->logRecords->getArrayCopy(), 'level'), 'The shop refusal.');

        $written = json_encode($this->logRecords->getArrayCopy(), \JSON_THROW_ON_ERROR);
        foreach (['203.0.113', '198.51.100', 'Town', '69011', '69140'] as $private) {
            self::assertStringNotContainsString($private, $written);
        }
    }

    /**
     * A logger that remembers what it was told.
     */
    private function recordingLogger(): AbstractLogger
    {
        return new class($this->logRecords) extends AbstractLogger {
            /**
             * @param \ArrayObject<int, array{level: string, message: string, context: array<mixed>}> $records
             */
            public function __construct(private readonly \ArrayObject $records)
            {
            }

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->records->append(['level' => (string) $level, 'message' => (string) $message, 'context' => $context]);
            }
        };
    }

    /**
     * A lock store in memory that remembers what it locked.
     */
    private function recordingLockStore(): PersistingStoreInterface
    {
        return new class($this->lockedResources) implements PersistingStoreInterface {
            private InMemoryStore $store;

            /**
             * @param \ArrayObject<int, string> $lockedResources
             */
            public function __construct(private readonly \ArrayObject $lockedResources)
            {
                $this->store = new InMemoryStore();
            }

            public function save(Key $key): void
            {
                $this->lockedResources->append((string) $key);
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
                $this->store->putOffExpiration($key, $ttl);
            }
        };
    }

    private function visitorAt(string $ip): void
    {
        while (null !== $this->requestStack->pop()) {
        }

        $this->requestStack->push(Request::create('https://shop.test/order/delivery', server: ['REMOTE_ADDR' => $ip]));
    }

    private function town(int $number): Destination
    {
        $destination = Destination::of(\sprintf('69%03d', $number), 'Town '.$number);
        self::assertNotNull($destination);

        return $destination;
    }
}
