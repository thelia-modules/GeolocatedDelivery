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

namespace GeolocatedDelivery\EventListeners;

use GeolocatedDelivery\GeolocatedDelivery;
use GeolocatedDelivery\Service\PostageBuilder;
use GeolocatedDelivery\Service\RadiusQuote;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Thelia\Core\Event\Delivery\DeliveryPostageEvent;
use Thelia\Core\Event\TheliaEvents;

/**
 * Decides whether the local delivery reaches the cart's address, and at what price.
 *
 * The core asks the module first (Thelia\Action\Delivery, priority 128), and a delivery
 * module only receives the country there. This listener runs after it, on the same event,
 * and replaces its answer with the quote of the address: in reach, the price of the slice;
 * out of reach or not locatable, the module is not valid. It never accepts what the core
 * already refused, and never throws, so the other carriers are offered whatever happens here.
 */
final readonly class PostageListener implements EventSubscriberInterface
{
    public const PRIORITY = 64;

    public function __construct(
        private RadiusQuote $radiusQuote,
        private PostageBuilder $postageBuilder,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            TheliaEvents::MODULE_DELIVERY_GET_POSTAGE => ['quote', self::PRIORITY],
        ];
    }

    public function quote(DeliveryPostageEvent $event): void
    {
        $module = $event->getModule();

        if (!$module instanceof GeolocatedDelivery || !$event->isValidModule()) {
            return;
        }

        $country = $event->getCountry();
        $price = null === $country ? null : $this->radiusQuote->priceFor($event->getCart(), $event->getAddress(), $country);

        if (null === $price) {
            $event->setValidModule(false);

            return;
        }

        $event
            ->setPostage($this->postageBuilder->build($price, $country))
            ->setDeliveryMode('delivery');
    }
}
