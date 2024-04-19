<?php

namespace GeolocatedDelivery\EventListeners;

use GeolocatedDelivery\GeolocatedDelivery;
use GeolocatedDelivery\Model\GeolocatedDeliveryRadiusQuery;
use Symfony\Component\EventDispatcher\GenericEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Thelia\Model\AreaDeliveryModuleQuery;
use Thelia\Model\ModuleConfigQuery;

class ConfigListener implements EventSubscriberInterface
{
    public static function getSubscribedEvents()
    {
        return [
            'module.config' => ['onModuleConfig', 128],
        ];
    }

    public function onModuleConfig(GenericEvent $event)
    {

        $subject = $event->getSubject();

        if ($subject !== "HealthStatus") {
            throw new \RuntimeException('Event subject does not match expected value');
        }

        $shippingZoneConfig = AreaDeliveryModuleQuery::create()
            ->filterByDeliveryModuleId(GeolocatedDelivery::getModuleId())
            ->find();

        $configModule = ModuleConfigQuery::create()
            ->filterByModuleId(GeolocatedDelivery::getModuleId())
            ->find();

        $deliveryRadius = GeolocatedDeliveryRadiusQuery::create()
            ->find();

        $moduleConfig['module'] = GeolocatedDelivery::getModuleCode();
        $configsCompleted = true;

        if ($shippingZoneConfig->count() === 0) {
            $configsCompleted = false;
        }

        if ($deliveryRadius->count() === 0) {
            $configsCompleted = false;
        }


        foreach ($configModule as $config) {
            $moduleConfig[$config->getName()] = $config->getValue();
            if ($config->getValue() === null) {
                $configsCompleted = false;
            }
        }

        $moduleConfig['completed'] = $configsCompleted;

        $event->setArgument('geolocated.delivery.module.config', $moduleConfig);
    }

}