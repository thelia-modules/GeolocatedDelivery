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

namespace GeolocatedDelivery\Model;

use GeolocatedDelivery\Model\Base\GeolocatedDeliveryRadiusQuery as BaseGeolocatedDeliveryRadiusQuery;
use Propel\Runtime\ActiveQuery\Criteria;

class GeolocatedDeliveryRadiusQuery extends BaseGeolocatedDeliveryRadiusQuery
{
    /**
     * The slices in the order they are tried: the nearest first, then the one created first,
     * so that two slices sharing a bound always give the same price.
     */
    public function inMatchingOrder(): self
    {
        return $this->orderByMinRadius(Criteria::ASC)->orderById(Criteria::ASC);
    }
}
