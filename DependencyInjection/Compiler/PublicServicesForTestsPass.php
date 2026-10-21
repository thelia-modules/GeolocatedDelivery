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

namespace GeolocatedDelivery\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Keeps the module services reachable, and replaceable, from the test container.
 *
 * A private service nothing else references is removed, and one used once is inlined into
 * its consumer: the tests could then neither read it nor put a geocoder answering without
 * the network in its place. The core does the same for its own services in
 * Thelia\Core\DependencyInjection\Compiler\TestPublicServicesPass, which only covers ids
 * starting with "Thelia\". Nothing changes for dev and prod.
 */
final readonly class PublicServicesForTestsPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if ('test' !== $container->getParameter('kernel.environment')) {
            return;
        }

        foreach ($container->getDefinitions() as $id => $definition) {
            if (!str_starts_with($id, 'GeolocatedDelivery\\')) {
                continue;
            }

            if ($definition->isPublic() || $definition->isAbstract() || $definition->hasErrors()) {
                continue;
            }

            $definition->setPublic(true);
        }
    }
}
