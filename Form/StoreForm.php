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
 * Class StoreForm
 * @author thomas da silva mendonca <tdasilva@openstudio.fr>
 */
class StoreForm extends BaseForm
{
    protected function buildForm()
    {
        $form = $this->formBuilder;

        $form
            ->add(
                "id",
                TextType::class,
                [
                    'required'=>false
                ]
            )
            ->add(
                "street",
                TextType::class,
                [
                    'constraints' => [
                        new NotBlank(),
                        new NotNull(),
                    ],
                    'label' => $this->trans("street"),
                ]
            )
            ->add(
                "zip_code",
                TextType::class,
                [
                    'constraints' => [
                        new NotBlank(),
                        new NotNull(),
                    ],
                    'label' => $this->trans("zip_code"),
                ]
            )
            ->add(
                "city",
                TextType::class,
                [
                    'constraints' => [
                        new NotBlank(),
                        new NotNull(),
                    ],
                    'label' => $this->trans("city"),
                ]
            )
            ->add(
                "name",
                TextType::class,
                [
                    'constraints' => [
                        new NotBlank(),
                        new NotNull(),
                    ],
                    'label' => $this->trans("name"),
                ]
            );
    }

    protected function trans($id, array $parameters = [])
    {
        return $this->translator->trans($id, $parameters, GeolocatedDelivery::MESSAGE_DOMAIN);
    }
}
