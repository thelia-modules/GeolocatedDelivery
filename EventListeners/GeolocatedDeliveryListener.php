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


namespace GeolocatedDelivery\EventListeners;

use GeolocatedDelivery\GeolocatedDelivery;
use JetBrains\PhpStorm\ArrayShape;
use OpenApi\Events\DeliveryModuleOptionEvent;
use OpenApi\Events\OpenApiEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Thelia\Core\Event\Order\OrderEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\Template\ParserInterface;
use Thelia\Log\Tlog;
use Thelia\Mailer\MailerFactory;
use Thelia\Model\ConfigQuery;
use Thelia\Model\MessageQuery;
use GeolocatedDelivery\Utils\GeolocManager;

/**
 * Class GeolocatedDeliveryListener
 * @package GeolocatedDelivery\EventListeners
 * @author thomas da silva mendonca <tdasilva@openstudio.fr>
 */
class GeolocatedDeliveryListener implements EventSubscriberInterface
{
    public function __construct(
        protected ParserInterface $parser,
        protected MailerFactory $mailer,
    ) {
    }


    
    public static function getSubscribedEvents(): array
    {
        return [
            TheliaEvents::ORDER_UPDATE_STATUS => ["updateStatus", 128],
            OpenApiEvents::MODULE_DELIVERY_GET_OPTIONS => ["getOptions", 127],
        ];
    }

    public function updateStatus(OrderEvent $event): void
    {
        $order = $event->getOrder();
        $geolocatedDelivery = new GeolocatedDelivery();

        if ($order->isSent() && $order->getDeliveryModuleId() == $geolocatedDelivery->getModuleModel()->getId()) {
            $contactEmail = ConfigQuery::getStoreEmail();

            if ($contactEmail) {
                $message = MessageQuery::create()
                    ->filterByName('mail_geolocated_delivery')
                    ->findOne();

                if (false === $message) {
                    throw new \Exception("Failed to load message 'mail_geolocated_delivery'.");
                }

                $order = $event->getOrder();
                $customer = $order->getCustomer();

                $this->parser->assign('customer_id', $customer->getId());
                $this->parser->assign('order_id', $order->getId());
                $this->parser->assign('order_ref', $order->getRef());
                $this->parser->assign('order_date', $order?->getCreatedAt()??new \DateTime());
                $this->parser->assign('update_date', $order?->getUpdatedAt()??new \DateTime());

                $package = $order->getDeliveryRef();
                $trackingUrl = null;

                if (!empty($package)) {
                    $config = GeolocatedDelivery::getConfig();
                    $trackingUrl = $config['url'];
                    if (!empty($trackingUrl)) {
                        $trackingUrl = str_replace('%ID%', $package, $trackingUrl);
                    }
                }
                $this->parser->assign('package', $package);
                $this->parser->assign('tracking_url', $trackingUrl);

                $message
                    ->setLocale($order->getLang()->getLocale());

                $email = $this->mailer->createEmailMessage(
                    'mail_geolocated_delivery',
                    [ConfigQuery::getStoreEmail() => ConfigQuery::getStoreName()],
                    [$customer->getEmail() => $customer->getFirstname() . " " . $customer->getLastname()],
                    [
                        'order_id' => $event->getOrder()->getId(),
                        'order_ref' => $event->getOrder()->getRef(),
                    ]
                );

                $this->mailer->send($email);

                Tlog::getInstance()->debug(
                    "Geolocated Delivery shipping message sent to customer " . $customer->getEmail()
                );
            } else {
                $customer = $order->getCustomer();
                Tlog::getInstance()->debug(
                    "Geolocated Delivery shipping message no contact email customer_id",
                    $customer->getId()
                );
            }
        }
    }

    public function getOptions(DeliveryModuleOptionEvent $deliveryPostageEvent): void
    {
        try {
            if (!isset($deliveryPostageEvent->getDeliveryModuleOptions()[0])) {
                throw new \Exception('no matching option in GeolocatedDelivery listener');
            }
            $deliveryModuleOption = $deliveryPostageEvent->getDeliveryModuleOptions()[0];
            $address = $deliveryPostageEvent->getAddress();
            $price = GeolocManager::getRadius($address);

            if ($deliveryModuleOption->getCode() === "GeolocatedDelivery") {
                if (null !== $price) {
                    $deliveryModuleOption->setPostage($price);
                }
                $deliveryModuleOption->setValid(null !== $price);
            } elseif (null === $price) {
                $deliveryModuleOption->setValid(true);
            } elseif (0.0 !== $price && $price <= 20.0 && $deliveryModuleOption->getCode() !== "LocalPickup") {
                $deliveryModuleOption->setValid(false);
            }
        } catch (\Exception $e) {
            throw new \Exception($e->getMessage());
        }
    }
}
