<?php

/**
 * @package moneris-payment-for-paymattic
 *
 *
 */

/**
 * Plugin Name: Moneris Payment for paymattic
 * Plugin URI: https://paymattic.com/
 * Description: Moneris payment gateway for paymattic. Moneris is the leading payment gateway in Canada and USA.
 * Version: 1.0.1
 * Author: WPManageNinja LLC
 * Author URI: https://paymattic.com/
 * License: GPLv2 or later
 * Text Domain: moneris-payment-for-paymattic
 * Domain Path: /language
 */

if (!defined('ABSPATH')) {
    exit;
}

defined('ABSPATH') or die;

define('MONERIS_PAYMENT_FOR_PAYMATTIC', true);
define('MONERIS_PAYMENT_FOR_PAYMATTIC_DIR', __DIR__);
define('MONERIS_PAYMENT_FOR_PAYMATTIC_URL', plugin_dir_url(__FILE__));
define('MONERIS_PAYMENT_FOR_PAYMATTIC_VERSION', '1.0.1');


if (!class_exists('MonerisPaymentForPaymattic')) {
    class MonerisPaymentForPaymattic
    {
        public function boot()
        {
            if (!class_exists('MonerisPaymentForPaymattic\API\MonerisProcessor.php')) {
                $this->init();
            };
        }

        public function init()
        {
            require_once MONERIS_PAYMENT_FOR_PAYMATTIC_DIR . '/API/MonerisProcessor.php';
            // dd("hitts");
            (new MonerisPaymentForPaymattic\API\MonerisProcessor())->init();

            $this->loadTextDomain();
        }

        public function loadTextDomain()
        {
            load_plugin_textdomain('moneris-payment-for-paymattic', false, dirname(plugin_basename(__FILE__)) . '/language');
        }

        public function hasPro()
        {
            return defined('WPPAYFORMPRO_DIR_PATH') || defined('WPPAYFORMPRO_VERSION');
        }

        public function hasFree()
        {

            return defined('WPPAYFORM_VERSION');
        }

        public function versionCheck()
        {
            $currentFreeVersion = WPPAYFORM_VERSION;
            $currentProVersion = WPPAYFORMPRO_VERSION;

            return version_compare($currentFreeVersion, '4.5.2', '>=') && version_compare($currentProVersion, '4.5.2', '>=');
        }

        public function renderNotice()
        {
            add_action('admin_notices', function () {
                if (current_user_can('activate_plugins')) {
                    echo '<div class="notice notice-error"><p>';
                    echo __('Please install & Activate Paymattic and Paymattic Pro to use moneris-payment-for-paymattic plugin.', 'moneris-payment-for-paymattic');
                    echo '</p></div>';
                }
            });
        }

        public function updateVersionNotice()
        {
            add_action('admin_notices', function () {
                if (current_user_can('activate_plugins')) {
                    echo '<div class="notice notice-error"><p>';
                    echo __('Please update Paymattic and Paymattic Pro to use moneris-payment-for-paymattic plugin!', 'moneris-payment-for-paymattic');
                    echo '</p></div>';
                }
            });
        }
    }


    add_action('init', function () {

        $moneris = new MonerisPaymentForPaymattic;

        if (!$moneris->hasFree() || !$moneris->hasPro()) {
            $moneris->renderNotice();
        } else if (!$moneris->versionCheck()) {
            $moneris->updateVersionNotice();
        } else {
            $moneris->boot();
        }
    });
}