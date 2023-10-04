<?php

namespace FluentFormSSLCOMMERZ\PaymentGateway;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

use FluentForm\Framework\Helpers\ArrayHelper;
use FluentFormPro\Payments\PaymentMethods\BasePaymentMethod;

class SSLCOMMERZHandler extends BasePaymentMethod
{

    protected $key = 'sslcommerz';

    public function __construct()
    {
        parent::__construct('sslcommerz');
    }

    public function init()
    {
        add_filter('fluentform/payment_settings_'.$this->key, function () {
            return SSLCOMMERZSettings::getSettings();
        });

        add_filter('fluentform/payment_method_settings_validation_'.$this->key, array($this, 'validateSettings'), 10, 2);

        if(!$this->isEnabled()) {
            return;
        }

        add_filter('fluentform/transaction_data_' . $this->key, array($this, 'modifyTransaction'), 10, 1);

        add_filter('fluentform/available_payment_methods', [$this, 'pushPaymentMethodToForm']);


	    (new API())->init();
        (new SSLCOMMERZProcessor())->init();
    }

	public function modifyTransaction($transaction)
	{
		return $transaction;
	}

    public function pushPaymentMethodToForm($methods)
    {
        $methods[$this->key] = [
            'title' => __('SSLCOMMERZ', 'SSLCOMMERZ'),
            'enabled' => 'yes',
            'method_value' => $this->key,
            'settings' => [
                'option_label' => [
                    'type' => 'text',
                    'template' => 'inputText',
                    'value' => 'Pay with SSLCOMMERZ',
                    'label' => 'Method Label'
                ]
            ]
        ];

        return $methods;
    }

    public function validateSettings($errors, $settings)
    {
        if(ArrayHelper::get($settings, 'is_active') == 'no') {
            return [];
        }

        $mode = ArrayHelper::get($settings, 'payment_mode');
        if(!$mode) {
            $errors['payment_mode'] = __('Please select Payment Mode', 'SSLCOMMERZ');
        }

        if(!ArrayHelper::get($settings, 'store_id')) {
            $errors['sslcommerz_store_id'] = __('Please provide store id', 'SSLCOMMERZ');
        }

        if(!ArrayHelper::get($settings, 'store_password')) {
            $errors['sslcommerz_store_password'] = __('Please provide store password', 'SSLCOMMERZ');
        }

        return $errors;
    }

    public function isEnabled()
    {
        $settings = $this->getGlobalSettings();
        return $settings['is_active'] == 'yes';
    }

    public function getGlobalFields()
    {
        
        return [
            'label' => 'SSLCOMMERZ',
            'fields' => [
                [
                    'settings_key' => 'is_active',
                    'type' => 'yes-no-checkbox',
                    'label' => 'Status',
                    'checkbox_label' => 'Enable SSLCOMMERZ Payment Method'
                ],
                [
                    'settings_key' => 'payment_mode',
                    'type' => 'input-radio',
                    'label' => 'Payment Mode',
                    'options' => [
                        'test' => 'Sandbox Mode',
                        'live' => 'Live Mode'
                    ],
                    'info_help' => 'Select the payment mode. for testing purposes you should select Sandbox Mode otherwise select Live mode.',
                    'check_status' => 'yes'
                ],
                [
                    'settings_key' => 'checkout_type',
                    'type' => 'input-radio',
                    'label' => 'Checkout Style Type',
                    'options' => [
                        'modal' => 'Modal Checkout Style',
                        'hosted' => 'Hosted to SSLCOMMERZ'
                    ],
                    'info_help' => 'Select which type of checkout style you want.',
                    'check_status' => 'yes'
                ],
                [
                    'type' => 'html',
                    'html' => '<h2>Your SSLCommerz Credentials</h2>'
                ],
                [
                    'settings_key' => 'store_id',
                    'type' => 'input-text',
                    'data_type' => 'text',
                    'placeholder' => 'Store Id',
                    'label' => 'Store Id',
                    'inline_help' => 'Provide your SSLCommerz Store Id',
                    'check_status' => 'yes'
                ],
                [
                    'settings_key' => 'store_password',
                    'type' => 'input-text',
                    'data_type' => 'password',
                    'placeholder' => 'Store Password',
                    'label' => 'Store Password',
                    'inline_help' => 'Provide your SSLCommerz Store Password',
                    'check_status' => 'yes'
                ],
            ]
        ];
    }

    public function getGlobalSettings()
    {
        return SSLCOMMERZSettings::getSettings();
    }

}