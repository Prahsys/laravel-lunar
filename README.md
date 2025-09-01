# Prahsys Lunar Payment Driver

[![Latest Version on Packagist](https://img.shields.io/packagist/v/prahsys/laravel-lunar.svg?style=flat-square)](https://packagist.org/packages/prahsys/laravel-lunar)
[![GitHub Tests Action Status](https://img.shields.io/github/workflow/status/Prahsys/laravel-lunar/run-tests?label=tests)](https://github.com/Prahsys/laravel-lunar/actions?query=workflow%3Arun-tests+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/prahsys/laravel-lunar.svg?style=flat-square)](https://packagist.org/packages/prahsys/laravel-lunar)

A Laravel package that integrates Prahsys payment processing with the Lunar e-commerce framework.

## Features

- 🛒 **Seamless Cart Integration** - Direct integration with Lunar's cart system
- 💳 **Multiple Payment Methods** - Support for hosted (`pay_portal`) and embedded (`pay_session`) checkout
- 🎨 **Customizable UI Components** - Pre-built Blade components with Alpine.js integration
- 🔒 **Secure Webhooks** - Comprehensive webhook handling for payment events
- ⚡ **Real-time Status** - Live payment status updates and monitoring
- 🔄 **Refund Support** - Full and partial refund capabilities
- 📊 **Audit Logging** - Complete transaction and event audit trail
- 🧪 **Comprehensive Tests** - Full test suite with 80%+ coverage

## Requirements

- PHP 8.2+
- Laravel 12+
- Lunar Framework
- `prahsys/laravel-clerk` package (core Prahsys integration)

## Installation

1. Install the core Clerk package first:

```bash
composer require prahsys/laravel-clerk
```

2. Install the Lunar driver package:

```bash
composer require prahsys/laravel-lunar
```

3. Publish the configuration file:

```bash
php artisan vendor:publish --tag=lunar-prahsys-config
```

4. Publish the views (optional):

```bash
php artisan vendor:publish --tag=lunar-prahsys-views
```

5. Configure your environment variables:

```env
PRAHSYS_API_KEY=your_api_key_here
PRAHSYS_WEBHOOK_SECRET=your_webhook_secret_here
LUNAR_PRAHSYS_ENABLED=true
```

## Quick Start

### Basic Checkout Integration

Add the checkout button to your cart page:

```blade
<x-lunar-prahsys:checkout-button 
    payment-method="pay_portal"
    button-text="Pay with Prahsys"
    class="btn btn-primary btn-lg"
/>
```

For embedded checkout with customer information collection:

```blade
<x-lunar-prahsys:checkout-button 
    payment-method="pay_session"
    button-text="Complete Payment"
    :requires-customer-info="true"
/>
```

### Programmatic Usage

```php
use Lunar\Facades\CartSession;
use Prahsys\Lunar\Drivers\PrahsysPaymentDriver;

$cart = CartSession::current();
$paymentDriver = app(PrahsysPaymentDriver::class);

$transaction = $paymentDriver->cart($cart, [
    'payment_method' => 'pay_portal',
]);

if ($transaction && $transaction->success) {
    $session = PrahsysPaymentSession::where('session_id', $transaction->reference)->first();
    return redirect($session->checkout_url);
}
```

## Configuration

The `config/lunar-prahsys.php` file provides extensive customization options:

```php
return [
    'driver' => [
        'enabled' => env('LUNAR_PRAHSYS_ENABLED', true),
    ],
    
    'payment_methods' => [
        'default' => 'pay_portal',
        'available' => [
            'pay_portal' => [
                'name' => 'Hosted Checkout',
                'description' => 'Redirect to Prahsys hosted payment page',
            ],
            'pay_session' => [
                'name' => 'Embedded Checkout', 
                'description' => 'Embedded payment form',
                'requires_customer_info' => true,
            ],
        ],
    ],
    
    'checkout' => [
        'success_url' => '/checkout/success',
        'cancel_url' => '/checkout/cancel',
        'session_expires_in' => 3600, // 1 hour
    ],
    
    'webhooks' => [
        'middleware' => ['api'],
        'events' => [
            'payment.completed',
            'payment.failed',
            'payment.cancelled',
            'payment.refunded',
            'payment.partially_refunded',
        ],
    ],
];
```

## Payment Methods

### Hosted Checkout (`pay_portal`)

Redirects customers to a secure Prahsys-hosted payment page. Best for simple integration with minimal customization needs.

### Embedded Checkout (`pay_session`)

Embeds the payment form directly in your application. Provides more control over the user experience and requires customer information collection.

## Webhook Configuration

Configure your Prahsys webhook endpoint to:

```
POST https://yoursite.com/prahsys/webhooks/lunar
```

The package handles these webhook events automatically:
- `payment.completed` - Payment successfully processed
- `payment.failed` - Payment failed or was declined  
- `payment.cancelled` - Payment cancelled by customer
- `payment.refunded` - Full refund processed
- `payment.partially_refunded` - Partial refund processed

## Refund Processing

```php
use Prahsys\Lunar\Drivers\PrahsysPaymentDriver;

$paymentDriver = app(PrahsysPaymentDriver::class);

// Full refund
$transaction = $paymentDriver->refund($order, [
    'notes' => 'Customer requested refund',
]);

// Partial refund
$transaction = $paymentDriver->refund($order, [
    'amount' => 1500, // $15.00 refund
    'notes' => 'Partial refund for damaged item',
]);
```

## Testing with Docker Tinker

During development, you can test features using Docker Tinker as recommended in the development workflow:

```bash
# Test payment driver instantiation
docker exec fable_app php artisan tinker --execute="
use Prahsys\Lunar\Drivers\PrahsysPaymentDriver;
echo 'Driver loaded: ' . (class_exists(PrahsysPaymentDriver::class) ? 'YES' : 'NO');
"

# Test payment session creation
docker exec fable_app php artisan tinker --execute="
use Prahsys\LaravelClerk\Models\PrahsysPaymentSession;
\$session = PrahsysPaymentSession::factory()->create();
echo 'Test session created: ' . \$session->session_id;
"
```

## Testing

Run the comprehensive test suite:

```bash
# Run all tests
vendor/bin/phpunit

# Run with coverage
vendor/bin/phpunit --coverage-html build/coverage

# Run specific test suites
vendor/bin/phpunit --testsuite Unit
vendor/bin/phpunit --testsuite Feature
```

The test suite covers:
- Payment driver functionality
- Checkout controller integration  
- Webhook processing
- Error handling and validation
- Refund processing
- Security features

## API Routes

The package automatically registers these routes:

```php
// Checkout routes
POST /prahsys/checkout              // Create payment session
GET  /prahsys/checkout/success      // Payment success callback
GET  /prahsys/checkout/cancel       // Payment cancellation callback  
GET  /prahsys/checkout/status/{id}  // Payment status check

// Webhook routes
POST /prahsys/webhooks/lunar        // Primary webhook handler
GET  /prahsys/webhooks/status       // Webhook status check
POST /prahsys/webhooks/lunar-legacy // Legacy webhook handler
```

## Security

- All webhook signatures are automatically verified
- CSRF tokens are included in checkout forms
- Payment data is validated before processing
- Sensitive information is never logged or exposed

## Troubleshooting

### Common Issues

**Cart is Empty Error**: Ensure cart has items before checkout
```php
$cart = CartSession::current();
if (!$cart || $cart->lines->isEmpty()) {
    return redirect()->back()->with('error', 'Please add items to cart');
}
```

**Payment Session Not Found**: Check session ID is passed correctly in callbacks
```php
'success_url' => route('checkout.success') . '?session_id={session_id}',
```

**Webhook Signature Failed**: Verify webhook secret is configured correctly
```env
PRAHSYS_WEBHOOK_SECRET=your_actual_webhook_secret
```

### Debug Mode

Enable debug logging:
```php
// In config/lunar-prahsys.php
'debug' => env('PRAHSYS_DEBUG', false),
```

## Contributing

1. Fork the repository
2. Create your feature branch (`git checkout -b feature/amazing-feature`)
3. Write tests for your changes
4. Ensure tests pass (`vendor/bin/phpunit`)
5. Commit your changes (`git commit -m 'Add amazing feature'`)
6. Push to the branch (`git push origin feature/amazing-feature`)  
7. Open a Pull Request

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.

## Support

- 📧 **Email:** support@prahsys.com
- 📖 **Documentation:** https://docs.prahsys.com/lunar
- 🐛 **Issues:** https://github.com/prahsys/laravel-lunar/issues
- 💬 **Discord:** https://discord.gg/prahsys