<?php

namespace FluentFormSSLCOMMERZ\PaymentGateway;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

use FluentForm\App\Helpers\Helper;
use FluentForm\App\Modules\Form\FormFieldsParser;
use FluentForm\Framework\Helpers\ArrayHelper;
use FluentFormPro\Payments\PaymentHelper;
use FluentFormPro\Payments\PaymentMethods\BaseProcessor;
use FluentFormPro\Payments\PaymentMethods\PayPal\API\IPN;

class SSLCOMMERZProcessor extends BaseProcessor
{
    public $method = 'sslcommerz';

    protected $form;

    public function init() 
    {
        add_action('fluentform_process_payment_' . $this->method, array($this, 'handlePaymentAction'), 10, 6);
        add_action('fluent_payment_frameless_' . $this->method, array($this, 'handleSessionRedirectBack'));

        add_action('fluentform_ipn_endpoint_' . $this->method, function () {
            (new API())->verifyIPN();
            exit(200);
        });

        add_action('fluentform_ipn_sslcommerz_action_paid', array($this, 'handlePaid'), 10, 2);
        //add_action('fluentform_ipn_sslcommerz_action_refunded', array($this, 'handleRefund'), 10, 3);

        add_filter('fluentform_submitted_payment_items_' . $this->method, [$this, 'validateSubmittedItems'], 10, 4);

        add_action('fluentform_rendering_payment_method_' . $this->method, array($this, 'addCheckoutJs'), 10, 3);



        add_action('wp_ajax_fluentform_sslcommerz_confirm_payment', array($this, 'confirmModalPayment'));
        add_action('wp_ajax_nopriv_fluentform_sslcommerz_confirm_payment', array($this, 'confirmModalPayment'));

	    //add_action('fluentform_render_item_submit_button', array($this, 'customModalParameters'), 10, 2);
    }

    public function handlePaymentAction($submissionId, $submissionData, $form, $methodSettings, $hasSubscriptions, $totalPayable) 
    {
        $this->setSubmissionId($submissionId);
        $this->form = $form;
        $submission = $this->getSubmission();

        $uniqueHash = md5($submission->id . '-' . $form->id . '-' . time() . '-' . mt_rand(100, 999));

        $transactionId = $this->insertTransaction([
            'transaction_type' => 'onetime',
            'transaction_hash' => $uniqueHash,
            'payment_total'    => $this->getAmountTotal(),
            'status'           => 'pending',
            'currency'         => PaymentHelper::getFormCurrency($form->id),
            'payment_mode'     => $this->getPaymentMode()
        ]);

        $transaction = $this->getTransaction($transactionId);

        $this->maybeShowModal($transaction, $submission, $form, $methodSettings);
        $this->handleRedirect($transaction, $submission, $form, $methodSettings);
    }

    public function handleRedirect($transaction, $submission, $form, $methodSettings)
    {
        //$globalSettings = SSLCOMMERZSettings::getSettings();

        $successUrl = add_query_arg(array(
            'fluentform_payment' => $submission->id,
            'payment_method'     => $this->method,
            'transaction_hash'   => $transaction->transaction_hash,
            'type'               => 'success'
        ), site_url('/'));

        $cancelUrl = add_query_arg(array(
            'fluentform_payment' => $submission->id,
            'payment_method'     => $this->method,
            'transaction_hash'   => $transaction->transaction_hash,
            'type'               => 'cancel'
        ), site_url('/'));

        $failedURL = add_query_arg(array(
            'fluentform_payment' => $submission->id,
            'payment_method'     => $this->method,
            'transaction_hash'   => $transaction->transaction_hash,
            'type'               => 'failed'
        ), site_url('/'));


        $siteDomain = site_url('/');

        if(defined('FLUENTFORM_SSLCOMERZ_IPN_DOMAIN') && FLUENTFORM_SSLCOMERZ_IPN_DOMAIN) {
	        $siteDomain = FLUENTFORM_SSLCOMERZ_IPN_DOMAIN;
        }

        $listener_url = add_query_arg(array(
            'fluentform_payment_api_notify' => 1,
            'payment_method'                => $this->method,
            'submission_id'                 => $submission->id
        ), $siteDomain);


        $paymentArgs = array(
            'total_amount' => intval($transaction->payment_total),
            'currency' => strtoupper($transaction->currency),
            'tran_id' => $transaction->transaction_hash,
            'cus_name'=> $transaction->payer_name,
            'cus_email' => $transaction->payer_email,
            'cus_phone' => ' ',
            'shipping_method' => 'NO',
            'product_name' => ' ',
            'product_category' => 'fluent-forms',
            'product_profile'=> 'general',
            'success_url' => esc_url_raw($successUrl),
            'fail_url' => esc_url_raw($failedURL),
            'cancel_url' => esc_url_raw($cancelUrl),
            'ipn_url' => esc_url_raw($listener_url)
        );

        // add customer info to the payment args
	    $paymentArgs = wp_parse_args($paymentArgs, $this->getCustomerAddress($submission, $form));

        $paymentArgs = apply_filters('fluentform_sslcommerz_payment_args', $paymentArgs, $submission, $transaction, $form);
        // Initiate Payment
        $paymentIntent = (new API())->makeApiCall($paymentArgs, $form->id, 'POST');

        if (is_wp_error($paymentIntent)) {
            do_action('ff_log_data', [
                'parent_source_id' => $submission->form_id,
                'source_type'      => 'submission_item',
                'source_id'        => $submission->id,
                'component'        => 'Payment',
                'status'           => 'error',
                'title'            => 'SSLCommerz Payment Redirect Error',
                'description'      => $paymentIntent->get_error_message()
            ]);

            wp_send_json_success([
                'message' => $paymentIntent->get_error_message()
            ], 423);
        }

        Helper::setSubmissionMeta($submission->id, '_sslcommerz_payment_id', $paymentIntent['sessionkey']);

        do_action('ff_log_data', [
            'parent_source_id' => $submission->form_id,
            'source_type'      => 'submission_item',
            'source_id'        => $submission->id,
            'component'        => 'Payment',
            'status'           => 'info',
            'title'            => 'Redirect to SSLCommerz',
            'description'      => 'User redirect to SSLCommerz for completing the payment'
        ]);

        wp_send_json_success([
            'nextAction'   => 'payment',
            'actionName'   => 'normalRedirect',
            'redirect_url' => $paymentIntent['GatewayPageURL'],
            'message'      => __('You are redirecting to sslcommerz.com to complete the purchase. Please wait while you are redirecting....', 'fluentformpro'),
            'result'       => [
                'insert_id' => $submission->id
            ]
        ], 200);
    }


	private function getCustomerAddress($submission, $form)
	{

		$fullAddress = array(
			'cus_add1' => ' ',
			'cus_add2' => ' ',
			'cus_city' => ' ',
			'cus_state' => ' ',
			'cus_postcode' => ' ',
			'cus_country' =>  ' '
		);

		$addressAttribute = array_keys(FormFieldsParser::getInputsByElementTypes($form, ['address'], ['attributes']));
		$customerAddressField = $submission->response[$addressAttribute[0]];

		if (!empty($customerAddressField['address_line_1'])) {
			$fullAddress['cus_add1'] = sanitize_text_field($customerAddressField['address_line_1']);
		}
		if (!empty($customerAddressField['address_line_2'])) {
			$fullAddress['cus_add2'] = sanitize_text_field($customerAddressField['address_line_2']);
		}
		if (!empty($customerAddressField['city'])) {
			$fullAddress['cus_city'] = sanitize_text_field($customerAddressField['city']);
		}
		if (!empty($customerAddressField['state'])) {
			$fullAddress['cus_state'] = sanitize_text_field($customerAddressField['state']);
		}
		if (!empty($customerAddressField['zip'])) {
			$fullAddress['cus_postcode'] = sanitize_text_field($customerAddressField['zip']);
		}
		if (!empty($customerAddressField['country'])) {
			$fullAddress['cus_country'] = sanitize_text_field($customerAddressField['country']);
		}

		return $fullAddress;
	}

    protected function getPaymentMode($formId = false)
    {
        $isLive = SSLCOMMERZSettings::isLive($formId);
        if ($isLive) {
            return 'live';
        }
        return 'test';
    }

    public function handleSessionRedirectBack($data)
    {
        $type = sanitize_text_field($data['type']);
        $submissionId = intval($data['fluentform_payment']);
        $this->setSubmissionId($submissionId);
        $submission = $this->getSubmission();

        $transactionHash = sanitize_text_field($data['transaction_hash']);
        $transaction = $this->getTransaction($transactionHash, 'transaction_hash');

        if (!$transaction || !$submission || $transaction->payment_method != $this->method) {
            return;
        }

        $isNew = false;
        $status = $_REQUEST['status'];

        if ($type == 'success' && $status == 'VALID') {
            $isNew = $this->getMetaData('is_form_action_fired') != 'yes';
            $returnData = $this->handlePaid($submission, $transaction);
        } else if ($type == 'success') {
            $transaction = $this->getLastTransaction($submission->id);
            $message = __('Looks like the payment is not marked as paid yet. Please reload this page after 1-2 minutes.', 'fluentformpro');

            $returnData = [
                'insert_id' => $submission->id,
                'title'     => __('Payment was not marked as Paid', 'fluentformpro'),
                'result'    => false,
                'error'     => $message
            ];
        } else if ($type == 'failed' && $status == 'FAILED') {
	        $this->changeSubmissionPaymentStatus('failed');
	        $returnData = [
		        'insert_id' => $submission->id,
		        'title'     => __('Payment Failed', 'fluentformpro'),
		        'result'    => false,
		        'error'     => __('Looks like the payment failed to process', 'fluentformpro')
	        ];
        } else {
	        $this->changeSubmissionPaymentStatus('cancelled');
	        $returnData = [
		        'insert_id' => $submission->id,
		        'title'     => __('Payment Cancelled', 'fluentformpro'),
		        'result'    => false,
		        'error'     => __('Looks like you have cancelled the payment', 'fluentformpro')
	        ];
        }

        $returnData['type'] = $type;
        $returnData['is_new'] = $isNew;

        $this->showPaymentView($returnData);

    }
    

    public function handlePaid($submission, $transaction)
    {
        $this->setSubmissionId($submission->id);
        //$transaction = $this->getLastTransaction($submission->id);

        if (!$transaction || $transaction->payment_method != $this->method) {
            return;
        }

        // Check if actions are fired
        if ($this->getMetaData('is_form_action_fired') == 'yes') {
            return $this->completePaymentSubmission(false);
        }

        $status = 'paid';

        // Let's make the payment as paid
        $updateData = [
            'payment_note'  => maybe_serialize($transaction),
            'charge_id'     => sanitize_text_field($transaction->transaction_hash),
            'payer_email'   => $transaction->payer_email,
            'payment_total' => intval($transaction->payment_total)
        ];

        $this->updateTransaction($transaction->id, $updateData);
        $this->changeSubmissionPaymentStatus($status);
        $this->changeTransactionStatus($transaction->id, $status);
        $this->recalculatePaidTotal();
        $returnData = $this->getReturnData();
        $this->setMetaData('is_form_action_fired', 'yes');
        return $returnData;

    }

    public function handleRefund($refundAmount, $submission, $vendorTransaction)
    {

    }

    public function validateSubmittedItems($paymentItems, $form, $formData, $subscriptionItems)
    {
        if (count($subscriptionItems)) {
            wp_send_json([
                'errors' => __('SSLCommerz Error: SSLCommerz does not support subscriptions right now!', 'fluentformpro')
            ], 423);
        }
    }

    public function addCheckoutJs($methodElement, $element, $form)
    {
	    $settings = SSLCOMMERZSettings::getSettings();
	    if($settings['checkout_type'] != 'modal') {
		    return;
	    }

	    wp_enqueue_script('ff_sslcommerz_handler', FFSSLCOMMERZ_URL . 'js/sslcommerz_handler.js', ['jquery'], FLUENTFORMPRO_VERSION);
    }

    public function maybeShowModal($transaction, $submission, $form, $methodSettings)
    {
	    $settings = SSLCOMMERZSettings::getSettings();
	    if($settings['checkout_type'] != 'modal') {
		    return;
	    }

	    $orderArgs = [
		    'total_amount' => intval($transaction->payment_total),
		    'currency' => strtoupper($transaction->currency),
		    'tran_id' => $transaction->transaction_hash,
		    'cus_name'=> $transaction->payer_name,
		    'cus_email' => $transaction->payer_email,
		    'cus_phone' => ' ',
		    'shipping_method' => 'NO',
		    'product_name' => ' ',
		    'product_category' => 'fluent-forms',
		    'product_profile'=> 'general'
	    ];

	    // add customer info to the payment args
	    $orderArgs = wp_parse_args($orderArgs, $this->getCustomerAddress($submission, $form));

	    //Initiate Payment
	    $order = (new API())->makeApiCall($orderArgs, $form->id, 'POST');

	    //dd($order);

	    if (is_wp_error($order)) {
		    $message = $order->get_error_message();
		    do_action('ff_log_data', [
			    'parent_source_id' => $submission->form_id,
			    'source_type'      => 'submission_item',
			    'source_id'        => $submission->id,
			    'component'        => 'Payment',
			    'status'           => 'error',
			    'title'            => 'SSLCOMMERZ Payment Error',
			    'description'      => $order->get_error_message()
		    ]);

		    wp_send_json([
			    'errors'      => 'SSLCOMMERZ Error: ' . $message,
			    'append_data' => [
				    '__entry_intermediate_hash' => Helper::getSubmissionMeta($submission->id, '__entry_intermediate_hash')
			    ]
		    ], 423);
	    }

//	    $this->updateTransaction($transaction->id, [
//		    'charge_id' => $order['id']
//	    ]);

	    $keys = SSLCOMMERZSettings::getApiKey($form->id);
	    $paymentSettings = PaymentHelper::getPaymentSettings();

	    //dd($paymentSettings);

	    $modalData = [
		    'amount'       => intval($transaction->payment_total),
		    'currency'     => strtoupper($transaction->currency),
		    'description'  => $form->title,
		    'reference_id' => $transaction->transaction_hash,
		    'order_id'     => $order['sessionkey'],
		    'name'         => $paymentSettings['business_name'],
		    //'key'          => $keys['store_id'],
		    'prefill'      => [
			    'email' => PaymentHelper::getCustomerEmail($submission)
		    ],
		    'theme'        => [
			    'color' => '#3399cc'
		    ]
	    ];

	    //dd($order);

	    do_action('ff_log_data', [
		    'parent_source_id' => $submission->form_id,
		    'source_type'      => 'submission_item',
		    'source_id'        => $submission->id,
		    'component'        => 'Payment',
		    'status'           => 'info',
		    'title'            => 'SSLCOMMERZ Modal is initiated',
		    'description'      => 'SSLCOMMERZ Modal is initiated to complete the payment'
	    ]);

	    # Tell the client to handle the action
	    wp_send_json_success([
		    'nextAction'       => 'payment',
		    'actionName'       => 'initSslcommerzModal',
		    'submission_id'    => $submission->id,
		    'postdata'       => $modalData,
		    'transaction_hash' => $transaction->transaction_hash,
		    'message'          => __('Payment Modal is opening, Please complete the payment', 'fluentformpro'),
		    'confirming_text'  => __('Confirming Payment, Please wait...', 'fluentformpro'),
		    'result'           => [
			    'insert_id' => $submission->id
		    ],
		    'append_data' => [
			    '__entry_intermediate_hash' => Helper::getSubmissionMeta($transaction->submission_id, '__entry_intermediate_hash')
		    ]
	    ], 200);

    }

    public function customModalParameters($item, $form)
    {
	    $settings = SSLCOMMERZSettings::getSettings();
	    if($settings['checkout_type'] != 'modal') {
		    return;
	    }

	    $website_url = site_url('/');

	    $item['attributes']['id'] = "sslczPayBtn";
	    $item['attributes']['token']=$order;
	    $item['attributes']['postdata']="";
	    $item['attributes']['order']=$order;
	    $item['attributes']['endpoint']= $website_url. "easyCheckout.php?v4checkout";
	    //array_push($item['attributes'], 'id');
    	//dd($item['attributes']);
	    //dd($item);
    }

    public function confirmModalPayment()
    {

    }

}