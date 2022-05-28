<?php

namespace FluentFormSSLCOMMERZ\PaymentGateway;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

use FluentForm\Framework\Helpers\ArrayHelper;

class API
{
	public function init()
	{
		// normal onetime payment process
		add_action('fluentform_ipn_sslcommerz_action_paid', array($this, 'updatePaymentStatusFromIPN'), 10, 3);
	}

    public function verifyIPN()
    {
        // Check if the request method is post
        if(isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
            return;
        }

        // Set initial post data to empty string
        $post_data = '';

        // Fallback just in case post_max_size is lower than needed
        if (ini_get('allow_url_fopen')) {
            $post_data = file_get_contents('php://input');
        } else {
            // If allow_url_fopen is not enabled, then make sure that post_max_size is large enough
            ini_set('post_max_size', '12M');
        }

        // Start the encoded data collection with notification command
        $encoded_data = 'cmd=_notify-validate';

        // Get current arg separator
        $arg_separator = ini_get('arg_separator.output');

        // Verify there is a post_data
        if ($post_data || strlen($post_data) > 0) {
            // Append the data
            $encoded_data .= $arg_separator . $post_data;
        } else {
            // Check if POST is empty
            if (empty($_POST)) {
                // Nothing to do
                return;
            } else {
                // Loop through each POST
                foreach ($_POST as $key => $value) {
                    // Encode the value and append the data
                    $encoded_data .= $arg_separator . "$key=" . urlencode($value);
                }
            }
        }

        // Convert collected post data to an array
        parse_str($encoded_data, $encoded_data_array);

        foreach ($encoded_data_array as $key => $value) {
            if (false !== strpos($key, 'amp;')) {
                $new_key = str_replace('&amp;', '&', $key);
                $new_key = str_replace('amp;', '&', $new_key);
                unset($encoded_data_array[$key]);
                $encoded_data_array[$new_key] = $value;
            }
        }

        $defaults = $_REQUEST;
        $encoded_data_array = wp_parse_args($encoded_data_array, $defaults);

        $this->handleIpn($encoded_data_array);
        exit(200);
    }

    protected function handleIpn($data)
    {
        $submissionId = intval(ArrayHelper::get($data, 'fluentform_payment'));

        if (!$submissionId || empty($data['tran_id'])) {
            return;
        }
        $submission = wpFluent()->table('fluentform_submissions')->where('id', $submissionId)->first();
        if (!$submission) {
            return;
        }

        $validationArgs = array(
            'val_id' => $data['val_id'],
        );

        $validateTransaction = $this->makeApiCall($validationArgs, $submission->form_id, 'GET');

        if(is_wp_error($validateTransaction)) {
            do_action('ff_log_data', [
                'parent_source_id' => $submission->form_id,
                'source_type'      => 'submission_item',
                'source_id'        => $submission->id,
                'component'        => 'Payment',
                'status'           => 'error',
                'title'            => 'SSLCommerz Payment Webhook Error',
                'description'      => $validateTransaction->get_error_message()
            ]);
            return false;
        }

        $status = $validateTransaction['status'];

        if ($status == 'VALID' || $status = 'VALIDATED') {
            $status = 'paid';
        }

        do_action('fluentform_ipn_sslcommerz_action_'.$status, $submission, $validateTransaction, $data);

    }

	public function updatePaymentStatusFromIPN($data, $submissionId, $submission)
	{
		(new SSLCOMMERZProcessor())->handlePaid($data, $submissionId);
	}

    public function makeApiCall($args, $formId, $method = 'GET')
    {
        $storeCredentials = SSLCOMMERZSettings::getApiKey($formId);

        $paymentData = wp_parse_args($args, $storeCredentials);

        $api_url = SSLCOMMERZSettings::getSSLCommerzEndpoint();
        // For Sandbox - "https://sandbox.sslcommerz.com"
        // For Live - "https://securepay.sslcommerz.com"
        
        if($method == 'POST') {
            $response = wp_remote_post($api_url . '/gwprocess/v4/api.php', [
                'headers' => array(),
                'body' => $paymentData
            ]);
        } else {
            $response = wp_remote_get($api_url . '/validator/api/validationserverAPI.php', [
                'headers' => array(),
                'body' => $paymentData
            ]);
        }
        
        if(is_wp_error($response)) {
            return $response;
        }

        $body = wp_remote_retrieve_body($response);
        $responseData = json_decode($body, true);

        if($responseData['status'] == 'FAILED') {
            $message = ArrayHelper::get($responseData, 'failedreason');
            if(!$message) {
                $message = 'Unknown SSLCommerz API request error';
            }

            return new \WP_Error(423, $message, $responseData);
        }

        return $responseData;
    }
}