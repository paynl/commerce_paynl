<?php
/**
 * Created by PhpStorm.
 * User: andy
 * Date: 4-9-18
 * Time: 16:04
 */

namespace Drupal\commerce_paynl\Plugin\Commerce\PaymentGateway;


use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayBase;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\SupportsNotificationsInterface;
use Drupal\Core\Form\FormStateInterface;
use Paynl\Result\Transaction\Transaction;
use Symfony\Component\HttpFoundation\Request;
use Zend\Diactoros\Response\TextResponse;

/**
 * Provides the Pay.nl payment gateway.
 *
 * @CommercePaymentGateway(
 *   id = "commerce_paynl",
 *   label = @Translation("Pay.nl"),
 *   display_label = @Translation("Pay.nl"),
 *    forms = {
 *     "offsite-payment" =
 *   "Drupal\commerce_paynl\PluginForm\RedirectCheckoutForm",
 *   },
 *   payment_method_types = {"credit_card"},
 *   credit_card_types = {
 *     "mastercard", "visa",
 *   },
 * )
 */
class Paynl extends OffsitePaymentGatewayBase implements SupportsNotificationsInterface {

  /**
   * @var array all currently supported methods
   */
  private $_allMethods = [
    736 => 'Afterpay',
    1903 => 'Amazon Pay',
    1705 => 'AMEX',
    436 => 'Bancontact',
    1672 => 'Billink',
    1744 => 'Capayable',
    1813 => 'Capayable Gespreid',
    1945 => 'CartaSI',
    710 => 'CarteBleue',
    1981 => 'Cashly',
    1939 => 'Dankort',
    815 => 'Fashioncheque',
    1669 => 'Fashiongiftcard',
    1702 => 'Focum',
    812 => 'Gezondheidsbon',
    694 => 'Giropay',
    1657 => 'Givacard',
    10 => 'iDEAL',
    1717 => 'Klarna',
    712 => 'Maestro',
    1588 => 'Mybank',
    136 => 'Overboeking',
    138 => 'PayPal',
    553 => 'Paysafecard',
    816 => 'Podiumkadokaart',
    707 => 'Postepay',
    559 => 'Sofortbanking',
    1987 => 'Spraypay',
    1600 => 'Telefonisch betalen',
    706 => 'Visa/Mastercard',
    1704 => 'VVV Giftcard',
    811 => 'WebshopGiftcard',
    1978 => 'Wechat Pay',
    1666 => 'Wijncadeau',
    1877 => 'Yehhpay',
    1645 => 'Yourgift',
  ];

  /**
   * Return a list of payment methods.
   * When no credentials are saved, it will return all payment methods.
   * When valid credentials are saved, it will fetch the payment methods from
   * pay.nl
   *
   * @return array methods to choose from
   */
  private function getMethods() {
    $paymentMethods = [];
    if (
      !empty($this->configuration['token_code']) &&
      !empty($this->configuration['api_token']) &&
      !empty($this->configuration['service_id'])
    ) {
      try {
        $this->loginSDK();
        $methods = \Paynl\Paymentmethods::getList();

        foreach ($methods as $key => $method) {
          $paymentMethods[$key] = $method['name'];
        }
      } catch (\Exception $e) {

      }
    }
    if (empty($paymentMethods)) {
      $paymentMethods = $this->_allMethods;
    }

    natcasesort($paymentMethods);

    return ['' => $this->t('All payment methods')] + $paymentMethods;

  }

  public function defaultConfiguration() {
    return [
        'token_code' => '',
        'api_token' => '',
        'service_id' => '',
        'payment_option_id' => '',
      ] + parent::defaultConfiguration();
  }

  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    $form['token_code'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Token code (AT-xxxx-xxxx)'),
      '#description' => $this->t('The token code belonging to your token, you can find your tokens <a href="https://admin.pay.nl/company/tokens">here</a>'),
      '#default_value' => $this->configuration['token_code'],
      '#required' => TRUE,
    ];

    $form['api_token'] = [
      '#type' => 'textfield',
      '#title' => $this->t('API token'),
      '#description' => $this->t('Your API token, you can find your tokens <a href="https://admin.pay.nl/company/tokens">here</a>'),
      '#default_value' => $this->configuration['api_token'],
      '#required' => TRUE,
    ];

    $form['service_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Service Id (SL-xxxx-xxxx)'),
      '#description' => $this->t('Your Service Id, you can find your services <a href="https://admin.pay.nl/programs/programs">here</a>'),
      '#default_value' => $this->configuration['service_id'],
      '#required' => TRUE,
    ];
    $form['payment_option_id'] = [
      '#type' => 'select',
      '#title' => $this->t('Payment method'),
      '#description' => $this->t('You can select a payment method here, so the customer is redirected to the correct payment method'),
      '#options' => $this->getMethods(),
      '#default_value' => $this->configuration['payment_option_id'],
    ];

    return $form;
  }

  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);

    if (!empty($form_state->getErrors())) {
      return;
    }

    $values = $form_state->getValue($form['#parents']);
    // check if values are correct
    \Paynl\Config::setTokenCode($values['token_code']);
    \Paynl\Config::setApiToken($values['api_token']);
    \Paynl\Config::setServiceId($values['service_id']);

    $this->configuration['token_code'] = $values['token_code'];
    $this->configuration['api_token'] = $values['api_token'];
    $this->configuration['service_id'] = $values['service_id'];
    $this->configuration['payment_option_id'] = $values['payment_option_id'];

    try {
      $paymentMethods = \Paynl\Paymentmethods::getList();
      if (
        $values['payment_option_id'] != '' &&
        !in_array($values['payment_option_id'], array_keys($paymentMethods))
      ) {
        $form_state->setError($form['payment_option_id'], $this->t('Payment method is not activated in your account'));
      }
    } catch (\Exception $e) {
      $form_state->setError($form['token_code'], $e->getMessage());
      $form_state->setError($form['api_token'], $e->getMessage());
      $form_state->setError($form['service_id'], $e->getMessage());
    }
  }

  private function loginSDK() {
    $configuration = $this->getConfiguration();

    \Paynl\Config::setTokenCode($configuration['token_code']);
    \Paynl\Config::setApiToken($configuration['api_token']);
    \Paynl\Config::setServiceId($configuration['service_id']);
  }

  public function onReturn(OrderInterface $order, Request $request) {
    $this->loginSDK();

    $transactionId = $request->get('orderId');
    $transaction = \Paynl\Transaction::get($transactionId);

    /** @var \Drupal\commerce_payment\PaymentStorage $payment_storage */
    $payment_storage = $this->entityTypeManager->getStorage('commerce_payment');
    /** @var PaymentInterface $payment */
    $payment = $payment_storage->loadByRemoteId($transactionId);

    if ($transaction->isCanceled()) {
      throw new PaymentGatewayException('Payment failed!');
    }
    $this->processStatus($payment->getOrder(), $payment, $transaction);
  }

  /**
   * @param \Paynl\Result\Transaction\Transaction $payTransaction
   *
   * @return string
   */
  private function getCommercePaymentStatus(Transaction $payTransaction) {
    if ($payTransaction->isCancelled()) {
      return 'authorization_voided'; //there is no canceled state
    }
    if ($payTransaction->isAuthorized()) {
      return 'authorization';
    }
    if ($payTransaction->isPaid()) {
      return 'complete';
    }
    if ($payTransaction->isPartiallyRefunded()) {
      return 'partially_refunded';
    }
    if ($payTransaction->isRefunded(FALSE)) {
      return 'refunded';
    }
    return 'new';
  }


  private function processStatus(OrderInterface $order, PaymentInterface $payment, Transaction $payTransaction) {
    $paymentStatus = $this->getCommercePaymentStatus($payTransaction);

    $payData = $payTransaction->getData();
    $remoteState = $payData['paymentDetails']['stateName'];

    $payment->setState($paymentStatus);
    $payment->setRemoteState($remoteState);
    $payment->save();

    // do transition
    if ($payTransaction->isPaid() || $payTransaction->isAuthorized()) {
      $transition = $order->getState()
        ->getWorkflow()
        ->getTransition('place');
      $order->getState()->applyTransition($transition);
    }
    elseif ($payTransaction->isCanceled() || $payTransaction->isRefunded()) {
      $transition = $order->getState()
        ->getWorkflow()
        ->getTransition('cancel');
      $order->getState()->applyTransition($transition);
    }

    return $paymentStatus;
  }

  public function onNotify(Request $request) {
    $this->loginSDK();
    $transactionId = $request->get('order_id'); // todo test if this works with POST

    $payTransaction = \Paynl\Transaction::get($transactionId);

    /** @var \Drupal\commerce_payment\PaymentStorage $payment_storage */
    $payment_storage = $this->entityTypeManager->getStorage('commerce_payment');
    /** @var PaymentInterface $payment */
    $payment = $payment_storage->loadByRemoteId($transactionId);
    $order = $payment->getOrder();

    $status = $this->processStatus($order, $payment, $payTransaction);

    return new TextResponse('TRUE| Status updated to ' . $status);
  }

  /**
   * @param \Drupal\commerce_payment\Entity\PaymentInterface $payment
   * @param array $extra
   *
   * @return \Paynl\Result\Transaction\Start
   * @throws \Paynl\Error\Error
   */
  public function startTransaction(PaymentInterface $payment, array $extra) {
    $this->loginSDK();

    $startData = $this->getStartData($payment, $extra);

    $result = \Paynl\Transaction::start($startData);
    $payment->setRemoteId($result->getTransactionId());
    $payment->setState('new');
    $payment->save();

    return $result;
  }

  private function getStartData(PaymentInterface $payment, array $extra) {
    $configuration = $this->getConfiguration();

    $order = $payment->getOrder();

    $startData = [
      'amount' => $payment->getAmount()->getNumber(),
      'currency' => $payment->getAmount()->getCurrencyCode(),
      'returnUrl' => $extra['returnUrl'],
      'exchangeUrl' => $this->getNotifyUrl()->toString(),
      'testmode' => $configuration['mode'] === 'test' ? 1 : 0,

      'ipaddress' => $order->getIpAddress(),
      'description' => $order->getOrderNumber(),
      'orderNumber' => $order->getOrderNumber(),
      'products' => $this->getProducts($order),

      'enduser' => $this->getEndUser($order),
      'address' => $this->getShippingAddress($order),
      'invoiceAddress' => $this->getInvoiceAddress($order),
      'company' => $this->getCompany($order),
    ];
    if (!empty($configuration['payment_option_id'])) {
      $startData['paymentMethod'] = $configuration['payment_option_id'];
    }

    return $startData;
  }

  private function getProducts(OrderInterface $order) {
    $items = $order->getItems();

    $arrProducts = [];
    $highestVat = 0;
    foreach ($items as $item) {
      // getVat
      $vatPercentage = 0;
      $adjustments = $item->getAdjustments();
      foreach ($adjustments as $adjustment) {
        if ($adjustment->getType() === 'tax') {
          $vatPercentage = $adjustment->getPercentage() * 100;
        }
      }
      if ($vatPercentage > $highestVat) {
        $highestVat = $vatPercentage;
      }

      $arrProducts[] = [
        'id' => $item->getPurchasedEntityId(),
        'name' => $item->getTitle(),
        'price' => $item->getUnitPrice()->getNumber(),
        'qty' => $item->getQuantity(),
        'type' => \Paynl\Transaction::PRODUCT_TYPE_ARTICLE,
        'vatPercentage' => $vatPercentage,

      ];

      $adjustments = $order->getAdjustments();
      foreach ($adjustments as $adjustment) {
        if ($adjustment->getType() == 'shipping') {
          $arrProducts[] = [
            'id' => $adjustment->getType(),
            'name' => $adjustment->getLabel(),
            'price' => $adjustment->getAmount()->getNumber(),
            'vatPercentage' => $highestVat,
            'qty' => '1',
          ];
        }
      }

      return $arrProducts;
    }
  }

  private function getEndUser(OrderInterface $order) {
    $arrEndUser = [];
    /** @var \Drupal\address\Plugin\Field\FieldType\AddressItem $address */
    $address = $order->getBillingProfile()->get('address')->first();

    $arrEndUser['initials'] = substr($address->getGivenName(), 0, 10);
    $arrEndUser['lastName'] = $address->getFamilyName();

    if ($order->hasField('shipments') && !$order->get('shipments')->isEmpty()) {
      // Gather the shipping profiles and only send shipping information if
      // there's only one shipping profile referenced by the shipments.
      $shipping_profiles = [];

      // Loop over the shipments to collect shipping profiles.
      foreach ($order->get('shipments')->referencedEntities() as $shipment) {
        if ($shipment->get('shipping_profile')->isEmpty()) {
          continue;
        }
        $shipping_profile = $shipment->getShippingProfile();
        $shipping_profiles[$shipping_profile->id()] = $shipping_profile;
      }

      // Don't send the shipping profile if we found more than one.
      if ($shipping_profiles && count($shipping_profiles) === 1) {
        $shipping_profile = reset($shipping_profiles);
        /** @var \Drupal\address\AddressInterface $address */
        $address = $shipping_profile->address->first();

        $arrEndUser['initials'] = substr($address->getGivenName(), 0, 10);
        $arrEndUser['lastName'] = $address->getFamilyName();
      }
    }

    $customer = $order->getCustomer();
    $arrEndUser['email'] = $customer->getEmail();


    return $arrEndUser;
  }

  private function getShippingAddress(OrderInterface $order) {
    $arrShippingAddress = [];

    // Check if the order references shipments.
    if ($order->hasField('shipments') && !$order->get('shipments')->isEmpty()) {
      // Gather the shipping profiles and only send shipping information if
      // there's only one shipping profile referenced by the shipments.
      $shipping_profiles = [];

      // Loop over the shipments to collect shipping profiles.
      foreach ($order->get('shipments')->referencedEntities() as $shipment) {
        if ($shipment->get('shipping_profile')->isEmpty()) {
          continue;
        }
        $shipping_profile = $shipment->getShippingProfile();
        $shipping_profiles[$shipping_profile->id()] = $shipping_profile;
      }

      // Don't send the shipping profile if we found more than one.
      if ($shipping_profiles && count($shipping_profiles) === 1) {
        $shipping_profile = reset($shipping_profiles);
        /** @var \Drupal\address\AddressInterface $address */
        $address = $shipping_profile->address->first();

        $strAddress = trim($address->getAddressLine1() . ' ' . $address->getAddressLine2());

        list($street, $number) = \Paynl\Helper::splitAddress($strAddress);
        $arrShippingAddress['streetName'] = $street;
        $arrShippingAddress['houseNumber'] = $number;
        $arrShippingAddress['zipCode'] = $address->getPostalCode();
        $arrShippingAddress['city'] = $address->getLocality();
        $arrShippingAddress['country'] = $address->getCountryCode();
      }
    }
    return $arrShippingAddress;
  }

  private function getInvoiceAddress(OrderInterface $order) {
    $arrInvoiceAddress = [];
    /** @var \Drupal\address\Plugin\Field\FieldType\AddressItem $address */
    $address = $order->getBillingProfile()->get('address')->first();

    $arrInvoiceAddress['initials'] = substr($address->getGivenName(), 0, 10);
    $arrInvoiceAddress['lastName'] = $address->getFamilyName();

    $strAddress = trim($address->getAddressLine1() . ' ' . $address->getAddressLine2());
    list($street, $number) = \Paynl\Helper::splitAddress($strAddress);
    $arrInvoiceAddress['streetName'] = $street;
    $arrInvoiceAddress['houseNumber'] = $number;
    $arrInvoiceAddress['zipCode'] = $address->getPostalCode();
    $arrInvoiceAddress['city'] = $address->getLocality();
    $arrInvoiceAddress['country'] = $address->getCountryCode();

    return $arrInvoiceAddress;
  }

  private function getCompany(OrderInterface $order) {
    $arrCompany = [];
    /** @var \Drupal\address\Plugin\Field\FieldType\AddressItem $address */
    $address = $order->getBillingProfile()->get('address')->first();

    $arrCompany['name'] = $address->getOrganization();
    $arrCompany['country'] = $address->getCountryCode();

    return $arrCompany;
  }
}
