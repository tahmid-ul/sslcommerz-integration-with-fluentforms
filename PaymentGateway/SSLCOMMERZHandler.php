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
        add_filter('fluentform_payment_settings_'.$this->key, function () {
            return SSLCOMMERZSettings::getSettings();
        });

        add_filter('fluentform_payment_method_settings_validation_'.$this->key, array($this, 'validateSettings'), 10, 2);

        if(!$this->isEnabled()) {
            return;
        }

        add_filter('fluentform_transaction_data_' . $this->key, array($this, 'modifyTransaction'), 10, 1);

        add_filter('fluentformpro_available_payment_methods', [$this, 'pushPaymentMethodToForm']);

	    (new API())->init();
        (new SSLCOMMERZProcessor())->init();
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
                    'checkbox_label' => __('Enable SSLCOMMERZ Payment Method', 'SSLCOMMERZ')
                ],
                [
                    'settings_key' => 'payment_mode',
                    'type' => 'input-radio',
                    'label' => 'Payment Mode',
                    'options' => [
                        'test' => __('Sandbox Mode', 'SSLCOMMERZ'),
                        'live' => __('Live Mode', 'SSLCOMMERZ')
                    ],
                    'info_help' => __('Select the payment mode. for testing purposes you should select Sandbox Mode otherwise select Live mode.', 'SSLCOMMERZ'),
                    'check_status' => 'yes'
                ],
                [
                    'settings_key' => 'checkout_type',
                    'type' => 'input-radio',
                    'label' => 'Checkout Style Type',
                    'options' => [
                        'modal' => __('Modal Checkout Style', 'SSLCOMMERZ'),
                        'hosted' => __('Hosted to SSLCOMMERZ', 'SSLCOMMERZ')
                    ],
                    'info_help' => __('Select which type of checkout style you want.', 'SSLCOMMERZ'),
                    'check_status' => 'yes'
                ],
                [
                    'type' => 'html',
                    'html' => __('<h2>Your SSLCommerz Credentials</h2>', 'SSLCOMMERZ')
                ],
                [
                    'settings_key' => 'store_id',
                    'type' => 'input-text',
                    'data_type' => 'text',
                    'placeholder' => __('Store Id', 'SSLCOMMERZ'),
                    'label' => __('Store Id', 'SSLCOMMERZ'),
                    'inline_help' => __('Provide your SSLCommerz Store Id', 'SSLCOMMERZ'),
                    'check_status' => 'yes'
                ],
                [
                    'settings_key' => 'store_password',
                    'type' => 'input-text',
                    'data_type' => 'password',
                    'placeholder' => __('Store Password', 'SSLCOMMERZ'),
                    'label' => __('Store Password', 'SSLCOMMERZ'),
                    'inline_help' => __('Provide your SSLCommerz Store Password', 'SSLCOMMERZ'),
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