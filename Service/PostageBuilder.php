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

namespace GeolocatedDelivery\Service;

use GeolocatedDelivery\GeolocatedDelivery;
use Symfony\Component\HttpFoundation\RequestStack;
use Thelia\Core\HttpFoundation\Session\Session;
use Thelia\Model\Country;
use Thelia\Model\Lang;
use Thelia\Model\OrderPostage;

/**
 * Turns the price of a slice into the postage of the order.
 *
 * The price is taken before tax and goes through the core's postage tax rule
 * (Thelia\Module\OrderPostageBuilderTrait): the rule the back office sets for this module
 * on the tax rules page, else the shop-wide delivery tax rule, else no tax, which charges
 * the price as entered, as the Thelia 2 version did.
 */
final readonly class PostageBuilder
{
    public function __construct(
        private RequestStack $requestStack,
    ) {
    }

    public function build(float $price, Country $country): OrderPostage
    {
        return (new GeolocatedDelivery())->buildOrderPostage($price, $country, $this->locale());
    }

    /**
     * Only names the tax rule on the postage.
     */
    public function locale(): string
    {
        $request = $this->requestStack->getCurrentRequest();
        $session = null !== $request && $request->hasSession() ? $request->getSession() : null;

        if ($session instanceof Session && null !== $locale = $session->getLang()?->getLocale()) {
            return $locale;
        }

        return Lang::getDefaultLanguage()->getLocale();
    }
}
