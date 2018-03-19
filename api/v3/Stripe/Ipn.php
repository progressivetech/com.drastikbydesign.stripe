<?php

/**
 * This api allows you to replay Stripe events. 
 *
 * You can either pass the id of an entry in the System Log (which can
 * be populated with the Stripe.PopulateLog call) or you can pass a
 * event id from Stripe directly.
 *
 * When processing an event, the event will always be re-fetched from the
 * Stripe server first, so this will not work while offline or with 
 * events that were not generated by the Stripe server.
 */ 

/**
 * Stripe.Ipn API specification
 *
 * @param array $spec description of fields supported by this API call
 * @return void
 * @see http://wiki.civicrm.org/confluence/display/CRMDOC/API+Architecture+Standards
 */
function _civicrm_api3_stripe_Ipn_spec(&$spec) {
  $spec['id']['title'] = ts("CiviCRM System Log id to replay from system log.");
  $spec['evtid']['title'] = ts("An event id as generated by Stripe.");
  $spec['ppid']['title'] = ts("The payment processor to use (required if using evtid)");
  $spec['noreceipt']['title'] = ts("Set to 1 to override contribution page settings and do not send a receipt (default is off or 0). )");
  $spec['noreceipt']['api.default'] = 0;
}

/**
 * Stripe.Ipn API
 *
 * @param array $params
 * @return array API result descriptor
 * @see civicrm_api3_create_success
 * @see civicrm_api3_create_error
 * @throws API_Exception
 */
function civicrm_api3_stripe_Ipn($params) {
  $object = NULL;
  $ppid = NULL;
  if (array_key_exists('id', $params)) {
    $data = civicrm_api3('SystemLog', 'getsingle', array('id' => $params['id'], 'return' => array('message', 'context')));
    if (empty($data)) {
      throw new API_Exception('Failed to find that entry in the system log', 3234);
    }
    $object = json_decode($data['context']);
    if (preg_match('/processor_id=([0-9]+)$/', $object['message'], $matches)) {
      $ppid = $matches[1];
    }
    else {
      throw new API_Exception('Failed to find payment processor id in system log', 3235);
    }
  }
  elseif (array_key_exists('evtid', $params)) {
    if (!array_key_exists('ppid', $params)) {
      throw new API_Exception('Please pass the payment processor id (ppid) if using evtid.', 3236);
    }
    $ppid = $params['ppid'];
    $results = civicrm_api3('PaymentProcessor', 'getsingle', array('id' => $ppid));
    // YES! I know, password and user are backwards. wtf??
    $sk = $results['user_name'];

    require_once ("vendor/stripe/stripe-php/init.php");
    \Stripe\Stripe::setApiKey($sk);
    $object = \Stripe\Event::retrieve($params['evtid']);
  }
  // Avoid a SQL error if this one has been processed already.
  $sql = "SELECT COUNT(*) AS count FROM civicrm_contribution WHERE trxn_id = %0";
  $sql_params = array(0 => array($object->data->object->charge, 'String'));
  $dao = CRM_Core_DAO::executeQuery($sql, $sql_params);
  $dao->fetch();
  if ($dao->count > 0) {
    return civicrm_api3_create_error("Ipn already processed.");
  }
  if (class_exists('CRM_Core_Payment_StripeIPN')) {
    // The $_GET['processor_id'] value is normally set by 
    // CRM_Core_Payment::handlePaymentMethod
    $_GET['processor_id'] = $ppid;
    $ipnClass = new CRM_Core_Payment_StripeIPN($object);
    if ($params['noreceipt'] == 1) {
      $ipnClass->is_email_receipt = 0;
    }
    $ipnClass->main();
  }
  else {
    trigger_error("The api depends on CRM_Core_Payment_StripeIPN");
  }
  return civicrm_api3_create_success(array());

}
