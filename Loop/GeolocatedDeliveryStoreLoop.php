<?php
/*************************************************************************************/
/*      This file is part of the Thelia package.                                     */
/*                                                                                   */
/*      Copyright (c) OpenStudio                                                     */
/*      email : dev@thelia.net                                                       */
/*      web : http://www.thelia.net                                                  */
/*                                                                                   */
/*      For the full copyright and license information, please view the LICENSE.txt  */
/*      file that was distributed with this source code.                             */
/*************************************************************************************/


namespace GeolocatedDelivery\Loop;

use GeolocatedDelivery\Model\GeolocatedDeliveryStore;
use GeolocatedDelivery\Model\GeolocatedDeliveryStoreQuery;
use Propel\Runtime\ActiveQuery\Criteria;
use Thelia\Core\Template\Element\BaseLoop;
use Thelia\Core\Template\Element\LoopResult;
use Thelia\Core\Template\Element\LoopResultRow;
use Thelia\Core\Template\Element\PropelSearchLoopInterface;
use Thelia\Core\Template\Loop\Argument\Argument;
use Thelia\Core\Template\Loop\Argument\ArgumentCollection;
use Thelia\Type\EnumListType;
use Thelia\Type\TypeCollection;

/**
 * Class GeolocatedDeliverySlideLoop
 * @package GeolocatedDelivery\Loop
 * @author thomas da silva mendonca <tdasilva@openstudio.fr>
 */
class GeolocatedDeliveryStoreLoop extends BaseLoop implements PropelSearchLoopInterface
{
    protected $timestampable = false;

    protected $versionable = false;

    /**
     * Definition of loop arguments
     *
     * example :
     *
     * public function getArgDefinitions()
     * {
     *  return new ArgumentCollection(
     *
     *       Argument::createIntListTypeArgument('id'),
     *           new Argument(
     *           'ref',
     *           new TypeCollection(
     *               new Type\AlphaNumStringListType()
     *           )
     *       ),
     *       Argument::createIntListTypeArgument('category'),
     *       Argument::createBooleanTypeArgument('new'),
     *       ...
     *   );
     * }
     *
     * @return \Thelia\Core\Template\Loop\Argument\ArgumentCollection
     */
    protected function getArgDefinitions()
    {
        return new ArgumentCollection(
            Argument::createIntListTypeArgument('id'),
            new Argument(
                'order',
                new TypeCollection(
                    new EnumListType(
                        [
                            'id',
                            'id_reverse',
                            'min_radius',
                            'min_radius_reverse',
                            'max_radius',
                            'max_radius_reverse',
                            'price',
                            'price_reverse',
                        ]
                    )
                ),
                'id'
            )
        );
    }

    /**
     * this method returns a Propel ModelCriteria
     *
     * @return \Propel\Runtime\ActiveQuery\ModelCriteria
     */
    public function buildModelCriteria()
    {
        $query = GeolocatedDeliveryStoreQuery::create();

        $id = $this->getArgValue('id');
        if (null !== $id) {
            $query->filterById($id, Criteria::IN);
        }

        $orders = $this->getArgValue('order');

        foreach ($orders as $order) {
            switch ($order) {
                case "id":
                    $query->orderById(Criteria::ASC);
                    break;
                case "id_reverse":
                    $query->orderById(Criteria::DESC);
                    break;
                case "street":
                    $query->orderByStreet(Criteria::ASC);
                    break;
                case "street_reverse":
                    $query->orderByStreet(Criteria::DESC);
                    break;
                case "zip_code":
                    $query->orderByZipCode(Criteria::ASC);
                    break;
                case "zip_code_reverse":
                    $query->orderByZipCode(Criteria::DESC);
                    break;
                case "city":
                    $query->orderByCity(Criteria::ASC);
                    break;
                case "city_reverse":
                    $query->orderByCity(Criteria::DESC);
                    break;
                case "name":
                    $query->orderByName(Criteria::ASC);
                    break;
                case "name_reverse":
                    $query->orderByName(Criteria::DESC);
                    break;
            }
        }

        return $query;
    }

    /**
     * @param LoopResult $loopResult
     *
     * @return LoopResult
     */
    public function parseResults(LoopResult $loopResult)
    {
        /** @var GeolocatedDeliveryStore $store */
        foreach ($loopResult->getResultDataCollection() as $store) {

            $loopResultRow = new LoopResultRow($store);

            $loopResultRow
                ->set("ID", $store->getId())
                ->set("STREET", $store->getStreet())
                ->set("ZIP_CODE", $store->getZipCode())
                ->set("CITY", $store->getCity())
                ->set("NAME", $store->getName());

            $this->addOutputFields($loopResultRow, $store);

            $loopResult->addRow($loopResultRow);
        }

        return $loopResult;
    }
}
