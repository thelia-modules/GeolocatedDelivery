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

use GeolocatedDelivery\GeolocatedDelivery;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use GeolocatedDelivery\Form\ConfigurationForm;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Thelia\Controller\Admin\BaseAdminController;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Model\ConfigQuery;
use Thelia\Tools\URL;

/**
 * Class BackController
 * @package GeolocatedDelivery\Controller
 * @author thomas da silva mendonca <tdasilva@openstudio.fr>
 */
class ModuleController extends BaseAdminController
{
    #[Route('/admin/module/geolocateddelivery/configuration', name: 'geolocateddelivery.admin.configuration')]
    public function saveConfigurationAction() :RedirectResponse|Response
    {
        $response = $this->checkAuth([AdminResources::MODULE], ['geolocateddelivery'], AccessManager::UPDATE);

        if (null !== $response) {
            return $response;
        }

        $form = $this->createForm(ConfigurationForm::getName());
        $message = "";

        $response = null;

        try {
            $vform = $this->validateForm($form);
            $data = $vform->getData();

            GeolocatedDelivery::setConfigValue('url', $data['url']);
            GeolocatedDelivery::setConfigValue('method', $data['method']);
            GeolocatedDelivery::setConfigValue('tax', $data['tax']);

        } catch (\Exception $e) {
            $message = $e->getMessage();
        }
        if ($message) {
            $form->setErrorMessage($message);
            $this->getParserContext()->addForm($form);
            $this->getParserContext()->setGeneralError($message);

            return $this->render(
                "module-configure",
                ["module_code" => GeolocatedDelivery::getModuleCode()]
            );
        }

        return new RedirectResponse(URL::getInstance()->absoluteUrl("/admin/module/" . GeolocatedDelivery::getModuleCode()));
    }
}
