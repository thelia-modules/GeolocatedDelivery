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

use Exception;
use GeolocatedDelivery\Form\RadiusForm;
use GeolocatedDelivery\GeolocatedDelivery;
use GeolocatedDelivery\Model\GeolocatedDeliveryRadius;
use GeolocatedDelivery\Model\GeolocatedDeliveryRadiusQuery;
use Symfony\Component\Routing\Annotation\Route;
use Thelia\Core\HttpFoundation\Request;
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
#[Route('/admin/module/geolocateddelivery/radius')]
class RadiusController extends BaseAdminController
{
    protected $currentRouter = 'router.geolocateddelivery';

    protected $useFallbackTemplate = true;

    #[Route('/save', name: 'geolocateddelivery.admin.radius.update')]
    public function saveAction()
    {
        $response = $this->checkAuth([AdminResources::MODULE], ['geolocateddelivery'], AccessManager::UPDATE);

        if (null !== $response) {
            return $response;
        }

        $form = $this->createForm(RadiusForm::getName());
        $response = null;

        try {
            $vform = $this->validateForm($form);
            $data = $vform->getData();

            if ($data["maxRadius"] <= $data["minRadius"]) {
                throw new Exception("maximum radius must be greater than minimal radius");
            }

            $radius = $data['id'] ? GeolocatedDeliveryRadiusQuery::create()->findOneById($data['id']) : new GeolocatedDeliveryRadius();

            if (!$radius) {
                throw new Exception("radius not found");
            }

            $radius->setMinRadius($data["minRadius"])
                   ->setMaxRadius($data["maxRadius"])
                   ->setPrice($data["price"])
                   ->save();

        } catch (Exception $e) {
            $form->setErrorMessage($e->getMessage());
            $this->getParserContext()->addForm($form);
            $this->getParserContext()->setGeneralError($e->getMessage());

            return $this->render(
                "module-configure",
                ["module_code" => GeolocatedDelivery::getModuleCode()]
            );
        }

        return new RedirectResponse(URL::getInstance()->absoluteUrl("/admin/module/" . GeolocatedDelivery::getModuleCode()));
    }

    #[Route('/delete/{id}', name: 'geolocateddelivery.admin.radius.delete')]
    public function deleteAction(Request $request, $id)
    {
        $response = $this->checkAuth([], ['geolocateddelivery'], AccessManager::DELETE);

        if (null !== $response) {
            return $response;
        }

        try {
            if (0 === $id) {
                throw new Exception("invalid radius id");
            }
            $radius = GeolocatedDeliveryRadiusQuery::create()->findPk($id);
            $radius->delete();
        } catch (Exception $e) {
            return $this->render(
                "module-configure",
                ["module_code" => GeolocatedDelivery::getModuleCode()]
            );
        }

        return new RedirectResponse(URL::getInstance()->absoluteUrl("/admin/module/" . GeolocatedDelivery::getModuleCode()));
    }
}
