<?php
/**
 * PayMongo Payment Gateway Configuration
 * Go-on National College of the Philippines (GNCP) Academic Portal
 * 
 * Supports both Simulation Mode and Live / Sandbox PayMongo API Keys.
 */

return [
    // Toggle simulation mode: true = internal deterministic simulation, false = live PayMongo API via cURL
    'simulation_mode'  => true,

    // PayMongo API Credentials (Test / Live)
    'public_key'       => getenv('PAYMONGO_PUBLIC_KEY') ?: 'pk_test_gncp_demo_public_key_84920412',
    'secret_key'       => getenv('PAYMONGO_SECRET_KEY') ?: 'sk_test_gncp_demo_secret_key_84920412',
    'webhook_secret'   => getenv('PAYMONGO_WEBHOOK_SECRET') ?: 'whsec_gncp_demo_secret_998124',

    // API Endpoint
    'api_base_url'     => 'https://api.paymongo.com/v1',

    // Default Currency & Payment Rails
    'currency'         => 'PHP',
    'payment_method_types' => [
        'gcash',
        'qrph'
    ],

    // Merchant & Institutional Profile
    'merchant_name'    => 'Go-on National College of the Philippines',
    'success_url'      => 'http://localhost/systemtest/stations/payment-processing/?status=success',
    'cancel_url'       => 'http://localhost/systemtest/stations/payment-processing/?status=cancelled',
];
