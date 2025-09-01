<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Payment Driver Configuration
    |--------------------------------------------------------------------------
    */
    'driver' => [
        'enabled' => env('LUNAR_PRAHSYS_ENABLED', true),
        'default' => env('LUNAR_PRAHSYS_DEFAULT', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Checkout Configuration
    |--------------------------------------------------------------------------
    */
    'checkout' => [
        'success_url' => env('LUNAR_PRAHSYS_SUCCESS_URL', '/checkout/success'),
        'cancel_url' => env('LUNAR_PRAHSYS_CANCEL_URL', '/checkout/cancel'),
        'session_expires_in' => env('LUNAR_PRAHSYS_SESSION_EXPIRES', 3600),
    ],

    /*
    |--------------------------------------------------------------------------
    | Payment Methods
    |--------------------------------------------------------------------------
    */
    'payment_methods' => [
        'default' => 'pay_session',
        'available' => [
            'pay_portal' => [
                'enabled' => true,
                'name' => 'Hosted Checkout',
                'description' => 'Complete checkout on secure payment page',
            ],
            'pay_session' => [
                'enabled' => true,
                'name' => 'Embedded Checkout',
                'description' => 'Complete checkout without leaving your site',
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Order Processing
    |--------------------------------------------------------------------------
    */
    'orders' => [
        'auto_fulfill' => env('LUNAR_PRAHSYS_AUTO_FULFILL', false),
        'send_confirmation' => env('LUNAR_PRAHSYS_SEND_CONFIRMATION', true),
        'capture_method' => env('LUNAR_PRAHSYS_CAPTURE_METHOD', 'automatic'), // automatic or manual
    ],

    /*
    |--------------------------------------------------------------------------
    | Webhook Configuration
    |--------------------------------------------------------------------------
    */
    'webhooks' => [
        'enabled' => env('LUNAR_PRAHSYS_WEBHOOKS_ENABLED', true),
        'route' => '/webhooks/prahsys',
        'middleware' => ['api', 'clerk.webhook.verify'],
    ],

    /*
    |--------------------------------------------------------------------------
    | UI Configuration
    |--------------------------------------------------------------------------
    */
    'ui' => [
        'theme' => 'default',
        'show_payment_icons' => true,
        'mobile_responsive' => true,
    ],
];