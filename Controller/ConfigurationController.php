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

namespace GeolocatedDelivery\Controller;

use GeolocatedDelivery\GeolocatedDelivery;
use GeolocatedDelivery\Model\GeolocatedDeliveryRadius;
use GeolocatedDelivery\Model\GeolocatedDeliveryRadiusQuery;
use GeolocatedDelivery\Model\GeolocatedDeliveryStore;
use GeolocatedDelivery\Model\GeolocatedDeliveryStoreQuery;
use GeolocatedDelivery\Service\Geocoder;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Thelia\Controller\Admin\BaseAdminController;
use Thelia\Core\HttpFoundation\Session\Session;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Core\Translation\Translator;
use Thelia\Tools\TokenProvider;
use Thelia\Tools\URL;

/**
 * The forms of the configuration page: stores and radius slices are added and deleted one at
 * a time, by POST with the session token, and the page is shown again with what happened.
 */
#[Route('/admin/module/GeolocatedDelivery', name: 'geolocateddelivery.')]
class ConfigurationController extends BaseAdminController
{
    private const MAXIMUM_TEXT_LENGTH = 255;

    /** Above any delivery price: a typo of a few zeros is refused instead of charged. */
    public const MAXIMUM_PRICE = 100_000;

    #[Route('/stores', name: 'store.create', methods: ['POST'])]
    public function createStore(Request $request, Session $session, TokenProvider $tokenProvider, Geocoder $geocoder): Response
    {
        if (null !== $response = $this->checkAuth([AdminResources::MODULE], [GeolocatedDelivery::getModuleCode()], AccessManager::UPDATE)) {
            return $response;
        }

        $tokenProvider->checkRequestToken($request);

        $name = trim((string) $request->request->get('name'));
        $street = trim((string) $request->request->get('street'));
        $zipCode = trim((string) $request->request->get('zip_code'));
        $city = trim((string) $request->request->get('city'));

        foreach ([$name, $street, $zipCode, $city] as $value) {
            if ('' === $value || mb_strlen($value) > self::MAXIMUM_TEXT_LENGTH) {
                return $this->backToTheConfiguration($session, 'danger', 'Fill in the name, the street, the postal code and the city of the store.');
            }
        }

        $coordinates = $geocoder->locateStore($street, $zipCode, $city);

        if (null === $coordinates) {
            return $this->backToTheConfiguration($session, 'danger', 'The address of the store could not be located: check it, or try again later. The store was not added.');
        }

        (new GeolocatedDeliveryStore())
            ->setName($name)
            ->setStreet($street)
            ->setZipCode($zipCode)
            ->setCity($city)
            ->setLatitude((string) $coordinates->latitude)
            ->setLongitude((string) $coordinates->longitude)
            ->save();

        return $this->backToTheConfiguration($session, 'success', 'The store was added.');
    }

    #[Route('/stores/{id}/delete', name: 'store.delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function deleteStore(int $id, Request $request, Session $session, TokenProvider $tokenProvider): Response
    {
        if (null !== $response = $this->checkAuth([AdminResources::MODULE], [GeolocatedDelivery::getModuleCode()], AccessManager::UPDATE)) {
            return $response;
        }

        $tokenProvider->checkRequestToken($request);

        $store = GeolocatedDeliveryStoreQuery::create()->findPk($id);

        if (null === $store) {
            return $this->backToTheConfiguration($session, 'danger', 'The store does not exist anymore.');
        }

        $store->delete();

        return $this->backToTheConfiguration($session, 'success', 'The store was deleted.');
    }

    #[Route('/radii', name: 'radius.create', methods: ['POST'])]
    public function createRadius(Request $request, Session $session, TokenProvider $tokenProvider): Response
    {
        if (null !== $response = $this->checkAuth([AdminResources::MODULE], [GeolocatedDelivery::getModuleCode()], AccessManager::UPDATE)) {
            return $response;
        }

        $tokenProvider->checkRequestToken($request);

        $minimum = filter_var(trim((string) $request->request->get('min_radius')), \FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        $maximum = filter_var(trim((string) $request->request->get('max_radius')), \FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        $price = str_replace(',', '.', trim((string) $request->request->get('price')));

        $error = match (true) {
            false === $minimum, false === $maximum => 'The distances are whole numbers of kilometres, zero or more.',
            $maximum <= $minimum => 'The maximum distance must be greater than the minimum distance.',
            !is_numeric($price) => 'The price must be a number.',
            (float) $price < 0 => 'The price cannot be negative.',
            (float) $price > self::MAXIMUM_PRICE => 'The price cannot be above 100000.',
            default => null,
        };

        if (null !== $error) {
            return $this->backToTheConfiguration($session, 'danger', $error);
        }

        (new GeolocatedDeliveryRadius())
            ->setMinRadius($minimum)
            ->setMaxRadius($maximum)
            ->setPrice((float) $price)
            ->save();

        return $this->backToTheConfiguration($session, 'success', 'The radius slice was added.');
    }

    #[Route('/radii/{id}/delete', name: 'radius.delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function deleteRadius(int $id, Request $request, Session $session, TokenProvider $tokenProvider): Response
    {
        if (null !== $response = $this->checkAuth([AdminResources::MODULE], [GeolocatedDelivery::getModuleCode()], AccessManager::UPDATE)) {
            return $response;
        }

        $tokenProvider->checkRequestToken($request);

        $slice = GeolocatedDeliveryRadiusQuery::create()->findPk($id);

        if (null === $slice) {
            return $this->backToTheConfiguration($session, 'danger', 'The radius slice does not exist anymore.');
        }

        $slice->delete();

        return $this->backToTheConfiguration($session, 'success', 'The radius slice was deleted.');
    }

    private function backToTheConfiguration(Session $session, string $type, string $message): RedirectResponse
    {
        $session->getFlashBag()->add($type, Translator::getInstance()->trans($message, [], GeolocatedDelivery::DOMAIN_NAME));

        return new RedirectResponse(URL::getInstance()->absoluteUrl('/admin/module/'.GeolocatedDelivery::getModuleCode()));
    }
}
