<?php
/**
 * Plugin Name: SSLCOMMERZ Integration with Fluent Forms
 * Plugin URI: https://github.com/tahmid-ul/sslcommerz-integration-with-fluentforms
 * Description: Connect Fluent Forms with SSLCOMMERZ paymeent gateway.
 * Version: 1.0.0
 * Author: Tahmid ul Karim
 * Author URI:  https://profiles.wordpress.org/tahmidulkarim/
 * License:           GPL v2 or later
 * Text Domain: SSLCOMMERZ
 */

/**
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
 *
 */

defined('ABSPATH') or die;
define('FFSSLCOMMERZ_DIR', plugin_dir_path(__FILE__));
define('FFSSLCOMMERZ_URL', plugin_dir_url(__FILE__));

class FluentFormSSLCOMMERZ {

    public function boot() {

        if(!defined('FLUENTFORMPRO')) {
            return $this->injectDependency();
        }

        $this->includeFiles();

        if (function_exists('wpFluentForm')) {
            return $this->registerHooks();
        }

    }

    protected function includeFiles()
    {
        include_once FFSSLCOMMERZ_DIR . 'PaymentGateway/SSLCOMMERZHandler.php';
        include_once FFSSLCOMMERZ_DIR . 'PaymentGateway/SSLCOMMERZSettings.php';
        include_once FFSSLCOMMERZ_DIR . 'PaymentGateway/SSLCOMMERZProcessor.php';
        include_once FFSSLCOMMERZ_DIR . 'PaymentGateway/API.php';
    }

    protected function registerHooks()
    {
       (new \FluentFormSSLCOMMERZ\PaymentGateway\SSLCOMMERZHandler())->init();
    }

    /**
    * Notify the user about the FluentForm dependency and instructs to install it.
    */
    protected function injectDependency() {
        add_action('admin_notices', function () {
            $pluginInfo = $this->getFluentFormInstallationDetails();

            $class = 'notice notice-error';

            $install_url_text = 'Click Here to Get the Plugin';

            if ($pluginInfo->action == 'activate') {
                $install_url_text = 'Click Here to Activate the Plugin';
            }

            $message = 'FluentForm SSLCOMMERZ Add-On Requires FluentForm Pro Plugin, <b><a href="' . $pluginInfo->url
                    . '">' . $install_url_text . '</a></b>';

            printf('<div class="%1$s"><p>%2$s</p></div>', esc_attr($class), $message);
        });
    }
    
    /**
    * Get the FluentForm plugin installation information e.g. the URL to install.
    *
    * @return \stdClass $activation
    */
    protected function getFluentFormInstallationDetails() {
        $activation = (object)[
            'action' => 'install',
            'url' => ''
        ];

        $allPlugins = get_plugins();

        if(isset($allPlugins['fluentformpro/fluentformpro.php'])) {
            $url = wp_nonce_url(
                self_admin_url('plugins.php?action=activate&plugin=fluentformpro/fluentformpro.php'),
                'activate-plugin_fluentformpro/fluentformpro.php'
            );

            $activation->action = 'activate';
        } else {
            $url = 'http://fluentforms.com/';
        }

        $activation->url = $url;

        return $activation;
    }

}

add_action('plugins_loaded', function() {
    (new FluentFormSSLCOMMERZ()) -> boot();
});

