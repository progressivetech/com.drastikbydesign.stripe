<?php

/*
 * Payment Processor class for Stripe
 */

class CRM_Core_Payment_Stripe extends CRM_Core_Payment {

  /**
   * We only need one instance of this object. So we use the singleton
   * pattern and cache the instance in this variable
   *
   * @var object
   * @static
   */
  static private $_singleton = NULL;

  /**
   * Mode of operation: live or test.
   *
   * @var object
   */
  protected $_mode = NULL;

  /**
   * Constructor
   *
   * @param string $mode
   *   The mode of operation: live or test.
   *
   * @return void
   */
  public function __construct($mode, &$paymentProcessor) {
    $this->_mode = $mode;
    $this->_islive = ($mode == 'live' ? 1 : 0);
    $this->_paymentProcessor = $paymentProcessor;
    $this->_processorName = ts('Stripe');
  }

  /**
   * This function checks to see if we have the right config values.
   *
   * @return string
   *   The error message if any.
   *
   * @public
   */
  public function checkConfig() {
    $config = CRM_Core_Config::singleton();
    $error = array();

    if (empty($this->_paymentProcessor['user_name'])) {
      $error[] = ts('The "Secret Key" is not set in the Stripe Payment Processor settings.');
    }

    if (empty($this->_paymentProcessor['password'])) {
      $error[] = ts('The "Publishable Key" is not set in the Stripe Payment Processor settings.');
    }

    if (!empty($error)) {
      return implode('<p>', $error);
    }
    else {
      return NULL;
    }
  }

  /**
   * Helper log function.
   *
   * @param string $op
   *   The Stripe operation being performed.
   * @param Exception $exception
   *   The error!
   */
  public function logStripeException($op, $exception) {
    $body = print_r($exception->getJsonBody(), TRUE);
    CRM_Core_Error::debug_log_message("Stripe_Error {$op}:  <pre> {$body} </pre>");
  }

  /**
   * Check if return from stripeCatchErrors was an error object
   * that should be passed back to original api caller.
   *
   * @param  $stripeReturn
   *   The return from a call to stripeCatchErrors
   * @return bool
   *
   */
  public function isErrorReturn($stripeReturn) {
      if (is_object($stripeReturn) && get_class($stripeReturn) == 'CRM_Core_Error') {
        return true;
      }
      return false;
  }

  /**
   * Run Stripe calls through this to catch exceptions gracefully.
   *
   * @param string $op
   *   Determine which operation to perform.
   * @param array $params
   *   Parameters to run Stripe calls on.
   *
   * @return varies
   *   Response from gateway.
   */
  public function stripeCatchErrors($op = 'create_customer', $stripe_params, $params, $ignores = array()) {
    $error_url = $params['stripe_error_url'];
    $return = FALSE;
    // Check for errors before trying to submit.
    try {
      switch ($op) {
         case 'create_customer':
          $return = \Stripe\Customer::create($stripe_params);
          break;

        case 'update_customer':
          $return = \Stripe\Customer::update($stripe_params);
          break;

        case 'charge':
          $return = \Stripe\Charge::create($stripe_params);
          break;

        case 'save':
          $return = $stripe_params->save();
          break;

        case 'create_plan':
          $return = \Stripe\Plan::create($stripe_params);
          break;

        case 'retrieve_customer':
          $return = \Stripe\Customer::retrieve($stripe_params);
          break;

        case 'retrieve_balance_transaction':
          $return = \Stripe\BalanceTransaction::retrieve($stripe_params);
          break;

        default:
          $return = \Stripe\Customer::create($stripe_params);
          break;
      }
    }
    catch (Exception $e) {
      if (is_a($e, 'Stripe_Error')) {
        foreach ($ignores as $ignore) {
          if (is_a($e, $ignore['class'])) {
            $body = $e->getJsonBody();
            $error = $body['error'];
            if ($error['type'] == $ignore['type'] && $error['message'] == $ignore['message']) {
              return $return;
            }
          }
        }
      }

      $this->logStripeException($op, $e);
      $error_message = '';
      // Since it's a decline, Stripe_CardError will be caught
      $body = $e->getJsonBody();
      $err = $body['error'];
      if (!isset($err['code'])) {
        $err['code'] = null;
      }
      //$error_message .= 'Status is: ' . $e->getHttpStatus() . "<br />";
      ////$error_message .= 'Param is: ' . $err['param'] . "<br />";
      $error_message .= 'Type: ' . $err['type'] . '<br />';
      $error_message .= 'Code: ' . $err['code'] . '<br />';
      $error_message .= 'Message: ' . $err['message'] . '<br />';

      if (is_a($e, 'Stripe_CardError')) {
        $newnote = civicrm_api3('Note', 'create', array(
          'sequential' => 1,
          'entity_id' => $params['contactID'],
          'contact_id' => $params['contributionID'],
          'subject' => $err['type'],
          'note' => $err['code'],
          'entity_table' => "civicrm_contributions",
        ));
      }

      if (isset($error_url)) {
      // Redirect to first page of form and present error.
      CRM_Core_Error::statusBounce("Oops!  Looks like there was an error.  Payment Response:
        <br /> {$error_message}", $error_url);
      }
      else {
        // Don't have return url - return error object to api
        $core_err = CRM_Core_Error::singleton();
        $message = 'Oops!  Looks like there was an error.  Payment Response: <br />' . $error_message;
        if ($err['code']) {
          $core_err->push($err['code'], 0, NULL, $message);
        }
        else {
          $core_err->push(9000, 0, NULL, 'Unknown Error: ' . $message);
        }
        return $core_err;
      }
    }

    return $return;
  }

  /**
   * Override CRM_Core_Payment function
   */
  public function getPaymentFormFields() {
    return array(
      'credit_card_type',
      'credit_card_number',
      'cvv2',
      'credit_card_exp_date',
      'stripe_token',
      'stripe_pub_key',
      'stripe_id',
    );
  }

  /**
   * Return an array of all the details about the fields potentially required for payment fields.
   *
   * Only those determined by getPaymentFormFields will actually be assigned to the form
   *
   * @return array
   *   field metadata
   */
  public function getPaymentFormFieldsMetadata() {
    $creditCardType = array('' => ts('- select -')) + CRM_Contribute_PseudoConstant::creditCard();
    return array(
      'credit_card_number' => array(
        'htmlType' => 'text',
        'name' => 'credit_card_number',
        'title' => ts('Card Number'),
        'cc_field' => TRUE,
        'attributes' => array(
          'size' => 20,
          'maxlength' => 20,
          'autocomplete' => 'off',
        ),
        'is_required' => TRUE,
      ),
      'cvv2' => array(
        'htmlType' => 'text',
        'name' => 'cvv2',
        'title' => ts('Security Code'),
        'cc_field' => TRUE,
        'attributes' => array(
          'size' => 5,
          'maxlength' => 10,
          'autocomplete' => 'off',
        ),
        'is_required' => TRUE,
      ),
      'credit_card_exp_date' => array(
        'htmlType' => 'date',
        'name' => 'credit_card_exp_date',
        'title' => ts('Expiration Date'),
        'cc_field' => TRUE,
        'attributes' => CRM_Core_SelectValues::date('creditCard'),
        'is_required' => TRUE,
        'month_field' => 'credit_card_exp_date_M',
        'year_field' => 'credit_card_exp_date_Y',
      ),

      'credit_card_type' => array(
        'htmlType' => 'select',
        'name' => 'credit_card_type',
        'title' => ts('Card Type'),
        'cc_field' => TRUE,
        'attributes' => $creditCardType,
        'is_required' => FALSE,
      ),
      'stripe_token' => array(
        'htmlType' => 'hidden',
        'name' => 'stripe_token',
        'title' => 'Stripe Token',
        'attributes' => array(
          'id' => 'stripe-token',
        ),
        'cc_field' => TRUE,
        'is_required' => TRUE,
      ),
      'stripe_id' => array(
        'htmlType' => 'hidden',
        'name' => 'stripe_id',
        'title' => 'Stripe ID',
        'attributes' => array(
          'id' => 'stripe-id',
        ),
        'cc_field' => TRUE,
        'is_required' => TRUE,
      ),
      'stripe_pub_key' => array(
        'htmlType' => 'hidden',
        'name' => 'stripe_pub_key',
        'title' => 'Stripe Public Key',
        'attributes' => array(
          'id' => 'stripe-pub-key',
        ),
        'cc_field' => TRUE,
        'is_required' => TRUE,
      ),
    );
  }

  /**
   * Get form metadata for billing address fields.
   *
   * @param int $billingLocationID
   *
   * @return array
   *    Array of metadata for address fields.
   */
  public function getBillingAddressFieldsMetadata($billingLocationID = NULL) {
    $metadata = parent::getBillingAddressFieldsMetadata($billingLocationID);
    if (!$billingLocationID) {
      // Note that although the billing id is passed around the forms the idea that it would be anything other than
      // the result of the function below doesn't seem to have eventuated.
      // So taking this as a param is possibly something to be removed in favour of the standard default.
      $billingLocationID = CRM_Core_BAO_LocationType::getBilling();
    }

    // Stripe does not require the state/county field
    if (!empty($metadata["billing_state_province_id-{$billingLocationID}"]['is_required'])) {
      $metadata["billing_state_province_id-{$billingLocationID}"]['is_required'] = FALSE;
    }

    return $metadata;
  }

  /**
   * Implementation of hook_civicrm_buildForm().
   *
   * @param $form - reference to the form object
   */
  public function buildForm(&$form) {
    if ($form->isSubmitted()) return;

    $stripe_ppid = CRM_Utils_Array::value('id', $form->_paymentProcessor);
    $stripe_key = self::stripe_get_key($stripe_ppid);

    // Set ddi_reference
    $defaults = array();
    $defaults['stripe_id'] = $stripe_ppid;
    $defaults['stripe_pub_key'] = $stripe_key;
    $form->setDefaults($defaults);
  }

  /**
   * Given a payment processor id, return the pub key.
   */
  public function stripe_get_key($stripe_ppid) {
    try {
      $result = civicrm_api3('PaymentProcessor', 'getvalue', array(
        'return' => "password",
        'id' => $stripe_ppid,
      ));
    }
    catch (CiviCRM_API3_Exception $e) {
      return NULL;
    }
    return $result;
  }

  /**
   * Return the CiviCRM version we're running.
   */
  public function get_civi_version() {
    $version = civicrm_api3('Domain', 'getvalue', array(
      'return' => "version",
      'current_domain' => true,
    ));
    return $version;
  }

  /**
   * Submit a payment using Stripe's PHP API:
   * https://stripe.com/docs/api?lang=php
   *
   * @param array $params
   *   Assoc array of input parameters for this transaction.
   *
   * @return array
   *   The result in a nice formatted array (or an error object).
   *
   * @public
   */
  public function doDirectPayment(&$params) {
    // Let a $0 transaction pass.
    if (empty($params['amount']) || $params['amount'] == 0) {
      return $params;
    }

    // Get proper entry URL for returning on error.
    if (!(array_key_exists('qfKey', $params))) {
      // Probably not called from a civicrm form (e.g. webform) -
      // will return error object to original api caller.
      $params['stripe_error_url'] = $error_url = null;
    }
    else {
      $qfKey = $params['qfKey'];
      $parsed_url = parse_url($params['entryURL']);
      $url_path = substr($parsed_url['path'], 1);
      $params['stripe_error_url'] = $error_url = CRM_Utils_System::url($url_path,
      $parsed_url['query'] . "&_qf_Main_display=1&qfKey={$qfKey}", FALSE, NULL, FALSE);
    }

    // Set plugin info and API credentials.
    \Stripe\Stripe::setAppInfo('CiviCRM', CRM_Utils_System::version(), CRM_Utils_System::baseURL());
    \Stripe\Stripe::setApiKey($this->_paymentProcessor['user_name']);

    // Stripe amount required in cents.
    $amount = number_format($params['amount'], 2, '.', '');
    $amount = (int) preg_replace('/[^\d]/', '', strval($amount));

    // Use Stripe.js instead of raw card details.
    if (!empty($params['stripe_token'])) {
      $card_token = $params['stripe_token'];
    }
    else if(!empty(CRM_Utils_Array::value('stripe_token', $_POST, NULL))) {
      $card_token = CRM_Utils_Array::value('stripe_token', $_POST, NULL);
    }
    else {
      CRM_Core_Error::statusBounce(ts('Unable to complete payment! Please this to the site administrator with a description of what you were trying to do.'));
      Civi::log()->debug('Stripe.js token was not passed!  Report this message to the site administrator. $params: ' . print_r($params, TRUE));
    }

    // Check for existing customer, create new otherwise.
    // Possible email fields.
    $email_fields = array(
      'email',
      'email-5',
      'email-Primary',
    );

    // Possible contact ID fields.
    $contact_id_fields = array(
      'contact_id',
      'contactID',
    );

    // Find out which email field has our yummy value.
    foreach ($email_fields as $email_field) {
      if (!empty($params[$email_field])) {
        $email = $params[$email_field];
        break;
      }
    }

    // We didn't find an email, but never fear - this might be a backend contrib.
    // We can look for a contact ID field and get the email address.
    if (empty($email)) {
      foreach ($contact_id_fields as $cid_field) {
        if (!empty($params[$cid_field])) {
          $email = civicrm_api3('Contact', 'getvalue', array(
            'id' => $params[$cid_field],
            'return' => 'email',
          ));
          break;
        }
      }
    }

    // We still didn't get an email address?!  /ragemode on
    if (empty($email)) {
      CRM_Core_Error::fatal(ts('No email address found.  Please report this issue.'));
    }

    // Prepare escaped query params.
    $query_params = array(
      1 => array($email, 'String'),
      2 => array($this->_paymentProcessor['id'], 'Integer'),
    );

    $customer_query = CRM_Core_DAO::singleValueQuery("SELECT id
      FROM civicrm_stripe_customers
      WHERE email = %1 AND is_live = '{$this->_islive}' AND processor_id = %2", $query_params);

    /****
     * If for some reason you cannot use Stripe.js and you are aware of PCI Compliance issues,
     * here is the alternative to Stripe.js:
     ****/

    /*
      // Get Cardholder's full name.
      $cc_name = $params['first_name'] . " ";
      if (strlen($params['middle_name']) > 0) {
        $cc_name .= $params['middle_name'] . " ";
      }
      $cc_name .= $params['last_name'];

      // Prepare Card details in advance to use for new Stripe Customer object if we need.
      $card_details = array(
        'number' => $params['credit_card_number'],
        'exp_month' => $params['month'],
        'exp_year' => $params['year'],
        'cvc' => $params['cvv2'],
        'name' => $cc_name,
        'address_line1' => $params['street_address'],
        'address_state' => $params['state_province'],
        'address_zip' => $params['postal_code'],
      );
    */

    // drastik - Uncomment this for Drupal debugging to dblog.
    /*
     $zz = print_r(get_defined_vars(), TRUE);
     $debug_code = '<pre>' . $zz . '</pre>';
     watchdog('Stripe', $debug_code);
    */

    // Customer not in civicrm_stripe database.  Create a new Customer in Stripe.
    if (!isset($customer_query)) {
      $sc_create_params = array(
        'description' => 'Donor from CiviCRM',
        'card' => $card_token,
        'email' => $email,
      );

      $stripe_customer = $this->stripeCatchErrors('create_customer', $sc_create_params, $params);

      // Store the relationship between CiviCRM's email address for the Contact & Stripe's Customer ID.
      if (isset($stripe_customer)) {
        if ($this->isErrorReturn($stripe_customer)) {
          return $stripe_customer;
        }
        // Prepare escaped query params.
        $query_params = array(
          1 => array($email, 'String'),
          2 => array($stripe_customer->id, 'String'),
          3 => array($this->_paymentProcessor['id'], 'Integer'),
        );

        CRM_Core_DAO::executeQuery("INSERT INTO civicrm_stripe_customers
          (email, id, is_live, processor_id) VALUES (%1, %2, '{$this->_islive}', %3)", $query_params);
      }
      else {
        CRM_Core_Error::fatal(ts('There was an error saving new customer within Stripe.  Is Stripe down?'));
      }
    }
    else {
      // Customer was found in civicrm_stripe database, fetch from Stripe.
      $stripe_customer = $this->stripeCatchErrors('retrieve_customer', $customer_query, $params);
      if (!empty($stripe_customer)) {
        if ($this->isErrorReturn($stripe_customer)) {
          return $stripe_customer;
        }
        // Avoid the 'use same token twice' issue while still using latest card.
        if (!empty($params['selectMembership'])
          && $params['selectMembership']
          && empty($params['contributionPageID'])
        ) {
          // This is a Contribution form w/ Membership option and charge is
          // coming through for the 2nd time.  Don't need to update customer again.
        }
        else {
          $stripe_customer->card = $card_token;
          $response = $this->stripeCatchErrors('save', $stripe_customer, $params);
            if (isset($response) && $this->isErrorReturn($response)) {
              return $response;
            }
        }
      }
      else {
        // Customer was found in civicrm_stripe database, but unable to be
        // retrieved from Stripe.  Was he deleted?
        $sc_create_params = array(
          'description' => 'Donor from CiviCRM',
          'card' => $card_token,
          'email' => $email,
        );

        $stripe_customer = $this->stripeCatchErrors('create_customer', $sc_create_params, $params);

        // Somehow a customer ID saved in the system no longer pairs
        // with a Customer within Stripe.  (Perhaps deleted using Stripe interface?).
        // Store the relationship between CiviCRM's email address for the Contact & Stripe's Customer ID.
        if (isset($stripe_customer)) {
          /*if ($this->isErrorReturn($stripe_customer)) {
            return $stripe_customer;
          }*/
          // Delete whatever we have for this customer.
          $query_params = array(
            1 => array($email, 'String'),
            2 => array($this->_paymentProcessor['id'], 'Integer'),
          );
          CRM_Core_DAO::executeQuery("DELETE FROM civicrm_stripe_customers
            WHERE email = %1 AND is_live = '{$this->_islive}' AND processor_id = %2", $query_params);

          // Create new record for this customer.
          $query_params = array(
            1 => array($email, 'String'),
            2 => array($stripe_customer->id, 'String'),
            3 => array($this->_paymentProcessor['id'], 'Integer'),
          );
          CRM_Core_DAO::executeQuery("INSERT INTO civicrm_stripe_customers (email, id, is_live, processor_id)
            VALUES (%1, %2, '{$this->_islive}, %3')", $query_params);
        }
        else {
          // Customer was found in civicrm_stripe database, but unable to be
          // retrieved from Stripe, and unable to be created in Stripe.  What luck :(
          CRM_Core_Error::fatal(ts('There was an error saving new customer within Stripe.  Is Stripe down?'));
        }
      }
    }

    // Prepare the charge array, minus Customer/Card details.
    if (empty($params['description'])) {
      $params['description'] = ts('CiviCRM backend contribution');
    }
    else {
      $params['description'] = ts('CiviCRM # ') . $params['description'];
    }

    // Stripe charge.
    $stripe_charge = array(
      'amount' => $amount,
      'currency' => strtolower($params['currencyID']),
      'description' => $params['description'] . ' # Invoice ID: ' . CRM_Utils_Array::value('invoiceID', $params),
    );

    // Use Stripe Customer if we have a valid one.  Otherwise just use the card.
    if (!empty($stripe_customer->id)) {
      $stripe_charge['customer'] = $stripe_customer->id;
    }
    else {
      $stripe_charge['card'] = $card_token;
    }

    // Handle recurring payments in doRecurPayment().
    if (CRM_Utils_Array::value('is_recur', $params) && $params['contributionRecurID']) {
      return $this->doRecurPayment($params, $amount, $stripe_customer);
    }

    // Fire away!  Check for errors before trying to submit.
    $stripe_response = $this->stripeCatchErrors('charge', $stripe_charge, $params);
    if (!empty($stripe_response)) {
      if ($this->isErrorReturn($stripe_response)) {
        return $stripe_response;
      }
      // Success!  Return some values for CiviCRM.
      $params['trxn_id'] = $stripe_response->id;
      // Return fees & net amount for Civi reporting.
      // Uses new Balance Trasaction object.
      $balance_transaction = $this->stripeCatchErrors('retrieve_balance_transaction', $stripe_response->balance_transaction, $params);
      if (!empty($balance_transaction)) {
        if ($this->isErrorReturn($balance_transaction)) {
          return $balance_transaction;
        }
        $params['fee_amount'] = $balance_transaction->fee / 100;
        $params['net_amount'] = $balance_transaction->net / 100;
      }
    }
    else {
      // There was no response from Stripe on the create charge command.
      if (isset($error_url)) {
        CRM_Core_Error::statusBounce('Stripe transaction response not recieved!  Check the Logs section of your stripe.com account.', $error_url);
      }
      else {
        // Don't have return url - return error object to api
        $core_err = CRM_Core_Error::singleton();
        $core_err->push(9000, 0, NULL, 'Stripe transaction response not recieved!  Check the Logs section of your stripe.com account.');
        return $core_err;
      }
    }

    return $params;
  }

  /**
   * Submit a recurring payment using Stripe's PHP API:
   * https://stripe.com/docs/api?lang=php
   *
   * @param array $params
   *   Assoc array of input parameters for this transaction.
   * @param int $amount
   *   Transaction amount in USD cents.
   * @param object $stripe_customer
   *   Stripe customer object generated by Stripe API.
   *
   * @return array
   *   The result in a nice formatted array (or an error object).
   *
   * @public
   */
  public function doRecurPayment(&$params, $amount, $stripe_customer) {
    // Get recurring contrib properties.
    $frequency = $params['frequency_unit'];
    $frequency_interval = (empty($params['frequency_interval']) ? 1 : $params['frequency_interval']);
    $currency = strtolower($params['currencyID']);
    if (isset($params['installments'])) {
      $installments = $params['installments'];
    }

    // This adds some support for CiviDiscount on recurring contributions and changes the default behavior to discounting
    // only the first of a recurring contribution set instead of all. (Intro offer) The Stripe procedure for discounting the
    // first payment of subscription entails creating a negative invoice item or negative balance first,
    // then creating the subscription at 100% full price. The customers first Stripe invoice will reflect the
    // discount. Subsequent invoices will be at the full undiscounted amount.
    // NB: Civi currently won't send a $0 charge to a payproc extension, but it should in this case. If the discount is >
    // the cost of initial payment, we still send the whole discount (or giftcard) as a negative balance.
    // Consider not selling giftards greater than your least expensive auto-renew membership until we can override this.
    // TODO: add conditonals that look for $param['intro_offer'] (to give admins the choice of default behavior) and
    // $params['trial_period'].

    if (!empty($params['discountcode'])) {
      $discount_code = $params['discountcode'];
      $discount_object = civicrm_api3('DiscountCode', 'get', array(
         'sequential' => 1,
         'return' => "amount,amount_type",
         'code' => $discount_code,
          ));
       // amount_types: 1 = percentage, 2 = fixed, 3 = giftcard
       if ((!empty($discount_object['values'][0]['amount'])) && (!empty($discount_object['values'][0]['amount_type']))) {
         $discount_type = $discount_object['values'][0]['amount_type'];
         if ( $discount_type == 1 ) {
         // Discount is a percentage. Avoid ugly math and just get the full price using price_ param.
           foreach($params as $key=>$value){
             if("price_" == substr($key,0,6)){
               $price_param = $key;
               $price_field_id = substr($key,strrpos($key,'_') + 1);
             }
           }
           if (!empty($params[$price_param])) {
             $priceFieldValue = civicrm_api3('PriceFieldValue', 'get', array(
               'sequential' => 1,
               'return' => "amount",
               'id' => $params[$price_param],
               'price_field_id' => $price_field_id,
              ));
           }
           if (!empty($priceFieldValue['values'][0]['amount'])) {
              $priceset_amount = $priceFieldValue['values'][0]['amount'];
              $full_price = $priceset_amount * 100;
              $discount_in_cents = $full_price - $amount;
              // Set amount to full price.
              $amount = $full_price;
           }
        } else if ( $discount_type >= 2 ) {
        // discount is fixed or a giftcard. (may be > amount).
          $discount_amount = $discount_object['values'][0]['amount'];
          $discount_in_cents = $discount_amount * 100;
          // Set amount to full price.
          $amount =  $amount + $discount_in_cents;
        }
     }
        // Apply the disount through a negative balance.
       $stripe_customer->account_balance = -$discount_in_cents;
       $stripe_customer->save();
     }

    // Tying a plan to a membership (or priceset->membership) makes it possible
    // to automatically change the users membership level with subscription upgrade/downgrade.
    // An amount is not enough information to distinguish a membership related recurring
    // contribution from a non-membership related one.
    $membership_type_tag = '';
    $membership_name = '';
    if (isset($params['selectMembership'])) {
      $membership_type_id = $params['selectMembership'][0];
      $membership_type_tag = 'membertype_' . $membership_type_id . '-';
      $membershipType = civicrm_api3('MembershipType', 'get', array(
       'sequential' => 1,
       'return' => "name",
       'id' => $membership_type_id,
      ));
      $membership_name = $membershipType['values'][0]['name'];
    }

    // Currently plan_id is a unique db key. Therefore test plans of the
    // same name as a live plan fail to be added with a DB error Already exists,
    // which is a problem for testing.  This appends 'test' to a test
    // plan to avoid that error.
    $is_live = $this->_islive;
    $mode_tag = '';
    if ( $is_live == 0 ) {
      $mode_tag = '-test';
    }
    $plan_id = "{$membership_type_tag}every-{$frequency_interval}-{$frequency}-{$amount}-{$currency}{$mode_tag}";

    // Prepare escaped query params.
    $query_params = array(
      1 => array($plan_id, 'String'),
    );


    // Prepare escaped query params.
    $query_params = array(
      1 => array($plan_id, 'String'),
      2 => array($this->_paymentProcessor['id'], 'Integer'),
    );

    $stripe_plan_query = CRM_Core_DAO::singleValueQuery("SELECT plan_id
      FROM civicrm_stripe_plans
      WHERE plan_id = %1 AND is_live = '{$this->_islive}' AND processor_id = %2", $query_params);

    if (!isset($stripe_plan_query)) {
      $formatted_amount = number_format(($amount / 100), 2);
      $product = \Stripe\Product::create(array(
        "name" => "CiviCRM {$membership_name} every {$frequency_interval} {$frequency}(s) {$formatted_amount}{$currency}{$mode_tag}",
        "type" => "service"
      ));
      // Create a new Plan.
      $stripe_plan = array(
        'amount' => $amount,
        'interval' => $frequency,
        'product' => $product->id,
        'currency' => $currency,
        'id' => $plan_id,
        'interval_count' => $frequency_interval,
      );

      $ignores = array(
        array(
          'class' => 'Stripe_InvalidRequestError',
          'type' => 'invalid_request_error',
          'message' => 'Plan already exists.',
        ),
      );
      $this->stripeCatchErrors('create_plan', $stripe_plan, $params, $ignores);
      // Prepare escaped query params.
      $query_params = array(
        1 => array($plan_id, 'String'),
        2 => array($this->_paymentProcessor['id'], 'Integer'),
      );
      CRM_Core_DAO::executeQuery("INSERT INTO civicrm_stripe_plans (plan_id, is_live, processor_id)
        VALUES (%1, '{$this->_islive}', %2)", $query_params);
    }

    // As of Feb. 2014, Stripe handles multiple subscriptions per customer, even
    // ones of the exact same plan. To pave the way for that kind of support here,
    // were using subscription_id as the unique identifier in the
    // civicrm_stripe_subscription table, instead of using customer_id to derive
    // the invoice_id.  The proposed default behavor should be to always create a
    // new subscription. Upgrade/downgrades keep the same subscription id in Stripe
    // and we mirror this behavior by modifing our recurring contribution when this happens.
    // For now, updating happens in Webhook.php as a result of modifiying the subscription
    // in the UI at stripe.com. Eventually we'll initiating subscription changes
    // from within Civi and Stripe.php. The Webhook.php code should still be relevant.

    // Attach the Subscription to the Stripe Customer.
    $cust_sub_params = array(
      'prorate' => FALSE,
      'plan' => $plan_id,
    );
    $stripe_response = $stripe_customer->subscriptions->create($cust_sub_params);
    $subscription_id = $stripe_response->id;
    $recuring_contribution_id = $params['contributionRecurID'];

    // Prepare escaped query params.
    $query_params = array(
      1 => array($subscription_id, 'String'),
      2 => array($stripe_customer->id, 'String'),
      3 => array($recuring_contribution_id, 'String'),
      4 => array($this->_paymentProcessor['id'], 'Integer'),
    );

    // Insert the Stripe Subscription info.

    // Let end_time be NULL if installments are ongoing indefinitely
    if (empty($installments)) {
      CRM_Core_DAO::executeQuery("INSERT INTO civicrm_stripe_subscriptions
        (subscription_id, customer_id, contribution_recur_id, processor_id, is_live )
        VALUES (%1, %2, %3, %4,'{$this->_islive}')", $query_params);
    } else {
      // Calculate timestamp for the last installment.
      $end_time = strtotime("+{$installments} {$frequency}");
      // Add the end time to the query params.
      $query_params[5] = array($end_time, 'Integer');
      CRM_Core_DAO::executeQuery("INSERT INTO civicrm_stripe_subscriptions
        (subscription_id, customer_id, contribution_recur_id, processor_id, end_time, is_live)
        VALUES (%1, %2, %3, %4, %5, '{$this->_islive}')", $query_params);
    }

    //  Don't return a $params['trxn_id'] here or else recurring membership contribs will be set
    //  "Completed" prematurely.  Webhook.php does that.
    
    // Add subscription_id so tests can properly work with recurring
    // contributions. 
    $params['subscription_id'] = $subscription_id;

    return $params;

  }

  /**
   * Transfer method not in use.
   *
   * @param array $params
   *   Name value pair of contribution data.
   *
   * @return void
   *
   * @access public
   *
   */
  public function doTransferCheckout(&$params, $component) {
    self::doDirectPayment($params);
  }

  /**
   * Default payment instrument validation.
   *
   * Implement the usual Luhn algorithm via a static function in the CRM_Core_Payment_Form if it's a credit card
   * Not a static function, because I need to check for payment_type.
   *
   * @param array $values
   * @param array $errors
   */
  public function validatePaymentInstrument($values, &$errors) {
    // Use $_POST here and not $values - for webform fields are not set in $values, but are in $_POST
    CRM_Core_Form::validateMandatoryFields($this->getMandatoryFields(), $_POST, $errors);
    if ($this->_paymentProcessor['payment_type'] == 1) {
      // Don't validate credit card details as they are not passed (and stripe does this for us)
      //CRM_Core_Payment_Form::validateCreditCard($values, $errors, $this->_paymentProcessor['id']);
    }
  }

  /**
   * Process incoming notification.
   */

  static public function handlePaymentNotification() {
    $data_raw = file_get_contents("php://input");
    $data = json_decode($data_raw);
    $ipnClass = new CRM_Core_Payment_StripeIPN($data);
    $ipnClass->main();
  }
}

