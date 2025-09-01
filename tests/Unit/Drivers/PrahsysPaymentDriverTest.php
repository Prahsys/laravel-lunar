<?php

declare(strict_types=1);

namespace Prahsys\Lunar\Tests\Unit\Drivers;

use PHPUnit\Framework\TestCase;
use Mockery;
use Mockery\MockInterface;
use Lunar\Models\Cart;
use Lunar\Models\Order;
use Lunar\Models\Transaction;
use Prahsys\LaravelClerk\Services\PrahsysService;
use Prahsys\LaravelClerk\Models\PrahsysPaymentSession;
use Prahsys\Lunar\Drivers\PrahsysPaymentDriver;
use Prahsys\LaravelClerk\Exceptions\PrahsysException;

class PrahsysPaymentDriverTest extends TestCase
{
    protected PrahsysPaymentDriver $driver;
    protected MockInterface $prahsysService;
    protected MockInterface $cart;
    protected MockInterface $order;

    protected function setUp(): void
    {
        parent::setUp();

        $this->prahsysService = Mockery::mock(PrahsysService::class);
        $this->driver = new PrahsysPaymentDriver($this->prahsysService);
        
        $this->cart = Mockery::mock(Cart::class);
        $this->order = Mockery::mock(Order::class);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** @test */
    public function it_can_create_cart_payment_session()
    {
        // Arrange
        $this->cart->shouldReceive('getAttribute')->with('id')->andReturn(1);
        $this->cart->shouldReceive('getAttribute')->with('total')->andReturn((object)['value' => 2500, 'currency' => (object)['code' => 'USD']]);
        $this->cart->shouldReceive('getAttribute')->with('user_id')->andReturn(null);
        $this->cart->shouldReceive('getAttribute')->with('channel_id')->andReturn(1);
        $this->cart->shouldReceive('getAttribute')->with('lines')->andReturn(collect([
            (object)['product' => (object)['name' => 'Test Product'], 'quantity' => 1, 'unit_price' => (object)['value' => 2500]]
        ]));

        $sessionData = [
            'session_id' => 'prahsys_test_session_123',
            'payment_id' => 'pay_test_123',
            'checkout_url' => 'https://checkout.prahsys.com/session/123',
            'portal_url' => 'https://portal.prahsys.com/session/123',
            'status' => 'pending',
            'amount' => 2500,
            'currency' => 'USD',
            'expires_at' => now()->addHour(),
        ];

        $this->prahsysService->shouldReceive('createPaymentSession')
            ->once()
            ->with(Mockery::type('array'))
            ->andReturn($sessionData);

        // Mock session creation
        $mockSession = Mockery::mock(PrahsysPaymentSession::class);
        $mockSession->shouldReceive('getAttribute')->with('session_id')->andReturn('prahsys_test_session_123');
        $mockSession->shouldReceive('getAttribute')->with('payment_id')->andReturn('pay_test_123');
        $mockSession->shouldReceive('getAttribute')->with('status')->andReturn('pending');

        // Mock static method
        PrahsysPaymentSession::shouldReceive('create')
            ->once()
            ->andReturn($mockSession);

        // Mock cart update
        $this->cart->shouldReceive('update')->once();

        // Mock transaction creation
        $mockTransaction = Mockery::mock(Transaction::class);
        $mockTransaction->shouldReceive('getAttribute')->with('success')->andReturn(true);
        $mockTransaction->shouldReceive('getAttribute')->with('reference')->andReturn('prahsys_test_session_123');
        $mockTransaction->shouldReceive('getAttribute')->with('id')->andReturn(1);

        Transaction::shouldReceive('create')
            ->once()
            ->andReturn($mockTransaction);

        $data = [
            'payment_method' => 'pay_portal',
        ];

        // Act
        $result = $this->driver->cart($this->cart, $data);

        // Assert
        $this->assertInstanceOf(Transaction::class, $result);
        $this->assertTrue($result->success);
        $this->assertEquals('prahsys_test_session_123', $result->reference);
    }

    /** @test */
    public function it_handles_cart_payment_session_failure()
    {
        // Arrange
        $this->cart->shouldReceive('getAttribute')->with('id')->andReturn(1);
        $this->cart->shouldReceive('getAttribute')->with('total')->andReturn((object)['value' => 2500, 'currency' => (object)['code' => 'USD']]);
        $this->cart->shouldReceive('getAttribute')->with('user_id')->andReturn(null);
        $this->cart->shouldReceive('getAttribute')->with('channel_id')->andReturn(1);
        $this->cart->shouldReceive('getAttribute')->with('lines')->andReturn(collect([
            (object)['product' => (object)['name' => 'Test Product'], 'quantity' => 1, 'unit_price' => (object)['value' => 2500]]
        ]));

        $this->prahsysService->shouldReceive('createPaymentSession')
            ->once()
            ->andThrow(new PrahsysException('Payment session creation failed'));

        // Mock failed transaction creation
        $mockTransaction = Mockery::mock(Transaction::class);
        $mockTransaction->shouldReceive('getAttribute')->with('success')->andReturn(false);
        $mockTransaction->shouldReceive('getAttribute')->with('status')->andReturn('failed');

        Transaction::shouldReceive('create')
            ->once()
            ->andReturn($mockTransaction);

        $data = ['payment_method' => 'pay_portal'];

        // Act
        $result = $this->driver->cart($this->cart, $data);

        // Assert
        $this->assertInstanceOf(Transaction::class, $result);
        $this->assertFalse($result->success);
        $this->assertEquals('failed', $result->status);
    }

    /** @test */
    public function it_can_process_order_payment()
    {
        // Arrange
        $this->order->shouldReceive('getAttribute')->with('id')->andReturn(1);
        $this->order->shouldReceive('getAttribute')->with('total')->andReturn((object)['value' => 2500, 'currency' => (object)['code' => 'USD']]);
        $this->order->shouldReceive('getAttribute')->with('reference')->andReturn('order_123');

        $data = [
            'session_id' => 'prahsys_test_session_123',
            'status' => 'captured',
        ];

        // Mock session lookup
        $mockSession = Mockery::mock(PrahsysPaymentSession::class);
        $mockSession->shouldReceive('getAttribute')->with('session_id')->andReturn('prahsys_test_session_123');
        $mockSession->shouldReceive('getAttribute')->with('status')->andReturn('completed');
        $mockSession->shouldReceive('update')->once();

        PrahsysPaymentSession::shouldReceive('where')
            ->with('session_id', 'prahsys_test_session_123')
            ->once()
            ->andReturnSelf();
        PrahsysPaymentSession::shouldReceive('first')
            ->once()
            ->andReturn($mockSession);

        // Mock transaction creation
        $mockTransaction = Mockery::mock(Transaction::class);
        $mockTransaction->shouldReceive('getAttribute')->with('success')->andReturn(true);
        $mockTransaction->shouldReceive('getAttribute')->with('status')->andReturn('captured');

        Transaction::shouldReceive('create')
            ->once()
            ->andReturn($mockTransaction);

        // Act
        $result = $this->driver->order($this->order, $data);

        // Assert
        $this->assertInstanceOf(Transaction::class, $result);
        $this->assertTrue($result->success);
        $this->assertEquals('captured', $result->status);
    }

    /** @test */
    public function it_can_process_refund()
    {
        // Arrange
        $this->order->shouldReceive('getAttribute')->with('id')->andReturn(1);
        $this->order->shouldReceive('getAttribute')->with('total')->andReturn((object)['value' => 2500, 'currency' => (object)['code' => 'USD']]);
        $this->order->shouldReceive('getAttribute')->with('reference')->andReturn('order_123');

        $data = [
            'amount' => 1000,
            'reference' => 'refund_123',
            'notes' => 'Customer requested refund',
        ];

        // Mock refund processing
        $refundData = [
            'refund_id' => 'refund_test_123',
            'amount' => 1000,
            'status' => 'completed',
            'processed_at' => now(),
        ];

        $this->prahsysService->shouldReceive('processRefund')
            ->once()
            ->with(Mockery::type('array'))
            ->andReturn($refundData);

        // Mock transaction creation
        $mockTransaction = Mockery::mock(Transaction::class);
        $mockTransaction->shouldReceive('getAttribute')->with('success')->andReturn(true);
        $mockTransaction->shouldReceive('getAttribute')->with('type')->andReturn('refund');

        Transaction::shouldReceive('create')
            ->once()
            ->andReturn($mockTransaction);

        // Act
        $result = $this->driver->refund($this->order, $data);

        // Assert
        $this->assertInstanceOf(Transaction::class, $result);
        $this->assertTrue($result->success);
        $this->assertEquals('refund', $result->type);
    }

    /** @test */
    public function it_handles_refund_failure()
    {
        // Arrange
        $this->order->shouldReceive('getAttribute')->with('id')->andReturn(1);
        $this->order->shouldReceive('getAttribute')->with('total')->andReturn((object)['value' => 2500, 'currency' => (object)['code' => 'USD']]);
        $this->order->shouldReceive('getAttribute')->with('reference')->andReturn('order_123');

        $data = [
            'amount' => 1000,
            'reference' => 'refund_123',
        ];

        $this->prahsysService->shouldReceive('processRefund')
            ->once()
            ->andThrow(new PrahsysException('Refund processing failed'));

        // Mock failed transaction creation
        $mockTransaction = Mockery::mock(Transaction::class);
        $mockTransaction->shouldReceive('getAttribute')->with('success')->andReturn(false);
        $mockTransaction->shouldReceive('getAttribute')->with('type')->andReturn('refund');

        Transaction::shouldReceive('create')
            ->once()
            ->andReturn($mockTransaction);

        // Act
        $result = $this->driver->refund($this->order, $data);

        // Assert
        $this->assertInstanceOf(Transaction::class, $result);
        $this->assertFalse($result->success);
        $this->assertEquals('refund', $result->type);
    }

    /** @test */
    public function it_validates_required_cart_data()
    {
        // Arrange
        $this->cart->shouldReceive('getAttribute')->with('id')->andReturn(null);

        // Act & Assert
        $this->expectException(PrahsysException::class);
        $this->expectExceptionMessage('Cart ID is required');

        $this->driver->cart($this->cart, []);
    }

    /** @test */
    public function it_validates_required_order_data()
    {
        // Arrange
        $this->order->shouldReceive('getAttribute')->with('id')->andReturn(null);

        // Act & Assert
        $this->expectException(PrahsysException::class);
        $this->expectExceptionMessage('Order ID is required');

        $this->driver->order($this->order, []);
    }

    /** @test */
    public function it_validates_refund_amount()
    {
        // Arrange
        $this->order->shouldReceive('getAttribute')->with('id')->andReturn(1);
        $this->order->shouldReceive('getAttribute')->with('total')->andReturn((object)['value' => 2500, 'currency' => (object)['code' => 'USD']]);

        // Act & Assert
        $this->expectException(PrahsysException::class);
        $this->expectExceptionMessage('Refund amount must be greater than zero');

        $this->driver->refund($this->order, ['amount' => 0]);
    }

    /** @test */
    public function it_validates_maximum_refund_amount()
    {
        // Arrange
        $this->order->shouldReceive('getAttribute')->with('id')->andReturn(1);
        $this->order->shouldReceive('getAttribute')->with('total')->andReturn((object)['value' => 2500, 'currency' => (object)['code' => 'USD']]);

        // Act & Assert
        $this->expectException(PrahsysException::class);
        $this->expectExceptionMessage('Refund amount cannot exceed order total');

        $this->driver->refund($this->order, ['amount' => 5000]);
    }

    /** @test */
    public function it_uses_default_payment_method_when_not_specified()
    {
        // Arrange
        $this->cart->shouldReceive('getAttribute')->with('id')->andReturn(1);
        $this->cart->shouldReceive('getAttribute')->with('total')->andReturn((object)['value' => 2500, 'currency' => (object)['code' => 'USD']]);
        $this->cart->shouldReceive('getAttribute')->with('user_id')->andReturn(null);
        $this->cart->shouldReceive('getAttribute')->with('channel_id')->andReturn(1);
        $this->cart->shouldReceive('getAttribute')->with('lines')->andReturn(collect([]));

        // Mock config call
        $this->app['config']->shouldReceive('get')
            ->with('lunar-prahsys.payment_methods.default', 'pay_portal')
            ->once()
            ->andReturn('pay_session');

        $this->prahsysService->shouldReceive('createPaymentSession')
            ->once()
            ->with(Mockery::on(function ($arg) {
                return $arg['payment_method'] === 'pay_session';
            }))
            ->andReturn([
                'session_id' => 'test_session',
                'payment_id' => 'test_payment',
                'status' => 'pending',
                'amount' => 2500,
                'currency' => 'USD',
                'expires_at' => now()->addHour(),
            ]);

        // Mock remaining dependencies
        $mockSession = Mockery::mock(PrahsysPaymentSession::class);
        $mockSession->shouldReceive('getAttribute')->with('session_id')->andReturn('test_session');

        PrahsysPaymentSession::shouldReceive('create')->once()->andReturn($mockSession);
        $this->cart->shouldReceive('update')->once();

        $mockTransaction = Mockery::mock(Transaction::class);
        $mockTransaction->shouldReceive('getAttribute')->with('success')->andReturn(true);
        Transaction::shouldReceive('create')->once()->andReturn($mockTransaction);

        // Act
        $result = $this->driver->cart($this->cart, []);

        // Assert
        $this->assertInstanceOf(Transaction::class, $result);
        $this->assertTrue($result->success);
    }
}