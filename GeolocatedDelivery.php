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

namespace GeolocatedDelivery;

use GeolocatedDelivery\DependencyInjection\Compiler\PublicServicesForTestsPass;
use Propel\Runtime\Connection\ConnectionInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\Loader\Configurator\ServicesConfigurator;
use Symfony\Component\Finder\Finder;
use Thelia\Core\Install\Database;
use Thelia\Model\Country;
use Thelia\Model\OrderPostage;
use Thelia\Model\State;
use Thelia\Module\AbstractDeliveryModuleWithState;

/**
 * Local delivery priced by the distance between the delivery address and the nearest store.
 *
 * The module class only says the carrier exists: the price, and whether the address is in
 * reach at all, are decided by EventListeners\PostageListener once the core has asked this
 * class, because the core hands a delivery module the country alone.
 */
class GeolocatedDelivery extends AbstractDeliveryModuleWithState
{
    public const DOMAIN_NAME = 'geolocateddelivery';
    public const BACK_OFFICE_DOMAIN = 'geolocateddelivery.bo.default-twig';

    /**
     * The tables are created with IF NOT EXISTS: activating the module again, or on a database
     * the Thelia 2 version left behind, keeps the stores and the radius slices.
     */
    public function postActivation(?ConnectionInterface $con = null): void
    {
        (new Database($con))->insertSql(null, [__DIR__.'/Config/thelia.sql']);
    }

    public function update($currentVersion, $newVersion, ?ConnectionInterface $con = null): void
    {
        $database = new Database($con);

        $finder = Finder::create()
            ->name('*.sql')
            ->depth(0)
            ->sortByName()
            ->in(__DIR__.'/Config/update');

        foreach ($finder as $file) {
            if (version_compare((string) $currentVersion, $file->getBasename('.sql'), '<')) {
                $database->insertSql(null, [$file->getPathname()]);
            }
        }
    }

    public function isValidDelivery(Country $country, ?State $state = null): bool
    {
        return 1 === (int) $this->getModuleModel()->getActivate();
    }

    /**
     * Never the price the customer pays: PostageListener replaces it, or turns the module
     * down, for every quote.
     */
    public function getPostage(Country $country, ?State $state = null): OrderPostage|float
    {
        return new OrderPostage(0.0, 0.0);
    }

    public function handleVirtualProductDelivery(): bool
    {
        return false;
    }

    /**
     * @return list<array{0: CompilerPassInterface, 1: string}>
     */
    public static function getCompilers(): array
    {
        return [
            [new PublicServicesForTestsPass(), PassConfig::TYPE_BEFORE_REMOVING],
        ];
    }

    public static function configureServices(ServicesConfigurator $servicesConfigurator): void
    {
        $servicesConfigurator->load(self::getModuleCode().'\\', __DIR__)
            ->exclude([
                __DIR__.'/I18n/*',
                __DIR__.'/Tests/*',
                __DIR__.'/Model/*',
                __DIR__.'/DependencyInjection/*',
                __DIR__.'/Service/Coordinates.php',
                __DIR__.'/Service/Destination.php',
                __DIR__.'/GeolocatedDelivery.php',
            ])
            ->autowire(true)
            ->autoconfigure(true);
    }
}
