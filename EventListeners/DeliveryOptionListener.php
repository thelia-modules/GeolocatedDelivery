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
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Thelia\Api\Bridge\Propel\Event\DeliveryModuleOptionEvent;
use Thelia\Api\Resource\DeliveryModuleOption;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Model\ConfigQuery;
use Thelia\Model\Lang;
use Thelia\Model\Module;

/**
 * The option the checkout shows for the local delivery. A delivery module without an option
 * is not listed by the Flexy checkout, even when it is valid; an address out of reach gets
 * no option, so the carrier does not appear. Never throws: a database or a cache failing
 * leaves the option out and logs the class of the failure.
 */
final readonly class DeliveryOptionListener implements EventSubscriberInterface
{
    /** Where CustomDelivery adds its own option. */
    public const PRIORITY = 129;

    public function __construct(
        private RadiusQuote $radiusQuote,
        private PostageBuilder $postageBuilder,
        private LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            TheliaEvents::MODULE_DELIVERY_GET_OPTIONS => ['addOption', self::PRIORITY],
        ];
    }

    public function addOption(DeliveryModuleOptionEvent $event): void
    {
        try {
            $this->addTheOptionOfTheAddress($event);
        } catch (\Throwable $exception) {
            $this->logger->error('GeolocatedDelivery: the delivery option could not be built, the local delivery is not offered.', [
                'failure' => $exception::class,
            ]);
        }
    }

    private function addTheOptionOfTheAddress(DeliveryModuleOptionEvent $event): void
    {
        $module = $event->getModule();

        if (GeolocatedDelivery::getModuleCode() !== $module->getCode() || 1 !== (int) $module->getActivate()) {
            return;
        }

        $country = $event->getCountry();
        $price = null === $country ? null : $this->radiusQuote->priceFor($event->getCart(), $event->getAddress(), $country);

        if (null === $price) {
            return;
        }

        $postage = $this->postageBuilder->build($price, $country);
        $amount = (float) $postage->getAmount();
        $tax = (float) $postage->getAmountTax();

        $event->appendDeliveryModuleOptions(
            (new DeliveryModuleOption())
                ->setCode(GeolocatedDelivery::getModuleCode())
                ->setValid(true)
                ->setTitle($this->titleOf($module, $this->postageBuilder->locale()))
                ->setDescription('')
                ->setImage('')
                ->setPostage($amount)
                ->setPostageTax($tax)
                ->setPostageUntaxed($amount - $tax),
        );
    }

    /**
     * The option title is a string, and a module has no title in a locale it was never
     * translated into: the default language then, as the back office setting says, else the
     * code (same fallback as CustomDelivery\EventListeners\ApiListener).
     */
    private function titleOf(Module $module, string $locale): string
    {
        $title = $module->setLocale($locale)->getTitle();

        if (null === $title || '' === $title) {
            $defaultLocale = Lang::getDefaultLanguage()->getLocale();
            $replaceByDefault = Lang::REPLACE_BY_DEFAULT_LANGUAGE === (int) ConfigQuery::getDefaultLangWhenNoTranslationAvailable();

            $title = $replaceByDefault && $defaultLocale !== $locale ? $module->setLocale($defaultLocale)->getTitle() : null;
        }

        return null === $title || '' === $title ? (string) $module->getCode() : $title;
    }
}
