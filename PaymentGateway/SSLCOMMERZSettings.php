<?php

namespace FluentFormSSLCOMMERZ\PaymentGateway;

use FluentForm\Framework\Helpers\ArrayHelper;
use FluentFormPro\Payments\PaymentHelper;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

class SSLCOMMERZSettings {
    
    public static function getSettings()
    {
        $defaults = [
            'is_active' => 'no',
            'payment_mode' => 'test',
            'checkout_type' => 'modal',
            'store_id' => '',
            'store_password' => '',
            'notifications' => []
        ];

        return wp_parse_args(get_option('fluentform_payment_settings_sslcommerz', []), $defaults);
    }

    public static function isLive($formId = false)
    {
        $settings = self::getSettings();
        return $settings['payment_mode'] == 'live';
    }

    public static function getSSLCommerzEndpoint()
    {
        $isLive = self::isLive();

        if ($isLive) {
            $sslcommerz_uri = 'https://securepay.sslcommerz.com';
        } else {
            $sslcommerz_uri = 'https://sandbox.sslcommerz.com';
        }

        return $sslcommerz_uri;
    }

    public static function getApiKey($formId = false)
    {
        $settings = self::getSettings();

        return [
            'store_id' => $settings['store_id'],
            'store_passwd' => $settings['store_password'],
        ];
    }
}