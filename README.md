# Prahsys Payment Driver for Lunar

[![Latest Version on Packagist](https://img.shields.io/packagist/v/prahsys/laravel-lunar.svg?style=flat-square)](https://packagist.org/packages/prahsys/laravel-lunar)
[![GitHub Tests Action Status](https://img.shields.io/github/workflow/status/Prahsys/laravel-lunar/run-tests?label=tests)](https://github.com/Prahsys/laravel-lunar/actions?query=workflow%3Arun-tests+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/prahsys/laravel-lunar.svg?style=flat-square)](https://packagist.org/packages/prahsys/laravel-lunar)

Prahsys payments for Lunar stores - one line integration.

## Features

- Seamless Lunar integration following PaymentTypeInterface
- Multiple payment methods (hosted checkout, embedded forms)
- Automatic order processing and fulfillment
- Real-time webhook support
- Comprehensive refund management
- Mobile-responsive checkout components

## Installation

First install the core Clerk package:

```bash
composer require prahsys/laravel-clerk
```

Then install the Lunar driver:

```bash
composer require prahsys/laravel-lunar
```

Publish the configuration:

```bash
php artisan vendor:publish --tag="lunar-prahsys-config"
```

## Quick Start

Add to your `.env`:

```env
PRAHSYS_API_KEY=your_api_key_here
PRAHSYS_SANDBOX_MODE=true
LUNAR_PRAHSYS_ENABLED=true
```

That's it! Prahsys is now available as a payment option in your Lunar checkout.

## Usage

### Basic Checkout

```php
// In your checkout controller
$cart = Cart::find($cartId);
$paymentType = app(\Prahsys\Lunar\PaymentTypes\PrahsysPaymentType::class);
$paymentType->cart($cart);

$response = $paymentType->authorize();

if ($response->success) {
    return redirect($response->data['session_url']);
}
```

### Blade Component

```blade
<x-lunar-prahsys::checkout-button 
    :cart="$cart" 
    method="pay_session" 
/>
```

## Configuration

The package provides sensible defaults but can be fully customized:

```php
// config/lunar-prahsys.php
return [
    'driver' => [
        'enabled' => env('LUNAR_PRAHSYS_ENABLED', true),
    ],
    'checkout' => [
        'success_url' => '/checkout/success',
        'cancel_url' => '/checkout/cancel',
    ],
    'orders' => [
        'auto_fulfill' => false,
        'capture_method' => 'automatic',
    ],
];
```

## Testing

```bash
composer test
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.