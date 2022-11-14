<?php

namespace GeolocatedDelivery;

use Propel\Runtime\Connection\ConnectionInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ServicesConfigurator;
use Symfony\Component\Finder\Finder;
use Thelia\Core\Translation\Translator;
use Thelia\Install\Database;
use Thelia\Model\AddressQuery;
use Thelia\Model\ConfigQuery;
use Thelia\Model\Country;
use Thelia\Model\LangQuery;
use Thelia\Model\Message;
use Thelia\Model\MessageQuery;
use Thelia\Model\ModuleQuery;
use Thelia\Model\Order;
use Thelia\Model\OrderPostage;
use Thelia\Model\State;
use Thelia\Module\AbstractDeliveryModuleWithState;
use Thelia\Module\BaseModule;
use Thelia\Module\Exception\DeliveryException;
use GeolocatedDelivery\Utils\GeolocManager;

class GeolocatedDelivery extends AbstractDeliveryModuleWithState
{
    const MESSAGE_DOMAIN = "geolocated_delivery";

    const CONFIG_TRACKING_URL = 'geolocated_delivery_tracking_url';
    const CONFIG_PICKING_METHOD = 'geolocated_delivery_picking_method';
    const CONFIG_TAX_RULE_ID = 'geolocated_delivery_taxe_rule';

    const DEFAULT_TRACKING_URL = '%ID%';
    const DEFAULT_PICKING_METHOD = 0;

    const METHOD_PRICE = 5;
    const METHOD_MAX_RADIUS = 4;
    const METHOD_MIN_RADIUS = 3;
    const METHOD_WEIGHT = 2;

    /** @var Translator */
    protected $translator;

    public static function getConfig()
{
    $config = [
        'url' => (
        self::getConfigValue('url')
        ),
        'method' => (
        self::getConfigValue('method')
        ),
        'tax' => (
        self::getConfigValue('tax')
        ),
    ];

    return $config;
}

    public function postActivation(ConnectionInterface $con = null): void
{
    if (!$this->getConfigValue('is_initialized', false)) {
        $database = new Database($con);

        $database->insertSql(null, array(__DIR__ . '/Config/TheliaMain.sql'));

        $this->setConfigValue('is_initialized', true);
    }

    // register config variables
    if (null === ConfigQuery::read(self::CONFIG_TRACKING_URL, null)) {
        ConfigQuery::write(self::CONFIG_TRACKING_URL, self::DEFAULT_TRACKING_URL);
    }

    if (null === ConfigQuery::read(self::CONFIG_PICKING_METHOD, null)) {
        ConfigQuery::write(self::CONFIG_PICKING_METHOD, self::DEFAULT_PICKING_METHOD);
    }

    // create new message
    if (null === MessageQuery::create()->findOneByName('mail_geolocated_delivery')) {

        $message = new Message();
        $message
            ->setName('mail_geolocated_delivery')
            ->setHtmlTemplateFileName('geolocated-delivery-shipping.html')
            ->setHtmlLayoutFileName('')
            ->setTextTemplateFileName('geolocated-delivery-shipping.txt')
            ->setTextLayoutFileName('')
            ->setSecured(0);

        $languages = LangQuery::create()->find();

        foreach ($languages as $language) {
            $locale = $language->getLocale();

            $message->setLocale($locale);

            $message->setTitle(
                $this->trans('GeolocatedDelivery shipping message', [], $locale)
            );
            $message->setSubject(
                $this->trans('Your order {$order_ref} has been shipped', [], $locale)
            );
        }

        $message->save();
    }
}

    /**
     * This method is called by the Delivery  loop, to check if the current module has to be displayed to the customer.
     * Override it to implements your delivery rules/
     *
     * If you return true, the delivery method will de displayed to the customer
     * If you return false, the delivery method will not be displayed
     *
     * @param Country $country the country to deliver to.
     * @param State $state the state to deliver to.
     *
     * @return boolean
     */
    public function isValidDelivery(Country $country, State $state = null)
{
    return 1 === ModuleQuery::create()->findOneByCode('GeolocatedDelivery')?->getActivate();
}

    /**
     * Calculate and return delivery price in the shop's default currency
     *
     * @param Country $country the country to deliver to.
     * @param State $state the state to deliver to.
     *
     * @return OrderPostage             the delivery price
     * @throws DeliveryException if the postage price cannot be calculated.
     */
    public function getPostage(Country $country, State $state = null)
{
    try {
        /** @var Order $order */
        $order = $_SESSION['_sf2_attributes']['thelia.order'];
        $address = AddressQuery::create()->findOneById($order->getChoosenDeliveryAddress());
        $deliveryModule = $order->getModuleRelatedByDeliveryModuleId();
        if ($address && $deliveryModule?->getCode() === "GeolocatedDelivery"){
            $price = GeolocManager::getRadius($address);
            return new OrderPostage($price,0.0,"no tax");
        }
        return new OrderPostage(0.0,0.0,"no taxes");
    } catch (\Exception $e) {
        throw new \Exception($e->getMessage());
    }
}

    /**
     *
     * This method return true if your delivery manages virtual product delivery.
     *
     * @return bool
     */
    public function handleVirtualProductDelivery()
{
    return false;
}

    protected function trans($id, array $parameters = [], $locale = null)
{
    if (null === $this->translator) {
        $this->translator = Translator::getInstance();
    }

    return $this->translator->trans($id, $parameters, GeolocatedDelivery::MESSAGE_DOMAIN, $locale);
}

    public function getDeliveryMode()
{
    return 'delivery';
}

    /*
     * You may now override BaseModuleInterface methods, such as:
     * install, destroy, preActivation, postActivation, preDeactivation, postDeactivation
     *
     * Have fun !
     */

    /**
     * Defines how services are loaded in your modules
     *
     * @param ServicesConfigurator $servicesConfigurator
     */
    public static function configureServices(ServicesConfigurator $servicesConfigurator): void
    {
        $servicesConfigurator->load(self::getModuleCode().'\\', __DIR__)
            ->exclude([THELIA_MODULE_DIR . ucfirst(self::getModuleCode()). "/I18n/*"])
            ->autowire(true)
            ->autoconfigure(true);
    }

    /**
     * Execute sql files in Config/update/ folder named with module version (ex: 1.0.1.sql).
     *
     * @param $currentVersion
     * @param $newVersion
     * @param ConnectionInterface $con
     */
    public function update($currentVersion, $newVersion, ConnectionInterface $con = null): void
    {
        $finder = Finder::create()
            ->name('*.sql')
            ->depth(0)
            ->sortByName()
            ->in(__DIR__.DS.'Config'.DS.'update');

        $database = new Database($con);

        /** @var \SplFileInfo $file */
        foreach ($finder as $file) {
            if (version_compare($currentVersion, $file->getBasename('.sql'), '<')) {
                $database->insertSql(null, [$file->getPathname()]);
            }
        }
    }
}
