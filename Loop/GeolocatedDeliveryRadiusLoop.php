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

use GeolocatedDelivery\Model\Base\GeolocatedDeliveryRadiusQuery;
use GeolocatedDelivery\Model\GeolocatedDeliveryRadius;
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
class GeolocatedDeliveryRadiusLoop extends BaseLoop implements PropelSearchLoopInterface
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
        $query = GeolocatedDeliveryRadiusQuery::create();

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
                case "min_radius":
                    $query->orderByMinRadius(Criteria::ASC);
                    break;
                case "min_radius_reverse":
                    $query->orderByMinRadius(Criteria::DESC);
                    break;
                case "max_radius":
                    $query->orderByMaxRadius(Criteria::ASC);
                    break;
                case "max_radius_reverse":
                    $query->orderByMaxRadius(Criteria::DESC);
                    break;
                case "price":
                    $query->orderByPrice(Criteria::ASC);
                    break;
                case "price_reverse":
                    $query->orderByPrice(Criteria::DESC);
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
        /** @var GeolocatedDeliveryRadius $radius */
        foreach ($loopResult->getResultDataCollection() as $radius) {

            $loopResultRow = new LoopResultRow($radius);

            $loopResultRow
                ->set("ID", $radius->getId())
                ->set("MIN_RADIUS", $radius->getMinRadius())
                ->set("MAX_RADIUS", $radius->getMaxRadius())
                ->set("PRICE", $radius->getPrice());

            $this->addOutputFields($loopResultRow, $radius);

            $loopResult->addRow($loopResultRow);
        }

        return $loopResult;
    }
}
