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
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class GeocoderTest extends TestCase
{
    /** @var list<array{url: string, options: array<string, mixed>}> */
    private array $requests = [];

    public function testTheCoordinatesAreReadLongitudeFirstFromTheAnswer(): void
    {
        $geocoder = $this->geocoder(self::answer([[4.869577, 45.751578, '69003']]));

        $coordinates = $geocoder->locateDestination($this->lyonThird());

        self::assertNotNull($coordinates);
        self::assertSame(45.751578, $coordinates->latitude);
        self::assertSame(4.869577, $coordinates->longitude);
    }

    public function testOnlyThePostalCodeAndTheCityOfTheCustomerAreSent(): void
    {
        $geocoder = $this->geocoder(self::answer([[4.869577, 45.751578, '69003']]));

        $geocoder->locateDestination($this->lyonThird());

        self::assertCount(1, $this->requests);
        self::assertStringStartsWith(Geocoder::ENDPOINT.'?', $this->requests[0]['url']);
        parse_str((string) parse_url($this->requests[0]['url'], \PHP_URL_QUERY), $query);
        self::assertSame(['q' => 'Lyon', 'postcode' => '69003', 'type' => 'municipality', 'limit' => '5'], $query);
    }

    public function testTheRequestIsBoundedInTime(): void
    {
        $geocoder = $this->geocoder(self::answer([[4.869577, 45.751578, '69003']]));

        $geocoder->locateDestination($this->lyonThird());

        self::assertLessThanOrEqual(2.0, $this->requests[0]['options']['timeout']);
        self::assertLessThanOrEqual(3.0, $this->requests[0]['options']['max_duration']);
        self::assertGreaterThan(0, $this->requests[0]['options']['max_duration']);
    }

    public function testTheAnswerCarryingThePostalCodeWinsOverTheTownCentre(): void
    {
        // What the service answers for "Lyon" in 69003: the town first, then the arrondissement.
        $geocoder = $this->geocoder(self::answer([[4.835, 45.758, '69001'], [4.869577, 45.751578, '69003']]));

        $coordinates = $geocoder->locateDestination($this->lyonThird());

        self::assertNotNull($coordinates);
        self::assertSame(45.751578, $coordinates->latitude);
    }

    public function testWithoutTheRightPostalCodeTheFirstAnswerIsKept(): void
    {
        $geocoder = $this->geocoder(self::answer([[4.835, 45.758, '69001'], [4.85, 45.75, '69002']]));

        self::assertSame(45.758, $geocoder->locateDestination($this->lyonThird())?->latitude);
    }

    public function testAnAnswerWithoutAnyPlaceLocatesNothing(): void
    {
        $geocoder = $this->geocoder(new MockResponse('{"type":"FeatureCollection","features":[],"query":"Nowhere"}'));

        self::assertNull($geocoder->locateDestination($this->lyonThird()));
    }

    public function testAServerErrorLocatesNothingAndThrowsNothing(): void
    {
        $geocoder = $this->geocoder(new MockResponse('Service Unavailable', ['http_code' => 503]));

        self::assertNull($geocoder->locateDestination($this->lyonThird()));
    }

    public function testAServiceOutOfReachLocatesNothingAndThrowsNothing(): void
    {
        $geocoder = $this->geocoder(new MockResponse((static function (): \Generator {
            yield new TransportException('Connection timed out');
        })()));

        self::assertNull($geocoder->locateDestination($this->lyonThird()));
    }

    public function testAnAnswerThatIsNotJsonOrHasNoCoordinatesLocatesNothing(): void
    {
        self::assertNull($this->geocoder(new MockResponse('<html>maintenance</html>'))->locateDestination($this->lyonThird()));
        self::assertNull($this->geocoder(new MockResponse('{"features":[{"geometry":{"coordinates":[]}}]}'))->locateDestination($this->lyonThird()));
        self::assertNull($this->geocoder(new MockResponse('{"features":[{"geometry":{"coordinates":[200,95]}}]}'))->locateDestination($this->lyonThird()));
        self::assertNull($this->geocoder(new MockResponse('{"features":"none"}'))->locateDestination($this->lyonThird()));
    }

    public function testALocatedTownIsNotAskedForAgain(): void
    {
        $geocoder = $this->geocoder(self::answer([[4.869577, 45.751578, '69003']]), self::answer([[1.0, 1.0, '69003']]));

        $first = $geocoder->locateDestination($this->lyonThird());
        $second = $geocoder->locateDestination(Destination::of(' 69003 ', 'LYON'));

        self::assertCount(1, $this->requests, 'The second quote is answered from the cache.');
        self::assertEquals($first, $second);
    }

    public function testAFailureIsNotKeptAndTheTownIsAskedForAgain(): void
    {
        $geocoder = $this->geocoder(
            new MockResponse('Service Unavailable', ['http_code' => 503]),
            self::answer([[4.869577, 45.751578, '69003']]),
        );

        self::assertNull($geocoder->locateDestination($this->lyonThird()));
        self::assertNotNull($geocoder->locateDestination($this->lyonThird()));
        self::assertCount(2, $this->requests);
    }

    public function testAStoreIsLocatedFromItsFullAddress(): void
    {
        $geocoder = $this->geocoder(self::answer([[4.835792, 45.765523, '69001']]));

        $coordinates = $geocoder->locateStore('10 rue de la République', '69001', 'Lyon');

        self::assertSame(45.765523, $coordinates?->latitude);
        parse_str((string) parse_url($this->requests[0]['url'], \PHP_URL_QUERY), $query);
        self::assertSame('10 rue de la République 69001 Lyon', $query['q']);
        self::assertSame('69001', $query['postcode']);
        self::assertArrayNotHasKey('type', $query);
    }

    private function lyonThird(): Destination
    {
        $destination = Destination::of('69003', 'Lyon');
        self::assertNotNull($destination);

        return $destination;
    }

    private function geocoder(MockResponse ...$responses): Geocoder
    {
        $responses = array_values($responses);
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$responses): MockResponse {
            $this->requests[] = ['url' => $url, 'options' => $options];

            return array_shift($responses) ?? new MockResponse('', ['http_code' => 500]);
        });

        return new Geocoder($client, new ArrayAdapter(), new NullLogger());
    }

    /**
     * @param list<array{0: float, 1: float, 2: string}> $places longitude, latitude, postal code
     */
    private static function answer(array $places): MockResponse
    {
        $features = array_map(static fn (array $place): array => [
            'type' => 'Feature',
            'geometry' => ['type' => 'Point', 'coordinates' => [$place[0], $place[1]]],
            'properties' => ['postcode' => $place[2], 'type' => 'municipality'],
        ], $places);

        return new MockResponse(json_encode(['type' => 'FeatureCollection', 'features' => $features], \JSON_THROW_ON_ERROR));
    }
}
