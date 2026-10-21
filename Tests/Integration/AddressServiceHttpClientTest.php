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

namespace GeolocatedDelivery\Tests\Integration;

use GeolocatedDelivery\Service\Geocoder;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Thelia\Test\IntegrationTestCase;

/**
 * The URL of a lookup carries the town and the postal code of the customer: the client of
 * the geocoder must not write it to the log, as the framework client does on the
 * http_client channel. The requests go to a closed local port, never to the service.
 */
final class AddressServiceHttpClientTest extends IntegrationTestCase
{
    private const LOOKUP_URL = 'http://127.0.0.1:9/search/?q=Villeurbanne&postcode=69100';

    public function testTheClientOfTheGeocoderWritesNoUrlToTheLog(): void
    {
        $spy = new TestHandler();
        $httpClientLogger = static::getContainer()->get('monolog.logger.http_client');
        self::assertInstanceOf(Logger::class, $httpClientLogger);
        $httpClientLogger->pushHandler($spy);

        try {
            // The control: the framework client does write the URL there.
            $this->request(static::getContainer()->get(HttpClientInterface::class));
            self::assertTrue($this->logged($spy, 'Villeurbanne'), 'The spy hears the framework client.');
            $spy->clear();

            $this->request(static::getContainer()->get(Geocoder::HTTP_CLIENT_SERVICE));

            self::assertFalse($this->logged($spy, 'Villeurbanne'));
            self::assertFalse($this->logged($spy, '69100'));
        } finally {
            $httpClientLogger->popHandler();
        }
    }

    public function testTheGeocoderUsesThatClient(): void
    {
        $geocoder = static::getContainer()->get(Geocoder::class);

        self::assertSame(
            static::getContainer()->get(Geocoder::HTTP_CLIENT_SERVICE),
            (new \ReflectionProperty(Geocoder::class, 'httpClient'))->getValue($geocoder),
        );
    }

    private function request(object $client): void
    {
        self::assertInstanceOf(HttpClientInterface::class, $client);

        try {
            $client->request('GET', self::LOOKUP_URL, ['timeout' => 1, 'max_duration' => 1])->getStatusCode();
        } catch (TransportExceptionInterface) {
            // Nothing listens on that port: only what was logged on the way matters.
        }
    }

    private function logged(TestHandler $spy, string $needle): bool
    {
        foreach ($spy->getRecords() as $record) {
            if (str_contains($record->message, $needle) || str_contains(json_encode($record->context, \JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '', $needle)) {
                return true;
            }
        }

        return false;
    }
}
