<?php

namespace MonerisPaymentForPaymattic\Settings;

use WPPayForm\Framework\Support\Arr;
use WPPayForm\App\Services\AccessControl;
use WPPayFormPro\GateWays\BasePaymentMethod;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

class MonerisSettings extends BasePaymentMethod
{
    public function __construct()
    {
        parent::__construct(
            'moneris',
            'Moneris',
            [],
            WPPAYFORM_URL .'assets/images/gateways/moneris.svg'
        );
    }

     /**
     * @function mapperSettings, To map key => value before store
     * @function validateSettings, To validate before save settings
     */

    public function init()
    {
        add_filter('wppayform_payment_method_settings_mapper_'.$this->key, array($this, 'mapperSettings'));
        add_filter('wppayform_payment_method_settings_validation_' . $this->key, array($this, 'validateSettings'), 10, 2);
    }

    /**
     * @return Array of global fields
     */
    public function globalFields() : array
    {
        return array(
            'is_active' => array(
                'value' => 'no',
                'label' => __('Enable/Disable', 'wp-payment-form'),
            ),
            'payment_mode' => array(
                'value' => 'test',
                'label' => __('Payment Mode', 'wp-payment-form'),
                'options' => array(
                    'test' => __('Test Mode', 'wp-payment-form'),
                    'live' => __('Live Mode', 'wp-payment-form')
                ),
                'type' => 'payment_mode'
            ),
            'live_store_id' => array(
                'value' => 'live',
                'label' => __('Live Store ID', 'wp-payment-form'),
                'type' => 'live_pub_key',
                'placeholder' => __('Live store ID', 'wp-payment-form')
            ),
            'test_store_id' => array(
                'value' => 'test',
                'label' => __('Test Store ID', 'wp-payment-form'),
                'type' => 'test_pub_key',
                'placeholder' => __('Test store ID', 'wp-payment-form')
            ),
            'live_api_token' => array(
                'value' => '',
                'label' => __('Live API Token', 'wp-payment-form'),
                'type' => 'live_secret_key',
                'placeholder' => __('Live API Token', 'wp-payment-form')
            ),
            'test_api_token' => array(
                'value' => '',
                'label' => __('Test API Token', 'wp-payment-form'),
                'type' => 'test_secret_key',
                'placeholder' => __('Test API Token', 'wp-payment-form')
            ),
            'live_checkout_id' => array(
                'value' => '',
                'label' => __('Live checkout id', 'wp-payment-form'),
                'type' => 'live_pub_key',
                'placeholder' => __('Live Checkout ID', 'wp-payment-form')
            ),
            'test_checkout_id' => array(
                'value' => '',
                'label' => __('Test Checkout ID', 'wp-payment-form'),
                'type' => 'test_pub_key',
                'placeholder' => __('Test Checkout ID', 'wp-payment-form')
            ),
            'payment_channels' => array(
                'value' => [],
                'label' => __('Payment Channels', 'wp-payment-form')
            ),
            'is_pro_item' => array(
                'value' => 'yes',
                'label' => __('PayPal', 'wp-payment-form'),
            ),
            'desc' => array(
                'value' => '<p>See our <a href="https://paymattic.com/docs/how-to-integrate-moneris-in-wordpress-with-paymattic/" target="_blank" rel="noopener">documentation</a> to get more information about moneris setup.</p>',
                'type' => 'html_attr',
            ),
        );
    }

    /**
     * @return Array of default fields
     */
    public static function settingsKeys() : array
    {
        $slug = 'moneris-payment-for-paymattic';

        return array(
            'is_active' => 'no',
            'payment_mode' => 'test',
            'checkout_type' => 'modal',
            'test_store_id' => '',
            'test_api_token' => '',
            'test_checkout_id' => '',
            'live_store_id' => '',
            'live_api_token' => '',
            'live_checkout_id' => '',
            'payment_channels' => [],
            'update_available' => self::checkForUpdate($slug),
        );
    }

  
    public static function checkForUpdate($slug)
    {
        $githubApi = "https://api.github.com/repos/WPManageNinja/{$slug}/releases";
        return $result = array(
            'available' => 'no',
            'url' => '',
            'slug' => 'moneris-payment-for-paymattic'
        );

        $response = wp_remote_get($githubApi, 
        [
            'headers' => array('Accept' => 'application/json',
            'authorization' => 'bearer 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')
        ]);

        $response = wp_remote_get($githubApi);
        $releases = json_decode($response['body']);
        if (isset($releases->documentation_url)) {
            return $result;
        }

        $latestRelease = $releases[0];
        $latestVersion = $latestRelease->tag_name;
        $zipUrl = $latestRelease->zipball_url;

        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
    
        $plugins = get_plugins();
        $currentVersion = '';

        // Check if the plugin is present
        foreach ($plugins as $plugin_file => $plugin_data) {
            // Check if the plugin slug or name matches
            if ($slug === $plugin_data['TextDomain'] || $slug === $plugin_data['Name']) {
                $currentVersion = $plugin_data['Version'];
            }
        }

        if (version_compare( $latestVersion, $currentVersion, '>')) {
            $result['available'] = 'yes';
            $result['url'] = $zipUrl;
        }

        return $result;
    }


    /**
     * @return Array of global_payments settings fields
     */
    public function getPaymentSettings() : array
    {
        $settings = $this->mapper(
            $this->globalFields(), 
            static::getSettings()
        );

        return array(
            'settings' => $settings,
            'is_key_defined' => self::isMonerisKeysDefined()
        );
    }

    public static function getSettings()
    {
        $settings = get_option('wppayform_payment_settings_moneris', array());
        $defaults = [
            'is_active' => 'no',
            'payment_mode' => 'test',
            'checkout_type' => 'modal',
            'test_store_id' => '',
            'test_api_token' => '',
            'test_checkout_id' => '',
            'live_store_id' => '',
            'live_api_token' => '',
            'live_checkout_id' => '',
            'payment_channels' => []
        ];
        return wp_parse_args($settings, $defaults);
    }

    public function mapperSettings ($settings)
    {
        return $this->mapper(
            static::settingsKeys(), 
            $settings, 
            false
        );
    }

    public static function isMonerisKeysDefined()
    {
        return defined('WP_PAY_FORM_MONERIS_STORE_ID') && defined('WP_PAY_FORM_MONERIS_API_TOKEN') && defined("WP_PAY_FORM_MONERIS_CHECKOUT_ID");
    }

    public function validateSettings($errors, $settings)
    {
        AccessControl::checkAndPresponseError('set_payment_settings', 'global');

        $mode = Arr::get($settings, 'payment_mode');
        if ($mode == 'test') {
            if (empty(Arr::get($settings, 'test_store_id')) || empty(Arr::get($settings, 'test_api_token') || empty(Arr::get($settings, 'test_checkout_id')))) {
                $errors['test_api_key'] = __('Please provide Test Store Id, API Token and Checkout ID', 'wp-payment-form-pro');
            }
        }

        if ($mode == 'live') {
            if (empty(Arr::get($settings, 'live_store_id')) || empty(Arr::get($settings, 'live_api_token') || empty(Arr::get($settings, 'live_checkout_id')))) {
                $errors['live_api_key'] = __('Please provide Live Store Id, API Token and Checkout ID', 'wp-payment-form-pro');
            }
        }
        return $errors;
    }

    public static function isLive($formId = false)
    {
        $settings = self::getSettings();
        $mode = Arr::get($settings, 'payment_mode');
        return $mode == 'live';
    }

    public static function getApiKeys($formId = false)
    {
        $isLive = self::isLive($formId);
        $settings = self::getSettings();
        if ($isLive) {
            return array(
                'store_id' => Arr::get($settings, 'live_store_id'),
                'api_token' => Arr::get($settings, 'live_api_token'),
                'checkout_id' => Arr::get($settings, 'live_checkout_id'),
                'payment_mode' => 'live'
            );
        }
        return array(
            'store_id' => Arr::get($settings, 'test_store_id'),
            'api_token' => Arr::get($settings, 'test_api_token'),
            'checkout_id' => Arr::get($settings, 'test_checkout_id'),
            'payment_mode' => 'test'
        );
    }
}
