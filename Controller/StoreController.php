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


namespace GeolocatedDelivery\Controller;

use GeolocatedDelivery\Form\StoreForm;
use GeolocatedDelivery\GeolocatedDelivery;
use GeolocatedDelivery\Model\GeolocatedDeliveryStore;
use GeolocatedDelivery\Model\GeolocatedDeliveryStoreQuery;
use GeolocatedDelivery\Utils\GeolocManager;
use Symfony\Component\Routing\Annotation\Route;
use Thelia\Core\HttpFoundation\Request;
use Thelia\Core\Template\ParserContext;
use Thelia\Model\Address;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Thelia\Controller\Admin\BaseAdminController;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Core\Translation\Translator;
use Thelia\Tools\URL;

/**
 * Class BackController
 * @package GeolocatedDelivery\Controller
 * @author thomas da silva mendonca <tdasilva@openstudio.fr>
 */
#[Route('/admin/module/geolocateddelivery/store')]
class StoreController extends BaseAdminController
{
    protected $currentRouter = 'router.geolocateddelivery';

    protected $useFallbackTemplate = true;

    #[Route('/save', name: 'geolocateddelivery.admin.store.update')]
    public function saveStoreAction(ParserContext $parserContext)
    {
        $response = $this->checkAuth([AdminResources::MODULE], ['geolocateddelivery'], AccessManager::UPDATE);

        if (null !== $response) {
            return $response;
        }

        $form = $this->createForm(StoreForm::getName());
        $message = "";

        $response = null;

        try {
            $vform = $this->validateForm($form);
            $data = $vform->getData();

            if ($data['id']) {
                $store = GeolocatedDeliveryStoreQuery::create()->findOneById($data['id']);
            } else {
                $store = new GeolocatedDeliveryStore();
            }

            $address = new Address();
            $address->setAddress1($data["street"])
                ->setZipcode($data["zip_code"])
                ->setCity($data["city"]);

            $store->setStreet($data["street"]);
            $store->setCity($data["city"]);
            $store->setZipCode($data["zip_code"]);
            $store->setName($data["name"]);
            $content = GeolocManager::getGeolocFromAddress($address);

            $latitude = $content['features'][0]['geometry']['coordinates'][1] ?? null;
            $longitude = $content['features'][0]['geometry']['coordinates'][0] ?? null;

            if (!$latitude || !$longitude) {
                throw new \Exception(Translator::getInstance()->trans('No store address found !', [], GeolocatedDelivery::MESSAGE_DOMAIN));
            }

            $store->setLatitude($latitude);
            $store->setLongitude($longitude);

            $store->save();
        } catch (\Exception $e) {
            $message = $e->getMessage();
        }
        if ($message) {
            $form->setErrorMessage($message);

            $parserContext->addForm($form)
                ->setGeneralError($message);

            return $this->render(
                "module-configure",
                ["module_code" => GeolocatedDelivery::getModuleCode()]
            );
        }

        return new RedirectResponse(URL::getInstance()->absoluteUrl("/admin/module/" . GeolocatedDelivery::getModuleCode()));
    }

    #[Route('/delete/{id}', name: 'geolocateddelivery.admin.store.delete')]
    public function deleteStoreAction(Request $request, $id): RedirectResponse
    {
        $response = $this->checkAuth([], ['geolocateddelivery'], AccessManager::DELETE);

        if (null !== $response) {
            return $response;
        }

        try {
            if (0 !== $id) {
                $radius = GeolocatedDeliveryStoreQuery::create()->findPk($id);
                $radius->delete();
            } else {
                $responseData['message'] = Translator::getInstance()->trans(
                    'The store has not been deleted',
                    [],
                    GeolocatedDelivery::MESSAGE_DOMAIN
                );
            }
        } catch (\Exception $e) {
            return $this->render(
                "module-configure",
                ["module_code" => GeolocatedDelivery::getModuleCode()]
            );
        }

        return new RedirectResponse(URL::getInstance()->absoluteUrl("/admin/module/" . GeolocatedDelivery::getModuleCode()));
    }
}
