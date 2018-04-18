/**
 * @file
 * JS Integration between CiviCRM & Stripe.
 */
(function($, CRM) {

  // Response from Stripe.createToken.
  function stripeResponseHandler(status, response) {
    $form = getBillingForm();
    $submit = getBillingSubmit();

    if (response.error) {
      $('html, body').animate({scrollTop: 0}, 300);
      // Show the errors on the form.
      if ($(".messages.crm-error.stripe-message").length > 0) {
        $(".messages.crm-error.stripe-message").slideUp();
        $(".messages.crm-error.stripe-message:first").remove();
      }
      $form.prepend('<div class="messages alert alert-block alert-danger error crm-error stripe-message">'
        + '<strong>Payment Error Response:</strong>'
        + '<ul id="errorList">'
        + '<li>Error: ' + response.error.message + '</li>'
        + '</ul>'
        + '</div>');

      removeCCDetails($form, true);
      $form.data('submitted', false);
      $submit.prop('disabled', false);
    }
    else {
      var token = response['id'];
      // Update form with the token & submit.
      removeCCDetails($form, false);
      // We use the credit_card_number field to pass token as this is reliable.
      // Inserting an input field is unreliable on ajax forms and often gets missed from POST request for some reason.
      $form.find("input#stripe-token").val(token);

      // Disable unload event handler
      window.onbeforeunload = null;

      // Restore any onclickAction that was removed.
      $submit.attr('onclick', onclickAction);

      // This triggers submit without generating a submit event (so we don't run submit handler again)
      $form.get(0).submit();
    }
  }

  // Prepare the form.
  var onclickAction = null;
  $(document).ready(function() {
    loadStripeBillingBlock();
    $submit = getBillingSubmit();

    // Store and remove any onclick Action currently assigned to the form.
    // We will re-add it if the transaction goes through.
    onclickAction = $submit.attr('onclick');
    $submit.removeAttr('onclick');
  });

  // Re-prep form when we've loaded a new payproc
  $( document ).ajaxComplete(function( event, xhr, settings ) {
    // /civicrm/payment/form? occurs when a payproc is selected on page
    // /civicrm/contact/view/participant occurs when payproc is first loaded on event credit card payment
    if ((settings.url.match("/civicrm/payment/form?"))
       || (settings.url.match("/civicrm/contact/view/participant?"))) {
      // See if there is a payment processor selector on this form
      // (e.g. an offline credit card contribution page).
      if ($('#payment_processor_id').length > 0) {
        // There is. Check if the selected payment processor is different
        // from the one we think we should be using.
        var ppid = $('#payment_processor_id').val();
        if (ppid != $('#stripe-id').val()) {
          debugging('payment processor changed to id: ' + ppid);
          // It is! See if the new payment processor is also a Stripe
          // Payment processor. First, find out what the stripe
          // payment processor type id is (we don't want to update
          // the stripe pub key with a value from another payment processor).
          CRM.api3('PaymentProcessorType', 'getvalue', {
            "sequential": 1,
            "return": "id",
            "name": "Stripe"
          }).done(function(result) {
            // Now, see if the new payment processor id is a stripe
            // payment processor.
            var stripe_pp_type_id = result['result'];
            CRM.api3('PaymentProcessor', 'getvalue', {
              "sequential": 1,
              "return": "password",
              "id": ppid,
              "payment_processor_type_id": stripe_pp_type_id,
            }).done(function(result) {
              var pub_key = result['result'];
              if (pub_key) {
                // It is a stripe payment processor, so update the key.
                debugging("Setting new stripe key to: " + pub_key);
                $('#stripe-pub-key').val(pub_key);
                // Now reload the billing block.
                loadStripeBillingBlock();
              }
              else {
                debugging("New payment processor is not Stripe");
              }
            });
          });
        }
      }
      else {
        loadStripeBillingBlock();
      }
    }
  });

  function loadStripeBillingBlock() {
    var $stripePubKey = $('#stripe-pub-key');
    if ($stripePubKey.length) {
      if (!$().Stripe) {
        $.getScript('https://js.stripe.com/v2/', function () {
          Stripe.setPublishableKey($('#stripe-pub-key').val());
        });
      }
    }

    // Get the form containing payment details
    $form = getBillingForm();
    if (!$form.length) {
      debugging('No billing form!');
      return;
    }
    $submit = getBillingSubmit();

    // If another submit button on the form is pressed (eg. apply discount)
    //  add a flag that we can set to stop payment submission
    $form.data('submit-dont-process', '0');
    // Find submit buttons which should not submit payment
    $form.find('[type="submit"][formnovalidate="1"], ' +
      '[type="submit"][formnovalidate="formnovalidate"], ' +
      '[type="submit"].cancel, ' +
      '[type="submit"].webform-previous').click( function() {
      debugging('adding submit-dont-process');
      $form.data('submit-dont-process', 1);
    });

    $submit.click( function() {
      debugging('clearing submit-dont-process');
      $form.data('submit-dont-process', 0);
    });

    // Add a keypress handler to set flag if enter is pressed
    $form.find('input#discountcode').keypress( function(e) {
      if (e.which === 13) {
        $form.data('submit-dont-process', 1);
      }
    });

    var isWebform = getIsWebform();

    // For CiviCRM Webforms.
    if (isWebform) {
      // We need the action field for back/submit to work and redirect properly after submission
      if (!($('#action').length)) {
        $form.append($('<input type="hidden" name="op" id="action" />'));
      }
      var $actions = $form.find('[type=submit]');
      $('[type=submit]').click(function() {
        $('#action').val(this.value);
      });
      // If enter pressed, use our submit function
      $form.keypress(function(event) {
        if (event.which === 13) {
          $('#action').val(this.value);
          submit(event);
        }
      });
      $('#billingcheckbox:input').hide();
      $('label[for="billingcheckbox"]').hide();
    }
    else {
      // As we use credit_card_number to pass token, make sure it is empty when shown
      $form.find("input#credit_card_number").val('');
      $form.find("input#cvv2").val('');
    }

    // Intercept form submission.
    $form.on('submit', function(event) {
      var ret = submit(event);
      if (ret) {
        // True means it's not our form. We are bailing and not trying to
        // process Stripe.
        // Restore any onclickAction that was removed.
        $form = getBillingForm();
        $submit = getBillingSubmit();
        $submit.attr('onclick', onclickAction);
        $form.get(0).submit();
        return true;
      }
      // Otherwise, this is a stripe submission - don't handle normally.
      return false;
    });

    function submit(event) {
      event.preventDefault();
      debugging('submit handler');

      if ($form.data('submitted') === true) {
        debugging('form already submitted');
        return false;
      }

      // Handle multiple payment options and Stripe not being chosen.
      if ($form.find(".crm-section.payment_processor-section").length > 0) {
        var extMode = $('#ext-mode').val();
        var stripeProcessorId = $('#stripe-id').val();
        var chosenProcessorId = $form.find('input[name="payment_processor_id"]:checked').val();

        // Bail if we're not using Stripe or are using pay later (option value '0' in payment_processor radio group).
        if ((chosenProcessorId !== stripeProcessorId) || (chosenProcessorId === 0)) {
          debugging('debug: Not a Stripe transaction, or pay-later');
          return true;
        }
      }
      else {
        debugging('debug: Stripe is the selected payprocessor');
      }

      $form = getBillingForm();

      // Don't handle submits generated by non-stripe processors
      if (!$('input#stripe-pub-key').length) {
        debugging('submit missing stripe-pub-key element');
        return true;
      }
      // Don't handle submits generated by the CiviDiscount button.
      if ($form.data('submit-dont-process')) {
        debugging('non-payment submit detected - not submitting payment');
        return true;
      }

      $submit = getBillingSubmit();
      var isWebform = getIsWebform();

      if (isWebform) {
        var $processorFields = $('.civicrm-enabled[name$="civicrm_1_contribution_1_contribution_payment_processor_id]"]');

        $totalElement = $('#wf-crm-billing-total');
        if ($totalElement.length) {
          if ($totalElement.data('data-amount') === '0') {
            debugging('webform total is 0');
            return true;
          }
        }
        if ($processorFields.length) {
          if ($processorFields.filter(':checked').val() === '0') {
            debugging('no payment processor selected');
            return true;
          }
        }
      }

      // If there's no credit card field, no use in continuing (probably wrong
      // context anyway)
      if (!$form.find('#credit_card_number').length) {
        debugging('debug: No credit card field');
        return true;
      }
      // Lock to prevent multiple submissions
      if ($form.data('submitted') === true) {
        // Previously submitted - don't submit again
        alert('Form already submitted. Please wait.');
        return false;
      } else {
        // Mark it so that the next submit can be ignored
        // ADDED requirement that form be valid
        if($form.valid()) {
          $form.data('submitted', true);
        }
      }

      // Disable the submit button to prevent repeated clicks
      $submit.prop('disabled', true);

      var cc_month = $form.find('#credit_card_exp_date_M').val();
      var cc_year = $form.find('#credit_card_exp_date_Y').val();

      Stripe.card.createToken({
        name: $form.find('#billing_first_name')
          .val() + ' ' + $form.find('#billing_last_name').val(),
        address_zip: $form.find('#billing_postal_code-5').val(),
        number: $form.find('#credit_card_number').val(),
        cvc: $form.find('#cvv2').val(),
        exp_month: cc_month,
        exp_year: cc_year
      }, stripeResponseHandler);
      debugging('debug: Getting Stripe token');
      return false;
    }
  }

  function getIsWebform() {
    return $('.webform-client-form').length;
  }

  function getBillingForm() {
    return $('input#stripe-pub-key').closest('form');
  }

  function getBillingSubmit() {
    $form = getBillingForm();
    var isWebform = getIsWebform();

    if (isWebform) {
      $submit = $form.find('[type="submit"].webform-submit');
    }
    else {
      $submit = $form.find('[type="submit"].validate');
    }
    return $submit;
  }

  function removeCCDetails($form, $truncate) {
    // Remove the "name" attribute so params are not submitted
    var ccNumElement = $form.find("input#credit_card_number");
    var cvv2Element = $form.find("input#cvv2");
    if ($truncate) {
      ccNumElement.val('');
      cvv2Element.val('');
    }
    else {
      var last4digits = ccNumElement.val().substr(12, 16);
      ccNumElement.val('000000000000' + last4digits);
      cvv2Element.val('000');
    }
  }

  function debugging (errorCode) {
    // Uncomment the following to debug unexpected returns.
    console.log(errorCode);
  }

}(cj, CRM));
