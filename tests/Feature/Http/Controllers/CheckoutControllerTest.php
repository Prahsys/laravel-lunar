<?php

declare(strict_types=1);

namespace Prahsys\Lunar\Tests\Feature\Http\Controllers;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;
use Lunar\Models\Cart;
use Lunar\Models\Order;
use Lunar\Models\CartLine;
use Lunar\Models\Product;
use Lunar\Models\Channel;
use Lunar\Models\Currency;
use Lunar\Facades\CartSession;
use Prahsys\LaravelClerk\Models\PrahsysPaymentSession;
use Prahsys\Lunar\Drivers\PrahsysPaymentDriver;

class CheckoutControllerTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    protected Cart $cart;
    protected Product $product;
    protected Channel $channel;
    protected Currency $currency;

    protected function setUp(): void
    {
        parent::setUp();

        // Create test channel and currency
        $this->channel = Channel::factory()->create(['default' => true]);
        $this->currency = Currency::factory()->create(['code' => 'USD', 'default' => true]);
        
        // Create test product
        $this->product = Product::factory()->create();

        // Create test cart with items
        $this->cart = Cart::factory()->create([
            'channel_id' => $this->channel->id,
            'currency_id' => $this->currency->id,
        ]);

        // Add cart line
        CartLine::factory()->create([
            'cart_id' => $this->cart->id,
            'purchasable_id' => $this->product->id,
            'purchasable_type' => Product::class,
            'quantity' => 2,
        ]);

        // Mock CartSession
        CartSession::shouldReceive('current')->andReturn($this->cart);
    }

    /** @test */
    public function it_can_create_checkout_with_portal_payment()
    {
        // Mock payment driver
        $mockDriver = $this->mock(PrahsysPaymentDriver::class);
        $mockDriver->shouldReceive('cart')
            ->once()
            ->andReturn((object)[
                'success' => true,
                'id' => 1,
                'reference' => 'test_session_123'
            ]);

        // Mock payment session
        $mockSession = PrahsysPaymentSession::factory()->create([
            'session_id' => 'test_session_123',
            'payment_id' => 'pay_test_123',
            'portal_url' => 'https://portal.prahsys.com/test',
            'status' => 'pending',
        ]);

        PrahsysPaymentSession::shouldReceive('where')
            ->with('session_id', 'test_session_123')
            ->andReturnSelf();
        PrahsysPaymentSession::shouldReceive('first')
            ->andReturn($mockSession);

        // Act
        $response = $this->postJson('/prahsys/checkout', [
            'payment_method' => 'pay_portal',
        ]);

        // Assert
        $response->assertSuccessful()
            ->assertJson([
                'success' => true,
                'transaction_id' => 1,
                'session_id' => 'test_session_123',
                'payment_id' => 'pay_test_123',
                'redirect_url' => 'https://portal.prahsys.com/test',
            ]);
    }

    /** @test */
    public function it_can_create_checkout_with_session_payment()
    {
        // Mock payment driver
        $mockDriver = $this->mock(PrahsysPaymentDriver::class);
        $mockDriver->shouldReceive('cart')
            ->once()
            ->andReturn((object)[
                'success' => true,
                'id' => 1,
                'reference' => 'test_session_123'
            ]);

        // Mock payment session
        $mockSession = PrahsysPaymentSession::factory()->create([
            'session_id' => 'test_session_123',
            'payment_id' => 'pay_test_123',
            'checkout_url' => 'https://checkout.prahsys.com/test',
            'status' => 'pending',
        ]);

        PrahsysPaymentSession::shouldReceive('where')
            ->with('session_id', 'test_session_123')
            ->andReturnSelf();
        PrahsysPaymentSession::shouldReceive('first')
            ->andReturn($mockSession);

        // Act
        $response = $this->postJson('/prahsys/checkout', [
            'payment_method' => 'pay_session',
            'customer_name' => 'John Doe',
            'customer_email' => 'john@example.com',
        ]);

        // Assert
        $response->assertSuccessful()
            ->assertJson([
                'success' => true,
                'transaction_id' => 1,
                'session_id' => 'test_session_123',
                'payment_id' => 'pay_test_123',
                'checkout_url' => 'https://checkout.prahsys.com/test',
            ]);
    }

    /** @test */
    public function it_redirects_for_portal_payment_when_not_json()
    {
        // Mock payment driver
        $mockDriver = $this->mock(PrahsysPaymentDriver::class);
        $mockDriver->shouldReceive('cart')
            ->once()
            ->andReturn((object)[
                'success' => true,
                'id' => 1,
                'reference' => 'test_session_123'
            ]);

        // Mock payment session
        $mockSession = PrahsysPaymentSession::factory()->create([
            'session_id' => 'test_session_123',
            'portal_url' => 'https://portal.prahsys.com/test',
        ]);

        PrahsysPaymentSession::shouldReceive('where')
            ->with('session_id', 'test_session_123')
            ->andReturnSelf();
        PrahsysPaymentSession::shouldReceive('first')
            ->andReturn($mockSession);

        // Act
        $response = $this->post('/prahsys/checkout', [
            'payment_method' => 'pay_portal',
        ]);

        // Assert
        $response->assertRedirect('https://portal.prahsys.com/test');
    }

    /** @test */
    public function it_validates_required_customer_info_for_session_payment()
    {
        // Act
        $response = $this->postJson('/prahsys/checkout', [
            'payment_method' => 'pay_session',
        ]);

        // Assert
        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['customer_name', 'customer_email']);
    }

    /** @test */
    public function it_fails_when_cart_is_empty()
    {
        // Mock empty cart
        $emptyCart = Cart::factory()->create();
        CartSession::shouldReceive('current')->andReturn($emptyCart);

        // Act
        $response = $this->postJson('/prahsys/checkout', [
            'payment_method' => 'pay_portal',
        ]);

        // Assert
        $response->assertStatus(400)
            ->assertJson(['error' => 'Cart is empty']);
    }

    /** @test */
    public function it_fails_when_payment_initialization_fails()
    {
        // Mock payment driver failure
        $mockDriver = $this->mock(PrahsysPaymentDriver::class);
        $mockDriver->shouldReceive('cart')
            ->once()
            ->andReturn((object)['success' => false]);

        // Act
        $response = $this->postJson('/prahsys/checkout', [
            'payment_method' => 'pay_portal',
        ]);

        // Assert
        $response->assertStatus(400)
            ->assertJson(['error' => 'Failed to initialize payment']);
    }

    /** @test */
    public function it_can_handle_successful_payment_callback()
    {
        // Create order
        $order = Order::factory()->create(['cart_id' => $this->cart->id]);
        
        // Mock payment session
        $mockSession = PrahsysPaymentSession::factory()->create([
            'session_id' => 'test_session_123',
            'payment_id' => 'pay_test_123',
        ]);

        PrahsysPaymentSession::shouldReceive('where')
            ->with('session_id', 'test_session_123')
            ->andReturnSelf();
        PrahsysPaymentSession::shouldReceive('first')
            ->andReturn($mockSession);

        // Mock cart lookup
        Cart::shouldReceive('where')
            ->with('meta->payment_id', 'pay_test_123')
            ->andReturnSelf();
        Cart::shouldReceive('first')
            ->andReturn($this->cart);

        // Mock order lookup
        Order::shouldReceive('where')
            ->with('cart_id', $this->cart->id)
            ->andReturnSelf();
        Order::shouldReceive('first')
            ->andReturn($order);

        // Mock payment driver
        $mockDriver = $this->mock(PrahsysPaymentDriver::class);
        $mockDriver->shouldReceive('order')
            ->once()
            ->andReturn((object)['success' => true]);

        // Mock CartSession::forget
        CartSession::shouldReceive('forget')->once();

        // Act
        $response = $this->get('/prahsys/checkout/success?session_id=test_session_123');

        // Assert
        $response->assertRedirect()
            ->assertSessionHas('success', 'Payment completed successfully!');
    }

    /** @test */
    public function it_handles_payment_cancellation()
    {
        // Mock payment session
        $mockSession = PrahsysPaymentSession::factory()->create([
            'session_id' => 'test_session_123',
            'payment_id' => 'pay_test_123',
        ]);

        PrahsysPaymentSession::shouldReceive('where')
            ->with('session_id', 'test_session_123')
            ->andReturnSelf();
        PrahsysPaymentSession::shouldReceive('first')
            ->andReturn($mockSession);

        // Mock cart and order lookup
        Cart::shouldReceive('where')
            ->with('meta->payment_id', 'pay_test_123')
            ->andReturnSelf();
        Cart::shouldReceive('first')
            ->andReturn($this->cart);

        $order = Order::factory()->create(['cart_id' => $this->cart->id]);
        Order::shouldReceive('where')
            ->with('cart_id', $this->cart->id)
            ->andReturnSelf();
        Order::shouldReceive('first')
            ->andReturn($order);

        // Mock payment driver
        $mockDriver = $this->mock(PrahsysPaymentDriver::class);
        $mockDriver->shouldReceive('order')
            ->once()
            ->andReturn((object)['success' => true]);

        // Act
        $response = $this->get('/prahsys/checkout/cancel?session_id=test_session_123');

        // Assert
        $response->assertRedirect()
            ->assertSessionHas('error', 'Payment was cancelled. Please try again.');
    }

    /** @test */
    public function it_can_get_payment_status()
    {
        // Mock payment session
        $mockSession = PrahsysPaymentSession::factory()->create([
            'session_id' => 'test_session_123',
            'status' => 'completed',
            'amount' => 2500,
            'currency' => 'USD',
            'completed_at' => now(),
            'expires_at' => now()->addHour(),
        ]);

        PrahsysPaymentSession::shouldReceive('where')
            ->with('session_id', 'test_session_123')
            ->andReturnSelf();
        PrahsysPaymentSession::shouldReceive('first')
            ->andReturn($mockSession);

        // Mock additional methods
        $mockSession->shouldReceive('isExpired')->andReturn(false);
        $mockSession->shouldReceive('isCompleted')->andReturn(true);

        // Act
        $response = $this->getJson('/prahsys/checkout/status/test_session_123');

        // Assert
        $response->assertSuccessful()
            ->assertJson([
                'session_id' => 'test_session_123',
                'status' => 'completed',
                'amount' => 2500,
                'currency' => 'USD',
                'is_expired' => false,
                'is_completed' => true,
            ]);
    }

    /** @test */
    public function it_handles_webhook_notifications()
    {
        // Mock payment session
        $mockSession = PrahsysPaymentSession::factory()->create([
            'session_id' => 'test_session_123',
            'payment_id' => 'pay_test_123',
        ]);

        PrahsysPaymentSession::shouldReceive('where')
            ->with('session_id', 'test_session_123')
            ->andReturnSelf();
        PrahsysPaymentSession::shouldReceive('first')
            ->andReturn($mockSession);

        // Mock cart and order lookup
        Cart::shouldReceive('where')
            ->with('meta->payment_id', 'pay_test_123')
            ->andReturnSelf();
        Cart::shouldReceive('first')
            ->andReturn($this->cart);

        $order = Order::factory()->create(['cart_id' => $this->cart->id]);
        Order::shouldReceive('where')
            ->with('cart_id', $this->cart->id)
            ->andReturnSelf();
        Order::shouldReceive('first')
            ->andReturn($order);

        // Mock payment driver
        $mockDriver = $this->mock(PrahsysPaymentDriver::class);
        $mockDriver->shouldReceive('order')
            ->once()
            ->andReturn((object)['success' => true]);

        // Act
        $response = $this->postJson('/prahsys/webhooks/lunar-legacy', [
            'session_id' => 'test_session_123',
            'status' => 'completed',
            'amount' => 2500,
        ]);

        // Assert
        $response->assertSuccessful()
            ->assertJson(['success' => true]);
    }

    /** @test */
    public function it_validates_webhook_payload()
    {
        // Act
        $response = $this->postJson('/prahsys/webhooks/lunar-legacy', []);

        // Assert
        $response->assertStatus(400)
            ->assertJson(['error' => 'Invalid webhook payload']);
    }
}