<?php

namespace Prahsys\Lunar\Tests\Feature;

use Prahsys\Lunar\PaymentTypes\PrahsysPaymentType;
use Prahsys\Lunar\Tests\TestCase;

class ServiceProviderTest extends TestCase
{
    /** @test */
    public function it_can_resolve_prahsys_payment_type_from_container(): void
    {
        $paymentType = $this->app->make(PrahsysPaymentType::class);
        
        $this->assertInstanceOf(PrahsysPaymentType::class, $paymentType);
        $this->assertEquals('prahsys', $paymentType->getId());
        $this->assertEquals('Prahsys Payments', $paymentType->getName());
    }

    /** @test */
    public function checkout_button_component_can_render(): void
    {
        $component = new \Prahsys\Lunar\View\Components\CheckoutButton();
        
        $this->assertInstanceOf(\Prahsys\Lunar\View\Components\CheckoutButton::class, $component);
    }
}