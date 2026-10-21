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

use GeolocatedDelivery\Model\Base\GeolocatedDeliveryStoreQuery as BaseGeolocatedDeliveryStoreQuery;
use Propel\Runtime\ActiveQuery\Criteria;

class GeolocatedDeliveryStoreQuery extends BaseGeolocatedDeliveryStoreQuery
{
    /**
     * The stores whose coordinates are known. A store the Thelia 2 version could not locate
     * has none, and no distance can be measured from it.
     */
    public function located(): self
    {
        // "<> ''" is not true for NULL either: one comparison per column leaves both out.
        return $this
            ->filterByLatitude('', Criteria::NOT_EQUAL)
            ->filterByLongitude('', Criteria::NOT_EQUAL);
    }
}
