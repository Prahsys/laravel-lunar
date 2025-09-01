<?php

declare(strict_types=1);

namespace Prahsys\Lunar\PaymentTypes;

use Lunar\Base\PaymentTypeInterface;
use Prahsys\Lunar\Drivers\PrahsysPaymentDriver;

class PrahsysPaymentType implements PaymentTypeInterface
{
    public function __construct(
        protected PrahsysPaymentDriver $driver
    ) {}

    /**
     * Return the payment type identifier
     */
    public function getId(): string
    {
        return 'prahsys';
    }

    /**
     * Return the payment type name
     */
    public function getName(): string
    {
        return 'Prahsys Payments';
    }

    /**
     * Return the payment driver instance
     */
    public function getDriver(): PrahsysPaymentDriver
    {
        return $this->driver;
    }

    /**
     * Return available payment methods
     */
    public function getPaymentMethods(): array
    {
        return config('lunar-prahsys.payment_methods.available', []);
    }

    /**
     * Check if payment type is enabled
     */
    public function isEnabled(): bool
    {
        return config('lunar-prahsys.driver.enabled', true);
    }

    /**
     * Get payment type description
     */
    public function getDescription(): string
    {
        return 'Secure payment processing via Prahsys gateway';
    }

    /**
     * Get supported currencies
     */
    public function getSupportedCurrencies(): array
    {
        return ['USD', 'EUR', 'GBP', 'CAD', 'AUD']; // Prahsys supported currencies
    }

    /**
     * Check if this payment type supports refunds
     */
    public function supportsRefunds(): bool
    {
        return true;
    }

    /**
     * Check if this payment type supports partial refunds
     */
    public function supportsPartialRefunds(): bool
    {
        return true;
    }

    /**
     * Get configuration for frontend
     */
    public function getFrontendConfig(): array
    {
        return [
            'payment_methods' => $this->getPaymentMethods(),
            'checkout_urls' => [
                'success' => config('lunar-prahsys.checkout.success_url'),
                'cancel' => config('lunar-prahsys.checkout.cancel_url'),
            ],
            'ui_config' => config('lunar-prahsys.ui', []),
            'session_expires_in' => config('lunar-prahsys.checkout.session_expires_in', 3600),
        ];
    }
}