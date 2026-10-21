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

namespace GeolocatedDelivery\Hook;

use GeolocatedDelivery\GeolocatedDelivery;
use GeolocatedDelivery\Model\GeolocatedDeliveryRadiusQuery;
use GeolocatedDelivery\Model\GeolocatedDeliveryStoreQuery;
use Propel\Runtime\ActiveQuery\Criteria;
use Thelia\Core\Event\Hook\HookRenderEvent;
use Thelia\Core\Hook\BaseHook;
use Thelia\Model\AreaQuery;
use Thelia\Model\CurrencyQuery;

/**
 * The configuration page of the module: the stores, the radius slices, and a warning while
 * the module serves no shipping zone, since the core never asks a carrier outside its zones.
 */
class HookManager extends BaseHook
{
    /**
     * @return array<string, list<array{type: string, method: string}>>
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'module.configuration' => [
                ['type' => 'back', 'method' => 'onModuleConfiguration'],
            ],
        ];
    }

    public function onModuleConfiguration(HookRenderEvent $event): void
    {
        $moduleId = GeolocatedDelivery::getModuleId();

        $stores = [];
        foreach (GeolocatedDeliveryStoreQuery::create()->orderByName(Criteria::ASC)->find() as $store) {
            $stores[] = [
                'id' => $store->getId(),
                'name' => $store->getName(),
                'street' => $store->getStreet(),
                'zip_code' => $store->getZipCode(),
                'city' => $store->getCity(),
                'located' => '' !== (string) $store->getLatitude() && '' !== (string) $store->getLongitude(),
            ];
        }

        $slices = [];
        foreach (GeolocatedDeliveryRadiusQuery::create()->inMatchingOrder()->find() as $slice) {
            $slices[] = [
                'id' => $slice->getId(),
                'min_radius' => $slice->getMinRadius(),
                'max_radius' => $slice->getMaxRadius(),
                'price' => $slice->getPrice(),
            ];
        }

        $zoneCount = AreaQuery::create()
            ->useAreaDeliveryModuleQuery()
                ->filterByDeliveryModuleId($moduleId)
            ->endUse()
            ->count();

        $event->add($this->render('GeolocatedDelivery/module-configuration.html.twig', [
            'module_id' => $moduleId,
            'stores' => $stores,
            'slices' => $slices,
            'has_zone' => $zoneCount > 0,
            'currency_symbol' => CurrencyQuery::create()->findOneByByDefault(1)?->getSymbol() ?? '',
        ]));
    }
}
