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

use GeolocatedDelivery\GeolocatedDelivery;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Constraints\GreaterThanOrEqual;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Thelia\Core\Form\Type\Field\TaxRuleIdType;
use Thelia\Form\BaseForm;
use Thelia\Model\Base\TaxRuleQuery;

/**
 * Class ConfigurationForm
 * @author thomas da silva mendonca <tdasilva@openstudio.fr>
 */
class ConfigurationForm extends BaseForm
{
    public function checkTaxRuleId($value, ExecutionContextInterface $context)
    {
        if (0 !== intval($value)) {
            if (null === TaxRuleQuery::create()->findPk($value)) {
                $context->addViolation(
                    $this->trans(
                        "The Tax Rule id '%id' doesn't exist",
                        [
                            "%id" => $value,
                        ]
                    )
                );
            }
        }
    }

    protected function buildForm()
    {
        $form = $this->formBuilder;

        $config = GeolocatedDelivery::getConfig();

        $form
            ->add(
                "method",
                ChoiceType::class,
                [
                    'constraints' => [
                        new NotBlank(),
                        new GreaterThanOrEqual(['value' => 0])
                    ],
                    "choices" => [
                        $this->trans("maxRadius") => GeolocatedDelivery::METHOD_MAX_RADIUS,
                        $this->trans("minRadius") => GeolocatedDelivery::METHOD_MIN_RADIUS,
                        $this->trans("RadiusPrice") => GeolocatedDelivery::METHOD_PRICE,
                    ],
                    'data' => $config['method'],
                    'label' => $this->trans("Method"),
                    'label_attr' => [
                        'for' => "method",
                        'help' => $this->trans(
                            "The method used to select the right radius."
                        )
                    ],
                ]
            )
            ->add(
                "tax",
                TaxRuleIdType::class,
                [
                    "constraints" => [
                        new Callback(
                            [$this, 'checkTaxRuleId']
                        ),
                    ],
                    'required' => false,
                    'data' => $config['tax'],
                    'label' => $this->trans("Tax rule"),
                    'label_attr' => [
                        'for' => "method",
                        'help' => $this->trans(
                            "The tax rule used to calculate postage taxes."
                        )
                    ],
                ]
            );
    }

    protected function trans($id, array $parameters = [])
    {
        return $this->translator->trans($id, $parameters, GeolocatedDelivery::MESSAGE_DOMAIN);
    }
}
