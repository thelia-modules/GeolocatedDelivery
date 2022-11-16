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


namespace GeolocatedDelivery\Hook;

use JetBrains\PhpStorm\ArrayShape;
use GeolocatedDelivery\GeolocatedDelivery;
use Thelia\Core\Event\Hook\HookRenderEvent;
use Thelia\Core\Hook\BaseHook;

/**
 * Class HookManager
 * @package GeolocatedDelivery\Hook
 * @author thomas da silva mendonca <tdasilva@openstudio.fr>
 */
class HookManager extends BaseHook
{
    #[ArrayShape(["module.configuration" => "\string[][]", "module.config-js" => "\string[][]"])]
    public static function getSubscribedHooks(): array
    {
        return [
            "module.configuration" => [
                [
                    "type" => "back",
                    "method" => "onModuleConfiguration"
                ]
            ],
            "module.config-js" => [
                [
                    "type" => "back",
                    "method" => "onModuleConfigJs"
                ]
            ]
        ];
    }

    public function onAccountOrderAfterProducts(HookRenderEvent $event)
    {
        $orderId = $event->getArgument('order');

        if (null !== $orderId) {
            $render = $this->render(
                'account-order-after-products.html',
                [
                    "order_id" => $orderId
                ]
            );
            $event->add($render);
        }

        $event->stopPropagation();
    }

    public function onModuleConfiguration(HookRenderEvent $event)
    {
        $moduleId = $this->getModule()->getCode();
        $config = GeolocatedDelivery::getConfig();

        $event->add(
            $this->render(
                "configuration.html",
                [
                    'module_id' => $moduleId,
                    'method' => $config['method']
                ]
            )
        );
    }

    public function onModuleConfigJs(HookRenderEvent $event)
    {
        $event->add(
            $this->render("module-config-js.html")
        );
    }
}
