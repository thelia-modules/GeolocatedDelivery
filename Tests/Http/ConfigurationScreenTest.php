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

namespace GeolocatedDelivery\Tests\Http;

use GeolocatedDelivery\GeolocatedDelivery;
use GeolocatedDelivery\Model\GeolocatedDeliveryRadius;
use GeolocatedDelivery\Model\GeolocatedDeliveryRadiusQuery;
use GeolocatedDelivery\Model\GeolocatedDeliveryStore;
use GeolocatedDelivery\Model\GeolocatedDeliveryStoreQuery;
use GeolocatedDelivery\Service\Geocoder;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Model\Admin;
use Thelia\Model\AreaDeliveryModule;
use Thelia\Model\AreaDeliveryModuleQuery;
use Thelia\Model\AreaQuery;
use Thelia\Model\ModuleQuery;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Support\BackOffice\AdminSessionInjector;

/**
 * The configuration page of the module: stores, radius slices, and the warning while the
 * module serves no shipping zone.
 */
final class ConfigurationScreenTest extends WebIntegrationTestCase
{
    private const CONFIGURATION_URL = '/admin/module/GeolocatedDelivery';

    private ?AdminSessionInjector $injector = null;

    /** @var list<string> */
    private array $requestedUrls = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->injector = new AdminSessionInjector();
        $this->getService(EventDispatcherInterface::class)->addSubscriber($this->injector);

        GeolocatedDeliveryStoreQuery::create()->deleteAll();
        GeolocatedDeliveryRadiusQuery::create()->deleteAll();
        $this->loginAs($this->factory()->admin());
    }

    protected function tearDown(): void
    {
        $this->injector?->clear();

        parent::tearDown();
    }

    public function testThePageListsTheStoresAndTheSlices(): void
    {
        $store = $this->store('Lyon centre');
        $slice = (new GeolocatedDeliveryRadius())->setMinRadius(0)->setMaxRadius(10)->setPrice(5.0);
        $slice->save();

        $this->assertPageRenders(self::CONFIGURATION_URL);

        $crawler = $this->client->getCrawler();
        self::assertStringContainsString('Lyon centre', $crawler->filter('[data-testid="geolocateddelivery-store-'.$store->getId().'"]')->text());
        self::assertSame(1, $crawler->filter('[data-testid="geolocateddelivery-slice-'.$slice->getId().'"]')->count());
    }

    public function testThePageWarnsWhileTheModuleServesNoShippingZone(): void
    {
        $moduleId = (int) ModuleQuery::create()->findOneByCode(GeolocatedDelivery::getModuleCode())?->getId();
        AreaDeliveryModuleQuery::create()->filterByDeliveryModuleId($moduleId)->delete();

        $this->assertPageRenders(self::CONFIGURATION_URL);
        self::assertSame(1, $this->client->getCrawler()->filter('[data-testid="geolocateddelivery-no-zone"]')->count());

        $area = AreaQuery::create()->findOne();
        self::assertNotNull($area, 'The test database has a shipping zone.');
        (new AreaDeliveryModule())->setAreaId($area->getId())->setDeliveryModuleId($moduleId)->save();

        $this->assertPageRenders(self::CONFIGURATION_URL);
        self::assertSame(0, $this->client->getCrawler()->filter('[data-testid="geolocateddelivery-no-zone"]')->count());
    }

    public function testThePageIsTranslatedInTheLanguageOfTheInterface(): void
    {
        $this->assertPageRenders(self::CONFIGURATION_URL.'?lang=fr');

        self::assertStringContainsString('Tranches de distance', $this->client->getCrawler()->filter('[data-testid="geolocateddelivery-slices"]')->text());
    }

    public function testAStoreIsAddedWithTheCoordinatesOfItsFullAddress(): void
    {
        $this->geocoderAnswers(new MockResponse(json_encode([
            'type' => 'FeatureCollection',
            'features' => [['geometry' => ['type' => 'Point', 'coordinates' => [4.835792, 45.765523]], 'properties' => ['postcode' => '69001']]],
        ], \JSON_THROW_ON_ERROR)));
        $this->assertPageRenders(self::CONFIGURATION_URL);

        $this->client->submit($this->client->getCrawler()->filter('[data-testid="geolocateddelivery-store-form"]')->form(), [
            'name' => 'Lyon centre',
            'street' => '10 rue de la République',
            'zip_code' => '69001',
            'city' => 'Lyon',
        ]);

        self::assertTrue($this->client->getResponse()->isRedirect());
        $store = GeolocatedDeliveryStoreQuery::create()->findOneByName('Lyon centre');
        self::assertNotNull($store);
        self::assertSame('45.765523', $store->getLatitude());
        self::assertSame('4.835792', $store->getLongitude());
        self::assertCount(1, $this->requestedUrls);
        self::assertStringContainsString('q=10%20rue%20de%20la%20R%C3%A9publique%2069001%20Lyon', $this->requestedUrls[0]);
    }

    public function testAStoreThatCannotBeLocatedIsNotAddedAndTheAdministratorIsTold(): void
    {
        $this->geocoderAnswers(new MockResponse('Service Unavailable', ['http_code' => 503]));
        $this->assertPageRenders(self::CONFIGURATION_URL);

        $this->client->submit($this->client->getCrawler()->filter('[data-testid="geolocateddelivery-store-form"]')->form(), [
            'name' => 'Nowhere',
            'street' => '1 rue inconnue',
            'zip_code' => '00000',
            'city' => 'Nulle part',
        ]);

        self::assertTrue($this->client->getResponse()->isRedirect());
        self::assertSame(0, GeolocatedDeliveryStoreQuery::create()->count());
        $this->client->followRedirect();
        self::assertStringContainsString(
            'The address of the store could not be located',
            $this->client->getCrawler()->filter('[data-testid="bo-flash-danger"]')->text(),
        );
    }

    public function testASliceWhoseMaximumIsNotAboveItsMinimumIsRefused(): void
    {
        $this->assertPageRenders(self::CONFIGURATION_URL);

        $this->client->submit($this->client->getCrawler()->filter('[data-testid="geolocateddelivery-slice-form"]')->form(), [
            'min_radius' => '10',
            'max_radius' => '5',
            'price' => '4.50',
        ]);

        self::assertSame(0, GeolocatedDeliveryRadiusQuery::create()->count());
        $this->client->followRedirect();
        self::assertStringContainsString(
            'The maximum distance must be greater than the minimum distance.',
            $this->client->getCrawler()->filter('[data-testid="bo-flash-danger"]')->text(),
        );
    }

    public function testARefusalIsWrittenInTheLanguageOfTheInterface(): void
    {
        $this->assertPageRenders(self::CONFIGURATION_URL.'?lang=fr');

        $this->client->submit($this->client->getCrawler()->filter('[data-testid="geolocateddelivery-slice-form"]')->form(), [
            'min_radius' => '10',
            'max_radius' => '5',
            'price' => '4.50',
        ]);

        $this->client->followRedirect();
        self::assertStringContainsString(
            'La distance maximale doit être supérieure à la distance minimale.',
            $this->client->getCrawler()->filter('[data-testid="bo-flash-danger"]')->text(),
        );
    }

    public function testASliceWithANegativePriceIsRefused(): void
    {
        $this->assertPageRenders(self::CONFIGURATION_URL);

        $this->client->submit($this->client->getCrawler()->filter('[data-testid="geolocateddelivery-slice-form"]')->form(), [
            'min_radius' => '0',
            'max_radius' => '5',
            'price' => '-1',
        ]);

        self::assertSame(0, GeolocatedDeliveryRadiusQuery::create()->count());
    }

    public function testASliceIsAddedThenDeleted(): void
    {
        $this->assertPageRenders(self::CONFIGURATION_URL);

        $this->client->submit($this->client->getCrawler()->filter('[data-testid="geolocateddelivery-slice-form"]')->form(), [
            'min_radius' => '0',
            'max_radius' => '10',
            'price' => '4,50',
        ]);

        $slice = GeolocatedDeliveryRadiusQuery::create()->findOne();
        self::assertNotNull($slice);
        self::assertSame(0, $slice->getMinRadius());
        self::assertSame(10, $slice->getMaxRadius());
        self::assertSame(4.5, $slice->getPrice());

        $this->assertPageRenders(self::CONFIGURATION_URL);
        $this->client->submit($this->client->getCrawler()->filter('[data-testid="geolocateddelivery-slice-'.$slice->getId().'"] form')->form());

        self::assertSame(0, GeolocatedDeliveryRadiusQuery::create()->count());
    }

    public function testAStoreIsDeletedWithTheTokenOfThePage(): void
    {
        $store = $this->store('Lyon centre');
        $this->assertPageRenders(self::CONFIGURATION_URL);

        $this->client->submit($this->client->getCrawler()->filter('[data-testid="geolocateddelivery-store-'.$store->getId().'"] form')->form());

        self::assertNull(GeolocatedDeliveryStoreQuery::create()->findPk($store->getId()));
    }

    public function testDeletingWithoutTheTokenChangesNothing(): void
    {
        $store = $this->store('Lyon centre');
        $slice = (new GeolocatedDeliveryRadius())->setMinRadius(0)->setMaxRadius(10)->setPrice(5.0);
        $slice->save();

        $this->client->request('POST', self::CONFIGURATION_URL.'/stores/'.$store->getId().'/delete');
        self::assertFalse($this->client->getResponse()->isRedirect(), 'The request is refused, not handled.');
        $this->client->request('POST', self::CONFIGURATION_URL.'/radii/'.$slice->getId().'/delete', ['_token' => 'forged']);
        self::assertFalse($this->client->getResponse()->isRedirect());

        self::assertNotNull(GeolocatedDeliveryStoreQuery::create()->findPk($store->getId()));
        self::assertNotNull(GeolocatedDeliveryRadiusQuery::create()->findPk($slice->getId()));
    }

    public function testAnAdministratorWithoutTheRightToTheModuleChangesNothing(): void
    {
        $this->geocoderAnswers();
        $store = $this->store('Lyon centre');
        $this->assertPageRenders(self::CONFIGURATION_URL);
        $crawler = $this->client->getCrawler();
        $createStore = $crawler->filter('[data-testid="geolocateddelivery-store-form"]')->form();
        $createSlice = $crawler->filter('[data-testid="geolocateddelivery-slice-form"]')->form();
        $deleteStore = $crawler->filter('[data-testid="geolocateddelivery-store-'.$store->getId().'"] form')->form();

        // Same session, same token: only the profile of the administrator changes.
        $this->loginAs($this->factory()->restrictedAdmin([AdminResources::ORDER => [AccessManager::VIEW]]));

        $statuses = [];
        $this->client->submit($createStore, ['name' => 'Other', 'street' => '1 rue', 'zip_code' => '69001', 'city' => 'Lyon']);
        $statuses[] = $this->client->getResponse()->getStatusCode();
        $this->client->submit($createSlice, ['min_radius' => '0', 'max_radius' => '10', 'price' => '5']);
        $statuses[] = $this->client->getResponse()->getStatusCode();
        $this->client->submit($deleteStore);
        $statuses[] = $this->client->getResponse()->getStatusCode();

        self::assertSame([403, 403, 403], $statuses);

        self::assertSame(1, GeolocatedDeliveryStoreQuery::create()->count());
        self::assertSame(0, GeolocatedDeliveryRadiusQuery::create()->count());
        self::assertSame([], $this->requestedUrls, 'Nothing is sent to the geocoder for a refused request.');
    }

    private function geocoderAnswers(MockResponse ...$responses): void
    {
        $responses = array_values($responses);
        $client = new MockHttpClient(function (string $method, string $url) use (&$responses): MockResponse {
            $this->requestedUrls[] = $url;

            return array_shift($responses) ?? new MockResponse('', ['http_code' => 500]);
        });

        static::getContainer()->set(Geocoder::class, new Geocoder($client, new ArrayAdapter(), new NullLogger()));
    }

    private function store(string $name): GeolocatedDeliveryStore
    {
        $store = (new GeolocatedDeliveryStore())
            ->setName($name)
            ->setStreet('1 place Bellecour')
            ->setZipCode('69002')
            ->setCity('Lyon')
            ->setLatitude('45.7640')
            ->setLongitude('4.8357');
        $store->save();

        return $store;
    }

    private function factory(): FixtureFactory
    {
        return new FixtureFactory($this->getPropelConnection());
    }

    private function loginAs(Admin $admin): void
    {
        $admin->eraseCredentials();
        $this->injector?->setAdmin($admin);
    }
}
