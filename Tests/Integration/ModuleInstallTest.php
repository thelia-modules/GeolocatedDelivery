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

use GeolocatedDelivery\GeolocatedDelivery;
use GeolocatedDelivery\Model\GeolocatedDeliveryRadius;
use GeolocatedDelivery\Model\GeolocatedDeliveryRadiusQuery;
use GeolocatedDelivery\Model\GeolocatedDeliveryStore;
use GeolocatedDelivery\Model\GeolocatedDeliveryStoreQuery;
use Thelia\Test\IntegrationTestCase;

/**
 * Activating the module again, or updating it from the Thelia 2 version, keeps the stores
 * and the slices the shop has. CREATE TABLE commits on its own, so the tests run outside a
 * transaction and remove their rows themselves.
 */
final class ModuleInstallTest extends IntegrationTestCase
{
    protected bool $useTransaction = false;

    private ?int $storeId = null;
    private ?int $sliceId = null;

    protected function tearDown(): void
    {
        if (null !== $this->storeId) {
            GeolocatedDeliveryStoreQuery::create()->filterById($this->storeId)->delete();
        }

        if (null !== $this->sliceId) {
            GeolocatedDeliveryRadiusQuery::create()->filterById($this->sliceId)->delete();
        }

        parent::tearDown();
    }

    public function testActivatingTheModuleAgainKeepsTheStoresAndTheSlices(): void
    {
        $this->storeAndSlice();

        (new GeolocatedDelivery())->postActivation($this->getPropelConnection());

        self::assertNotNull(GeolocatedDeliveryStoreQuery::create()->findPk($this->storeId));
        self::assertNotNull(GeolocatedDeliveryRadiusQuery::create()->findPk($this->sliceId));
    }

    public function testUpdatingFromTheThelia2VersionKeepsTheStoresAndTheSlices(): void
    {
        $this->storeAndSlice();

        (new GeolocatedDelivery())->update('1.0.1', '2.0.0', $this->getPropelConnection());

        self::assertNotNull(GeolocatedDeliveryStoreQuery::create()->findPk($this->storeId));
        self::assertNotNull(GeolocatedDeliveryRadiusQuery::create()->findPk($this->sliceId));
    }

    public function testTheUpdateScriptCreatesTheTablesTheInstallScriptCreates(): void
    {
        self::assertSame(
            file_get_contents(__DIR__.'/../../Config/thelia.sql'),
            file_get_contents(__DIR__.'/../../Config/update/2.0.0.sql'),
            'A shop updated from version 1 gets the tables a new shop gets.',
        );
    }

    private function storeAndSlice(): void
    {
        $store = (new GeolocatedDeliveryStore())
            ->setName('Kept')
            ->setStreet('1 place Bellecour')
            ->setZipCode('69002')
            ->setCity('Lyon')
            ->setLatitude('45.7640')
            ->setLongitude('4.8357');
        $store->save();
        $this->storeId = $store->getId();

        $slice = (new GeolocatedDeliveryRadius())->setMinRadius(0)->setMaxRadius(10)->setPrice(5.0);
        $slice->save();
        $this->sliceId = $slice->getId();
    }
}
