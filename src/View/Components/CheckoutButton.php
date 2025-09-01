<?php

declare(strict_types=1);

namespace Prahsys\Lunar\View\Components;

use Illuminate\View\Component;
use Lunar\Facades\CartSession;
use Prahsys\Lunar\PaymentTypes\PrahsysPaymentType;

class CheckoutButton extends Component
{
    public function __construct(
        public string $paymentMethod = 'pay_portal',
        public string $buttonText = 'Checkout with Prahsys',
        public string $class = 'btn btn-primary btn-lg',
        public bool $requiresCustomerInfo = false
    ) {}

    /**
     * Get the view / contents that represent the component
     */
    public function render()
    {
        return view('lunar-prahsys::components.checkout-button');
    }

    /**
     * Get the current cart
     */
    public function getCart()
    {
        return CartSession::current();
    }

    /**
     * Check if cart has items
     */
    public function hasItems(): bool
    {
        $cart = $this->getCart();
        return $cart && $cart->lines->count() > 0;
    }

    /**
     * Get formatted cart total
     */
    public function getFormattedTotal(): string
    {
        $cart = $this->getCart();
        return $cart ? $cart->total->formatted() : '$0.00';
    }

    /**
     * Get payment method configuration
     */
    public function getPaymentMethodConfig(): array
    {
        return config("lunar-prahsys.payment_methods.available.{$this->paymentMethod}", []);
    }

    /**
     * Check if customer info is required for this payment method
     */
    public function requiresCustomerInfo(): bool
    {
        return $this->paymentMethod === 'pay_session' || $this->requiresCustomerInfo;
    }

    /**
     * Get checkout URL
     */
    public function getCheckoutUrl(): string
    {
        return route('prahsys.checkout.create');
    }
}