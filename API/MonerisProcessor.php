<?php

namespace MonerisPaymentForPaymattic\API;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

use WPPayForm\Framework\Support\Arr;
use WPPayForm\App\Models\Transaction;
use WPPayForm\App\Models\OrderItem;
use WPPayForm\App\Models\Subscription;
use WPPayForm\App\Models\Submission;
use WPPayForm\App\Services\CountryNames;
use WPPayForm\App\Services\ConfirmationHelper;

// can't use namespace as these files are not accessible yet.. we are not using autoload
require_once MONERIS_PAYMENT_FOR_PAYMATTIC_DIR . '/Settings/MonerisElement.php';
require_once MONERIS_PAYMENT_FOR_PAYMATTIC_DIR . '/Settings/MonerisSettings.php';
require_once MONERIS_PAYMENT_FOR_PAYMATTIC_DIR . '/API/API.php';

class MonerisProcessor
{
    public $method = 'moneris';

    protected $form;

    public function init()
    {
        new  \MonerisPaymentForPaymattic\Settings\MonerisElement();
        (new  \MonerisPaymentForPaymattic\Settings\MonerisSettings())->init();
        (new API())->init();

        add_filter('wppayform/choose_payment_method_for_submission', array($this, 'choosePaymentMethod'), 10, 4);
        add_action('wppayform/form_submission_make_payment_' . $this->method, array($this, 'makeFormPayment'), 10, 6);
        add_action('wppayform_load_checkout_js_' . $this->method, array($this, 'addCheckoutJs'), 10, 3);

        add_action('wp_ajax_wppayform_moneris_confirm_payment', array($this, 'confirmPayment'));
        add_action('wp_ajax_nopriv_wppayform_moneris_confirm_payment', array($this, 'confirmPayment'));
        add_filter('wppayform/entry_transactions_' . $this->method, array($this, 'addTransactionUrl'), 10, 2);
    }

    public function choosePaymentMethod($paymentMethod, $elements, $formId, $form_data)
    {
        if ($paymentMethod) {
            // Already someone choose that it's their payment method
            return $paymentMethod;
        }
        // Now We have to analyze the elements and return our payment method
        foreach ($elements as $element) {
            if ((isset($element['type']) && $element['type'] == 'moneris_gateway_element')) {
                return 'moneris';
            }
        }
        return $paymentMethod;
    }

    public function makeFormPayment($transactionId, $submissionId, $form_data, $form, $hasSubscriptions, $totalPayable = 0)
    {
        $paymentMode = $this->getPaymentMode();
        $transactionModel = new Transaction();

        if ($transactionId) {
            $transactionModel->updateTransaction($transactionId, array(
                'payment_mode' => $paymentMode
            ));
        }

        $transaction = $transactionModel->getTransaction($transactionId);

        $submission = (new Submission())->getSubmission($submissionId);

        $this->startCheckout($transaction, $submission, $form, $form_data, $paymentMode, $hasSubscriptions, $totalPayable);
    }

    public function addCheckoutJs($settings)
    {
        $isLive = (new \MonerisPaymentForPaymattic\Settings\MonerisSettings())::isLive();
        if ($isLive) {
            wp_enqueue_script('moneris', 'https://gateway.moneris.com/chktv2/js/chkt_v2.00.js', ['jquery'], WPPAYFORM_VERSION);
        } else {
            wp_enqueue_script('moneris', 'https://gatewayt.moneris.com/chktv2/js/chkt_v2.00.js', ['jquery'], WPPAYFORM_VERSION);
        }
        wp_enqueue_script('wppayform_moneris_handler', WPPAYFORM_URL . 'assets/js/moneris-handler.js', ['jquery'], WPPAYFORM_VERSION);
    }

    public function startCheckout($transaction, $submission, $form, $form_data, $paymentMode, $hasSubscriptions, $totalPayable)
    {
        $currency = strtoupper($submission->currency);
        if (!in_array($currency, ['CAD', 'USD'])) {
            wp_send_json([
                'errors'      => $currency . ' is not supported by Moneris payment method'
            ], 423);
        }

        $keys = \MonerisPaymentForPaymattic\Settings\MonerisSettings::getApiKeys($submission->form_id);
        $formDataFormatted = maybe_unserialize($submission->form_data_formatted);
        $response = $this->makePreloadRequest($keys, $submission, $transaction, $formDataFormatted, $form_data, $currency, $hasSubscriptions, $totalPayable)['response'];

        if ($response['success'] === 'false') {
            $errorData = $response['error'];
            // Function to recursively collect all values from the nested errorData array
                if (is_array($errorData)) {
                    function collectValues($array) {
                        $values = [];
                        foreach ($array as $value) {
                            if (is_array($value)) {
                                $values = array_merge($values, collectValues($value));
                            } else {
                                $values[] = $value;
                            }
                        }
                        return $values;
                    }

                    // Collect all values from the nested array
                    $values = collectValues($errorData);
                    // Join the values array into a single string
                    $errorMessage = implode(' and ', $values);
                    wp_send_json([
                        'errors'      => $errorMessage
                    ], 423);
                } else {
                    wp_send_json([
                        'errors'      => $errorData
                    ], 423);
                }
        }

        $ticket = $response['ticket'];
        if (!$ticket) {
            $submissionModel = new Submission();
            $submission = $submissionModel->getSubmission($transaction->submission_id);
            $submissionData = array(
                'payment_status' => 'failed',
                'updated_at' => current_time('Y-m-d H:i:s')
            );
            $submissionModel->where('id', $transaction->submission_id)->update($submissionData);

            do_action('wppayform_log_data', [
                'form_id' => $submission->form_id,
                'submission_id' => $submission->id,
                'type' => 'activity',
                'created_by' => 'Paymattic BOT',
                'title' => 'Moneris Modal is failed',
                'content' => 'Moneris Modal is failed to initiate, please check the logs for more information.'
            ]);

            do_action('wppayform/form_payment_failed', $submission, $submission->form_id, $transaction, 'moneris');
            wp_send_json([
                'errors'      => __('Moneris payment method failed to initiate', 'wp-payment-form-pro')
            ], 423);
        }


        if ($ticket && !$transaction) {
            $subscription = $this->getValidSubscription($submission);
            $amount = number_format($subscription->initial_amount / 100, 2, '.', '') ?? '1.00';
        } else {
            $amount = number_format($transaction->payment_total / 100, 2, '.', '');
        }

        $checkoutData = [
            'store_id' => $keys['store_id'],
            'api_token' => $keys['api_token'],
            'checkout_id' => $keys['checkout_id'],
            'ticket' => $ticket,
            'environment' => $paymentMode == 'live' ? 'prod' : 'qa',
            'action' => 'receipt',
            'email'    => $submission->customer_email ? $submission->customer_email : 'moneris@example.com',
            'ref'      => $submission->submission_hash,
            'amount'   => $amount,
            'currency' => $currency, //
            'label'    => $form->post_title,
            'metadata' => [
                'payment_handler' => 'WPPayForm',
                'form_id'         => $form->ID,
                'transaction_id'  => $transaction->id,
                'submission_id'   => $submission->id,
                'form'            => $form->post_title
            ]
        ];

        $checkoutData = apply_filters('wppayform_moneris_checkout_data', $checkoutData, $submission, $transaction, $form, $form_data);

        do_action('wppayform_log_data', [
            'form_id' => $submission->form_id,
            'submission_id' => $submission->id,
            'type' => 'activity',
            'created_by' => 'Paymattic BOT',
            'title' => 'Moneris Modal is initiated',
            'content' => 'Moneris Modal is initiated to complete the payment'
        ]);

        $confirmation = ConfirmationHelper::getFormConfirmation($submission->form_id, $submission);
        # Tell the client to handle the action
        wp_send_json_success([
            'nextAction'       => 'moneris',
            'actionName'       => 'initMonerisModal',
            'submission_id'    => $submission->id,
            'checkout_data'       => $checkoutData,
            'transaction_hash' => $submission->submission_hash,
            'message'          => __('Moneris checkout page is loading. Please wait ....', 'wp-payment-form-pro'),
            'result'           => [
                'insert_id' => $submission->id
            ]
        ], 200);
    }

    public function makePreloadRequest($keys, $submission, $transaction, $formDataFormatted, $form_data, $currency, $hasSubscriptions, $totalPayable = 0)
    {
        $requireBillingAddress = Arr::get($form_data, '__payment_require_billing_address') == 'yes';
        $paymentMode = $this->getPaymentMode($submission->form_id);
        $address = '';
        $formDataRaw = $submission->form_data_raw;


        $hasAddress = isset($formDataRaw['address_input']);
        $address = $formDataRaw['address_input'];

        if ($requireBillingAddress) {
            if (empty($address)) {
                return [
                    'response' => array(
                        'success' => 'false',
                        'error' => __('Billing Address is required.', 'wp-payment-form-pro')
                    )
                ];
            }
        }

        $address = array(
            'city' => Arr::get($address, 'city', ''),
            'country' => Arr::get($address, 'country', ''),
            'postal_code' => Arr::get($address, 'zip_code', ''),
            'state' => Arr::get($address, 'state', ''),
            'address_line_1' => Arr::get($address, 'address_line_1', ''),
            'address_line_2' => Arr::get($address, 'address_line_2', ''),
        );


        // make preloadRequestARgs
        $preloadRequestArgs = [];
        $preloadRequestArgs['store_id'] = $keys['store_id'];
        $preloadRequestArgs['api_token'] = $keys['api_token'];
        $preloadRequestArgs['checkout_id'] = $keys['checkout_id'];

        $orderItemsModel = new OrderItem();
        $lineItems = $orderItemsModel->getOrderItems($submission->id);
        $hasLineItems = count($lineItems) ? true : false;

        if (!$hasLineItems && !$hasSubscriptions) {
           wp_send_json_error(array(
                'message' => 'Moneris payment method requires at least one line item or subscription',
                'payment_error' => true,
                'type' => 'error',
                'form_events' => [
                    'payment_failed'
                ]
            ), 423);
        }

        $orderItemModel = new OrderItem();
        $discountItems = $orderItemModel->getDiscountItems($submission->id);

        if ($hasLineItems) {
            $preloadRequestArgs['txn_total'] = number_format($transaction->payment_total / 100, 2, '.', ''); 
            $this->maybeHasDiscountsWithDonationItems( $submission, $discountItems, $lineItems);  
        }
       
        $preloadRequestArgs = $this->maybeHasSubscription($preloadRequestArgs,$submission, $form_data, $paymentMode, $hasSubscriptions, $discountItems, $hasLineItems, $currency);

        $preloadRequestArgs['currency'] = $currency;
        $preloadRequestArgs['language'] = 'en';
        $preloadRequestArgs['environment'] = $paymentMode == 'live' ? 'prod' : 'qa';
        $preloadRequestArgs['action'] = 'preload';
        if ($requireBillingAddress || $hasAddress) {
            $preloadRequestArgs['billing_details'] = [
                'address_1' => trim($address['address_line_1']) ?? '',
                'address_2' => trim($address['address_line_2']) ?? '',
                'city' => trim($address['city']) ?? '',
                'province' => trim($address['state']) ?? '',
                'country' => $address['country'] ?? '',
                'postal_code' => trim($address['postal_code']) ?? '',
            ];
            $preloadRequestArgs['shipping_details'] = [
                'address_1' => trim($address['address_line_1']) ?? '',
                'address_2' => trim($address['address_line_2']) ?? '',
                'city' => trim($address['city']) ?? '',
                'province' => trim($address['state']) ?? '',
                'country' => $address['country'] ?? '',
                'postal_code' => trim($address['postal_code']) ?? '',
            ];
            
        }

        $cart = $this->getCartSummary($preloadRequestArgs, $submission, $form_data, $lineItems, $hasSubscriptions, $discountItems);

        $preloadRequestArgs['cart'] = $cart;

        $preloadRequestArgs = apply_filters('wppayform/moneris_payment_args', $preloadRequestArgs, $submission, $form_data, $transaction, $hasSubscriptions);
 
        if ($preloadRequestArgs['txn_total'] < 10) {
            if ($preloadRequestArgs['txn_total'] < 10 && $preloadRequestArgs['txn_total'] != floor($preloadRequestArgs['txn_total'])) {
                wp_send_json_error(array(
                    'message' => 'Moneris payment method does not support decimal amounts on less than $ 10.00 ex: 9.99 is not allowed but 9.00 is, same goes for 4.50 is not accepted but 4.00 is accepted, but fraction amount is accepted if the amount is bigger than 10.00.',
                    'payment_error' => true,
                    'type' => 'error',
                    'form_events' => [
                        'payment_failed'
                    ]
                ), 423);
            }
        }

        $api = new API();
        return $api->makeApiCall('', $preloadRequestArgs, $submission->form_id, 'POST');
    }

    public function getCartSummary($preloadRequestArgs, $submission, $form_data, $items, $hasSubscriptions = false, $discountItems = [])
    {
        $subtotal = 0;
        $cart = array(
            'items' => [],
        );
        if (count($items) > 0) {
            $counter = 1;
            $taxTotal = 0;
            $taxItemCounter = 0;
            $description = '';
            foreach ($items as $item) {
                if (!$item->item_price) {
                    continue;
                }
                $quantity = ($item->quantity) ? $item->quantity : 1; 
                $price = number_format($item->item_price / 100, 2, '.', '');
                if ( $item->type !== 'tax_line') {
                    $subtotal += $price * $quantity;
                }

                if ($item->type == 'tax_line') {
                    $tax = number_format($item->line_total / 100, 2, '.', '');
                    $temp = preg_replace('/[^a-zA-Z\s%]/', '', strip_tags(html_entity_decode($item->item_name)));
                    $tempDescription = preg_replace_callback('/[^a-zA-Z0-9\s()]/', function($matches) use ($tax) {
                        return ' '. number_format($tax, 2, '.', '');
                    }, strip_tags(html_entity_decode($temp)));

                    $taxTotal += intval($item->line_total);
                    if ($taxItemCounter > 0) {
                        $description .= ' '. $tempDescription;
                    } else {
                        $description .= $tempDescription;
                    }
                    $taxItemCounter++;
                } else {
                    $item = array(
                        'description' => preg_replace('/[^a-zA-Z0-9\s]/', '', strip_tags(html_entity_decode($item->item_name))),
                        'unit_cost' => $price,
                        'product_code' => (string) $item->id,
                        'quantity' => $quantity,
                    );
                    
                    $cart['items'][] = $item;
                }
                $counter = $counter + 1;
            }
            if ($taxItemCounter) {
                $cart['tax'] = array(
                    'amount' => number_format($taxTotal / 100, 2, '.', ''),
                    'description' =>  $description,
                );
            }
            // add discounts as item to the cart for user clarification
            if (count($discountItems) > 0) {
                foreach ($discountItems as $discountItem) {
                    $item = array(
                        'description' => 'Discount on ' . preg_replace('/[^a-zA-Z0-9\s]/', '', strip_tags(html_entity_decode($discountItem->item_name))) . ' Coupon',
                        'unit_cost' => number_format($discountItem->line_total / 100, 2, '.', ''),
                        'product_code' => (string) $discountItem->id,
                        'quantity' => '1',
                    );
                    $subtotal -= $discountItem->line_total / 100;
                    $cart['items'][] = $item;
                }
            }
        }

        $cart['subtotal'] = number_format($subtotal, 2, '.', '');

        if ($hasSubscriptions) {
            // We just need the first subscription
            $subscription = $this->getValidSubscription($submission);
            $item = array(
                'description' => preg_replace('/[^a-zA-Z0-9\s]/', '', strip_tags(html_entity_decode($subscription->item_name))),
                'unit_cost' => number_format($subscription->recurring_amount / 100, 2, '.', ''),
                'product_code' => (string) $subscription->id,
                'quantity' => '1',
            );
            $cart['items'][] = $item;
            if (count($items) == 0) {
                $subtotal = 0;
                
                $item = array(
                    'description' => preg_replace('/[^a-zA-Z0-9\s]/', '', strip_tags(html_entity_decode($subscription->item_name))) . ' Sign up Fee',
                    'unit_cost' => $subscription['initial_amount'] ? number_format($subscription['initial_amount'] / 100 , 2, '.', '') : '1.00',
                    'product_code' => (string) $subscription->id,
                    'quantity' => '1',
                );
                // add signup fee as item to the cart for user clarification
                $cart['items'][] = $item;
                $cart['subtotal'] = $subscription['initial_amount'] ? number_format($subscription['initial_amount'] / 100 , 2, '.', '') : '1.00'; // minimum amount to process the transaction or user will be charged the subscription amount as signup fee
            }
        }
        return $cart;   
    }

    public function maybeHasDiscountsWithDonationItems($submission, $discountItems, $lineItems)
    {
        if (count($discountItems) > 0) {
            // check for donation item
            foreach ($lineItems as $lineItem) {
                if ($lineItem->parent_holder == 'donation_item') {
                    wp_send_json_error(array(
                        'message' => 'Do not use discount with donation item',
                        'payment_error' => true,
                        'type' => 'error',
                        'form_events' => [
                            'payment_failed'
                        ]
                    ), 423);
                }
            }
            $discountTotal = 0;
            foreach ($discountItems as $discountItem) {
                $discountTotal += intval($discountItem->line_total);
            }
        }
    }

    public function maybeHasSubscription($originalArgs, $submission, $form_data, $paymentMode, $hasSubscriptions, $discountItems, $hasLineItems = false, $currency = 'USD')
    {
        if (!$hasSubscriptions) {
            return $originalArgs;
        }

        // We just need the first subscriptipn
        $subscription = $this->getValidSubscription($submission);

        if (!$subscription->recurring_amount) {
            return $originalArgs;
        }
       
        if (count($discountItems) > 0) {
            wp_send_json_error(array(
                'message' => 'Moneris payment method does not support discounts with subscriptions/recurring payments',
                'payment_error' => true,
                'type' => 'error',
                'form_events' => [
                    'payment_failed'
                ]
            ), 423);
        }

        $initialAmount = $subscription['initial_amount'];

        if (!$hasLineItems) {
            $paymentTotal = 100;

            if ($initialAmount > 0) {
                $paymentTotal = $initialAmount;
            }

            $currentUserId = get_current_user_id();
            $transaction = array(
                'form_id'        => $submission->form_id,
                'user_id'        => $currentUserId,
                'submission_id'  => $submission->id,
                'subscription_id' => $subscription->id,
                'charge_id'      => '',
                'transaction_type' => 'subscription',
                'payment_method' => 'moneris',
                'payment_total'  => $paymentTotal,
                'currency'       => $currency,
                'status'         => 'pending',
                'created_at'     => current_time('mysql'),
                'updated_at'     => current_time('mysql'),
            );
            $transaction = apply_filters('wppayform/submission_transaction_data', $transaction, $submission->form_id, $form_data);
            $transactionModel = new Transaction();
            $transactionId = $transactionModel->createTransaction($transaction)->id;
            do_action('wppayform/after_transaction_data_insert', $transactionId, $transaction);

            $originalArgs['txn_total'] = number_format((float)$paymentTotal / 100, 2, '.', ''); // minimum amount to process the transaction
        }

        // Unit to be used as a basis for the interval. Works in conjunction with the period variable to define the billing frequency.
        $recurUnit = $subscription['billing_interval'];

        if ('daily' === $recurUnit) {
            $recurUnit = 'day';
        }

        if ('month' !== $recurUnit && 'week' !== $recurUnit && 'day' !== $recurUnit && 'year' !== $recurUnit) {
            wp_send_json_error(array(
                'message' => 'Moneris payment method does not support ' . $recurUnit . ' subscription',
                'payment_error' => true,
                'type' => 'error',
                'form_events' => [
                    'payment_failed'
                ]
            ), 423);
        }

        // Immediate charge
        $billNow = 'true';
        $addDays = 0;
        // Free trial period in days
        $trialPeriod = intval($subscription['trial_days']);
       
        if ('0' != $initialAmount && $hasLineItems) {
            wp_send_json_error(array(
                'message' => "Moneris doesn't support initial amount/signup fee with One time payment, You can disable trial days or set it to 0, it will be charged the subscription amount as signup fee.",
                'payment_error' => true,
                'type' => 'error',
                'form_events' => [
                    'payment_failed'
                ]
            ), 423);
        }


        if ('0' != $trialPeriod) {
            $billNow = 'false';
            $addDays = $trialPeriod;

        } else {
            $billNow = 'true';
            if ('day' == $recurUnit) {
                $addDays = 1;
            } else if('week' == $recurUnit) {
                $addDays = 7;
            } else if('month' == $recurUnit) {
                $addDays = 30;
            } else if('year' == $recurUnit) {
                $addDays = 365;
            }
        }
         // Number of recur unit intervals that must pass between recurring billings
         $recur_period = '1';
         if ('year' === $recurUnit) {
             $recurUnit = 'month';
             $recur_period = '12';
         }


        $startDate = $subscription['created_at'];
        $dateTime = new \DateTime($startDate);
        $dateTime->modify("+". $addDays. " days");
        // Moneris requires the date to be in the format of YYYY-MM-DD
        $formattedDate = $dateTime->format('Y-m-d');

        // The number of times that the transaction must recur
        $numberOfRecurs = $subscription['bill_times'];
        if (!$numberOfRecurs || '0' === $numberOfRecurs || 'unlimited' === $numberOfRecurs) {
            wp_send_json_error(array(
                'message' => "Provide a valid number of Billing times(By default it sets to 0 on Subscription item settings - which indicate unlimited),  Moneris doesn't support unlimited Billings",
                'payment_error' => true,
                'type' => 'error',
                'form_events' => [
                    'payment_failed'
                ]
            ), 423);
        };

        // strucutre the args for subscription
        $originalArgs['recur'] = array(
            'bill_now' => $billNow,
            'recur_amount' => number_format($subscription->recurring_amount / 100, 2, '.', ''),
            'start_date' => $formattedDate,
            'recur_unit' => $recurUnit,
            'recur_period' => $recur_period,
            'number_of_recurs' => $numberOfRecurs,

        );

        return $originalArgs;
    }

    public function getValidSubscription($submission)
    {
        $subscriptionModel = new Subscription();
        $subscriptions = $subscriptionModel->getSubscriptions($submission->id);

        $validSubscriptions = [];
        foreach ($subscriptions as $subscriptionItem) {
            if ($subscriptionItem->recurring_amount) {
                $validSubscriptions[] = $subscriptionItem;
            }
        }

        if ($validSubscriptions && count($validSubscriptions) > 1) {
            wp_send_json_error(array(
                'message' => 'Moneris payment method does not support more than 1 subscriptions',
                'payment_error' => true,
                'type' => 'error',
                'form_events' => [
                    'payment_failed'
                ]
            ), 423);
            // Moneris Standard does not support more than 1 subscriptions
        }

        // We just need the first subscriptipn
        return $validSubscriptions[0];
    }

    protected function getPaymentMode($formId = false)
    {
        $isLive = (new \MonerisPaymentForPaymattic\Settings\MonerisSettings())::isLive($formId);
        if ($isLive) {
            return 'live';
        }
        return 'test';
    }

    public function addTransactionUrl($transactions, $submissionId)
    {
      
        if (count($transactions) > 0) {
            foreach ($transactions as $transaction) {
                if ($transaction->charge_id) {
                    $paymentNote = maybe_unserialize($transaction->payment_note);
                    $transaction->transaction_url =  'https://esqa.moneris.com/mpg/reports/order_history/index.php?order_no='.  $transaction->charge_id . '&orig_txn_no='.$paymentNote['transaction_no'];
                }
            }
        } else {
            $transaction = $this->getLastTransaction($submissionId);
            if ($transaction && $transaction->charge_id) {
                $paymentNote = maybe_unserialize($transaction->payment_note);
                $transaction->transaction_url =  'https://esqa.moneris.com/mpg/reports/order_history/index.php?order_no='.  $transaction->charge_id . '&orig_txn_no='.$paymentNote['transaction_no'];
                $transactions[] = $transaction;
            }
        }
        return $transactions;
    }


    public function getLastTransaction($submissionId)
    {
        $transactionModel = new Transaction();
        $transaction = $transactionModel->where('submission_id', $submissionId)
            ->first();
        return $transaction;
    }

    public function handlePaid($submission, $transaction, $vendorTransaction)
    {
        $transaction = $this->getLastTransaction($submission->id);

        if (!$transaction || $transaction->payment_method != $this->method) {
            return;
        }

        do_action('wppayform/form_submission_activity_start', $transaction->form_id);

        if ($transaction->payment_method != 'moneris') {
            return; // this isn't a moneris standard IPN
        }

        $status = 'paid';
        $paymentTotal = intval($vendorTransaction['amount'] * 100);
        $currency = $transaction->currency;
        $cardFist6Last4 = $vendorTransaction['first6last4'];
        $lastFour = substr($cardFist6Last4, -4);
        $cardType = $vendorTransaction['card_type'];
        if ('V' === $cardType) {
            $cardType = 'visa';
        }

        $updateData = [
            'status' => $status,
            'payment_note'     => maybe_serialize($vendorTransaction),
            'charge_id'        => sanitize_text_field(Arr::get($vendorTransaction, 'order_no')),
            'payment_total' => $paymentTotal,
            'currency'      => $currency,
            'card_brand' => sanitize_text_field($cardType),
            'card_last_4' => intval($lastFour),
        ];
        // Let's make the payment as paid
        $this->markAsPaid('paid', $updateData, $transaction);
    }

    public function markAsPaid($status, $updateData, $transaction)
    {
        $submissionModel = new Submission();
        $submission = $submissionModel->getSubmission($transaction->submission_id);

        $submissionData = array(
            'payment_status' => $status,
            'updated_at' => current_time('Y-m-d H:i:s')
        );

        $submissionModel->where('id', $transaction->submission_id)->update($submissionData);

        $transactionModel = new Transaction();
        $updateData['updated_at'] = current_time('Y-m-d H:i:s');

        $transactionModel->where('id', $transaction->id)->update($updateData);
        $transaction = $transactionModel->getTransaction($transaction->id);
        do_action('wppayform_log_data', [
            'form_id' => $transaction->form_id,
            'submission_id' => $transaction->submission_id,
            'type' => 'info',
            'created_by' => 'PayForm Bot',
            'content' => sprintf(__('Transaction Marked as paid and Moneris Transaction ID: %s', 'wp-payment-form-pro'), $updateData['charge_id'])
        ]);

        do_action('wppayform/form_payment_success_moneris', $submission, $transaction, $transaction->form_id, $updateData);
        do_action('wppayform/form_payment_success', $submission, $transaction, $transaction->form_id, $updateData);
    }

    public function confirmPayment()
    {
        $data = $_REQUEST;
        $formId = $data['form_id'];
        $transactionId = sanitize_text_field($data['transaction_id']);
        $submissionId = sanitize_text_field($data['submission_id']);

        $hasTransaction = $transactionId;

        $ticket = sanitize_text_field(Arr::get($data, 'ticket'));

        $keys = \MonerisPaymentForPaymattic\Settings\MonerisSettings::getApiKeys($formId);
        // populate the receipt request args
        $receiptRequestArgs = [];
        $receiptRequestArgs['store_id'] = $keys['store_id'];
        $receiptRequestArgs['api_token'] = $keys['api_token'];
        $receiptRequestArgs['checkout_id'] = $keys['checkout_id'];
        $receiptRequestArgs['ticket'] = $ticket;
        $receiptRequestArgs['environment'] = (new \MonerisPaymentForPaymattic\Settings\MonerisSettings())::isLive($formId) ? 'prod' : 'qa';
        $receiptRequestArgs['action'] = 'receipt';
        
        $submission = (new Submission())->getSubmission($submissionId);
        $transaction = (new Transaction())->getTransaction($transactionId);

        // get receipt and verify
        $api = new API();
        $response = $api->makeApiCall('', $receiptRequestArgs, $formId, 'POST')['response'];
        if ($response['success'] === 'false') {
            do_action('wppayform/form_payment_failed', $submission, $submission->form_id, $transaction, 'moneris');
            wp_send_json_error(array(
                'message' => $response['error'],
                'payment_error' => true,
                'type' => 'error',
                'form_events' => [
                    'payment_failed'
                ]
            ), 423);
        }

        $vendorPayment = $response['receipt']['cc'];
        if ('d' === $response['receipt']['result']) {
            wp_send_json_error(array(
                'message' => 'Payment could not be verified. Please contact site admin',
                'payment_error' => true,
                'type' => 'error',
                'form_events' => [
                    'payment_failed'
                ]
            ), 423);
        }
        if (isset($vendorPayment['recur_success']) && $vendorPayment['recur_success'] === 'true') {
            $this->processSubscriptionSignup($submission, $transaction, $vendorPayment);
        }
        if ( $transaction ) {
            if ( $response['success'] === 'true') {
                do_action('wppayform_log_data', [
                    'form_id' => $transaction->form_id,
                    'submission_id' => $submission->id,
                    'type' => 'activity',
                    'created_by' => 'Paymattic BOT',
                    'title' => 'Moneris Payment is verified',
                    'content' => 'Moneris payment has been marked as paid'
                ]);
    
                $this->handlePaid($submission, $transaction, $vendorPayment);
            }
            if (is_wp_error($vendorPayment)) {
                do_action('wppayform_log_data', [
                    'form_id' => $transaction->form_id,
                    'submission_id' => $submission->id,
                    'type' => 'activity',
                    'created_by' => 'Paymattic BOT',
                    'title' => 'Moneris Payment is failed to verify',
                    'content' => $vendorPayment->get_error_message()
                ]);
    
                wp_send_json_error(array(
                    'message' => $vendorPayment->get_error_message(),
                    'payment_error' => true,
                    'type' => 'error',
                    'form_events' => [
                        'payment_failed'
                    ]
                ), 423);
            }
        }

        $confirmation = ConfirmationHelper::getFormConfirmation($submission->form_id, $submission);
        $returnData['payment'] = $vendorPayment;
        $returnData['confirmation'] = $confirmation;
        wp_send_json_success($returnData, 200);
    }

    public function processSubscriptionSignup($submission, $transaction, $vendorPayment)
    {
        // We just need the first subscription
        $subscription = $this->getValidSubscription($submission);
       
        $data['status'] = 'active';
        // vendor_subscriptipn_id is intentionally mistaken as it is in the DB
        $data['vendor_subscriptipn_id'] = $vendorPayment['order_no'];
       
        if (!$transaction) {
            $data['payment_total'] = ($subscription->initial_amount) > 0 ? $subscription->initial_amount : '100';
            $data['initial_amount'] = ($subscription->initial_amount) > 0 ?$subscription->initial_amount :  '100';
        }

        $subscriptionModel = new Subscription();

        $subscriptionModel->updateSubscription($subscription['id'], $data);
   
        do_action('wppayform_log_data', [
            'form_id' => $subscription->form_id,
            'submission_id' => $submission->id,
            'type' => 'activity',
            'created_by' => 'Paymattic BOT',
            'title' => 'Moneris Payment is failed to verify',
            'content' => 'Moneris subscription payment has been marked as active'
        ]);

        $vendor_data = Arr::get($subscription, 'vendor_response');

        do_action('wppayform/subscription_payment_activated', $submission, $subscription, $submission->form_id, $vendor_data);
        do_action('wppayform/subscription_payment_activate_moneris', $submission, $subscription, $submission->form_id, $vendor_data);
    
        if (!$transaction) {
            $subscriptionTypeTransaction = Transaction::where('submission_id', $submission->id)
                ->where('transaction_type', 'subscription')
                ->first();
            // Its a subscription only transaction where the payment is made 1.00 as moneris dose not allow 0 dollar transaction
            $status = 'paid';
            $currency = $transaction->currency;
            $cardFist6Last4 = $vendorPayment['first6last4'];
            $lastFour = substr($cardFist6Last4, -4);
            $cardType = $vendorPayment['card_type'];
            if ('V' === $cardType) {
                $cardType = 'visa';
            }

            // update submission status to remove confusion as it is just a subscription signup not a payment,
            // we need to have at least 1.00 dollar transaction, which customer pay as subscription signup fee if there is no extra payment
            $submissionData = array(
                'payment_status' => $status,
                'payment_total' => ($subscription->initial_amount) > 0 ? $subscription->initial_amount : '100',
                'updated_at' => current_time('Y-m-d H:i:s')
            );
    
            $submissionModel = new Submission();
            $submissionModel->updateSubmission($submission->id, array(
                'payment_status' => 'paid'
            ));

            // now transaction related to the subscription but be sure it's not a subscription invoice or direct payment
            $updateData = [
                'status' => $status,
                'payment_note'     => maybe_serialize($vendorPayment),
                'charge_id'        => sanitize_text_field(Arr::get($vendorPayment, 'order_no')),
                'payment_total' => ($subscription->initial_amount) > 0 ? $subscription->initial_amount : '100',
                'currency'      => $currency,
                'card_brand' => sanitize_text_field($cardType),
                'card_last_4' => intval($lastFour),
            ];

            $transactionModel = new Transaction();
            $transactionModel->updateTransaction($subscriptionTypeTransaction->id, $updateData);

            // update transaction related submission also
            $submissionModel->updateSubmission($subscriptionTypeTransaction->submission_id, $submissionData);
            
            $confirmation = ConfirmationHelper::getFormConfirmation($submission->form_id, $submission);
            $returnData['payment'] = $vendorPayment;
            $returnData['confirmation'] = $confirmation;
            wp_send_json_success($returnData, 200);
        }

    }
}
