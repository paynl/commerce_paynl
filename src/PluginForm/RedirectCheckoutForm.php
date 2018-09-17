<?php
/**
 * Created by PhpStorm.
 * User: andy
 * Date: 5-9-18
 * Time: 11:32
 */

namespace Drupal\commerce_paynl\PluginForm;

use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\PluginForm\PaymentOffsiteForm;
use Drupal\Core\Form\FormStateInterface;

class RedirectCheckoutForm extends PaymentOffsiteForm {


  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    /** @var PaymentInterface $payment */
    $payment = $this->entity;

    $extra = [
      'returnUrl' => $form['#return_url'],

    ];

    /** @var \Drupal\commerce_paynl\Plugin\Commerce\PaymentGateway\Paynl $plugin */
    $plugin = $payment->getPaymentGateway()->getPlugin();
    $result = $plugin->startTransaction($payment, $extra);


    return $this->buildRedirectForm(
      $form,
      $form_state,
      $result->getRedirectUrl(),
      [],
      PaymentOffsiteForm::REDIRECT_GET
    );
  }


}
