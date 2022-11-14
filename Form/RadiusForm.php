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


namespace GeolocatedDelivery\Form;

use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormTypeInterface;
use Symfony\Component\Validator\Constraints\GreaterThan;
use Symfony\Component\Validator\Constraints\NotNull;
use Symfony\Component\Validator\Constraints\Positive;
use Symfony\Component\Validator\Constraints\PositiveOrZero;
use GeolocatedDelivery\GeolocatedDelivery;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Validator\Constraints\GreaterThanOrEqual;
use Symfony\Component\Validator\Constraints\NotBlank;
use Thelia\Form\BaseForm;
use Thelia\Type\FloatType;

/**
 * Class SliceForm
 * @author thomas da silva mendonca <tdasilva@openstudio.fr>
 */
class RadiusForm extends BaseForm
{
    protected function buildForm()
    {
        $form = $this->formBuilder;

        $form
            ->add(
                "id",
                NumberType::class,
                [
                    'label' => $this->trans("Id"),
                    'required' => 'false',
                ]
            )
            ->add(
                "minRadius",
                NumberType::class,
                [
                    'constraints' => [
                        new NotBlank(),
                        new NotNull(),
                        new PositiveOrZero()
                    ],
                    'label' => $this->trans("minRadius"),
                ]
            )
            ->add(
                "maxRadius",
                NumberType::class,
                [
                    'constraints' => [
                        new NotBlank(),
                        new NotNull(),
                        new Positive()
                    ],
                    'label' => $this->trans("maxRadius"),
                ]
            )
            ->add(
                "price",
                NumberType::class,
                [
                    'constraints' => [
                        new NotBlank(),
                        new NotNull(),
                        new Positive()
                    ],
                    'label' => $this->trans("RadiusPrice"),
                ]
            );
    }

    protected function trans($id, array $parameters = [])
    {
        return $this->translator->trans($id, $parameters, GeolocatedDelivery::MESSAGE_DOMAIN);
    }
}
