<?php

declare(strict_types=1);

namespace Prahsys\Lunar\Tests\Feature\Http\Controllers;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;
use Lunar\Models\Cart;
use Lunar\Models\Order;
use Prahsys\LaravelClerk\Models\PrahsysPaymentSession;
use Prahsys\LaravelClerk\Models\PrahsysWebhookEvent;
use Prahsys\LaravelClerk\Services\WebhookService;
use Prahsys\Lunar\Drivers\PrahsysPaymentDriver;

class WebhookControllerTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    protected PrahsysPaymentSession $session;
    protected Cart $cart;
    protected Order $order;

    protected function setUp(): void
    {
        parent::setUp();

        // Create test entities
        $this->session = PrahsysPaymentSession::factory()->create([
            'session_id' => 'test_session_123',
            'payment_id' => 'pay_test_123',
        ]);

        $this->cart = Cart::factory()->create();
        $this->cart->update(['meta' => ['payment_id' => 'pay_test_123']]);

        $this->order = Order::factory()->create(['cart_id' => $this->cart->id]);
    }

    /** @test */
    public function it_can_handle_payment_completed_webhook()
    {
        // Mock webhook service
        $mockWebhookService = $this->mock(WebhookService::class);
        $mockWebhookService->shouldReceive('verifySignature')
            ->once()
            ->andReturn(true);

        // Mock payment driver
        $mockDriver = $this->mock(PrahsysPaymentDriver::class);
        $mockDriver->shouldReceive('order')
            ->once()
            ->andReturn((object)['success' => true]);

        $payload = [
            'id' => 'evt_test_123',
            'type' => 'payment.completed',
            'data' => [
                'session_id' => 'test_session_123',
                'amount' => 2500,
                'transaction_id' => 'txn_test_123',
            ],
        ];

        // Act
        $response = $this->postJson('/prahsys/webhooks/lunar', $payload, [
            'X-Prahsys-Signature' => 'valid_signature'
        ]);

        // Assert
        $response->assertSuccessful()
            ->assertJson(['success' => true]);

        // Verify webhook event was stored
        $this->assertDatabaseHas('prahsys_webhook_events', [
            'event_id' => 'evt_test_123',
            'event_type' => 'payment.completed',
            'status' => 'processed',
        ]);

        // Verify session was updated
        $this->session->refresh();
        $this->assertEquals('completed', $this->session->status);
        $this->assertNotNull($this->session->completed_at);
    }

    /** @test */
    public function it_can_handle_payment_failed_webhook()
    {
        // Mock webhook service
        $mockWebhookService = $this->mock(WebhookService::class);
        $mockWebhookService->shouldReceive('verifySignature')
            ->once()
            ->andReturn(true);

        // Mock payment driver
        $mockDriver = $this->mock(PrahsysPaymentDriver::class);
        $mockDriver->shouldReceive('order')
            ->once()
            ->andReturn((object)['success' => true]);

        $payload = [
            'id' => 'evt_test_124',
            'type' => 'payment.failed',
            'data' => [
                'session_id' => 'test_session_123',
                'failure_reason' => 'Insufficient funds',
            ],
        ];

        // Act
        $response = $this->postJson('/prahsys/webhooks/lunar', $payload, [
            'X-Prahsys-Signature' => 'valid_signature'
        ]);

        // Assert
        $response->assertSuccessful()
            ->assertJson(['success' => true]);

        // Verify session was updated
        $this->session->refresh();
        $this->assertEquals('failed', $this->session->status);
        $this->assertEquals('Insufficient funds', $this->session->failure_reason);
    }

    /** @test */
    public function it_can_handle_payment_refunded_webhook()
    {
        // Mock webhook service
        $mockWebhookService = $this->mock(WebhookService::class);
        $mockWebhookService->shouldReceive('verifySignature')
            ->once()
            ->andReturn(true);

        // Mock payment driver
        $mockDriver = $this->mock(PrahsysPaymentDriver::class);
        $mockDriver->shouldReceive('refund')
            ->once()
            ->andReturn((object)['success' => true]);

        $payload = [
            'id' => 'evt_test_125',
            'type' => 'payment.refunded',
            'data' => [
                'session_id' => 'test_session_123',
                'refund_amount' => 2500,
                'refund_id' => 'rf_test_123',
                'refund_reason' => 'Customer requested refund',
            ],
        ];

        // Act
        $response = $this->postJson('/prahsys/webhooks/lunar', $payload, [
            'X-Prahsys-Signature' => 'valid_signature'
        ]);

        // Assert
        $response->assertSuccessful()
            ->assertJson(['success' => true]);

        // Verify webhook event was stored
        $this->assertDatabaseHas('prahsys_webhook_events', [
            'event_id' => 'evt_test_125',
            'event_type' => 'payment.refunded',
            'status' => 'processed',
        ]);
    }

    /** @test */
    public function it_can_handle_partial_refund_webhook()
    {
        // Mock webhook service
        $mockWebhookService = $this->mock(WebhookService::class);
        $mockWebhookService->shouldReceive('verifySignature')
            ->once()
            ->andReturn(true);

        // Mock payment driver
        $mockDriver = $this->mock(PrahsysPaymentDriver::class);
        $mockDriver->shouldReceive('refund')
            ->once()
            ->andReturn((object)['success' => true]);

        $payload = [
            'id' => 'evt_test_126',
            'type' => 'payment.partially_refunded',
            'data' => [
                'session_id' => 'test_session_123',
                'refund_amount' => 1000,
                'refund_id' => 'rf_test_124',
            ],
        ];

        // Act
        $response = $this->postJson('/prahsys/webhooks/lunar', $payload, [
            'X-Prahsys-Signature' => 'valid_signature'
        ]);

        // Assert
        $response->assertSuccessful()
            ->assertJson(['success' => true]);
    }

    /** @test */
    public function it_rejects_webhooks_with_invalid_signature()
    {
        // Mock webhook service
        $mockWebhookService = $this->mock(WebhookService::class);
        $mockWebhookService->shouldReceive('verifySignature')
            ->once()
            ->andReturn(false);

        $payload = [
            'type' => 'payment.completed',
            'data' => ['session_id' => 'test_session_123'],
        ];

        // Act
        $response = $this->postJson('/prahsys/webhooks/lunar', $payload, [
            'X-Prahsys-Signature' => 'invalid_signature'
        ]);

        // Assert
        $response->assertStatus(401)
            ->assertJson(['error' => 'Invalid signature']);
    }

    /** @test */
    public function it_validates_webhook_payload_structure()
    {
        // Mock webhook service
        $mockWebhookService = $this->mock(WebhookService::class);
        $mockWebhookService->shouldReceive('verifySignature')
            ->once()
            ->andReturn(true);

        $payload = [
            'type' => 'payment.completed',
            // Missing data.session_id
        ];

        // Act
        $response = $this->postJson('/prahsys/webhooks/lunar', $payload, [
            'X-Prahsys-Signature' => 'valid_signature'
        ]);

        // Assert
        $response->assertStatus(400)
            ->assertJson(['error' => 'Invalid webhook payload']);
    }

    /** @test */
    public function it_handles_unknown_session_id()
    {
        // Mock webhook service
        $mockWebhookService = $this->mock(WebhookService::class);
        $mockWebhookService->shouldReceive('verifySignature')
            ->once()
            ->andReturn(true);

        $payload = [
            'id' => 'evt_test_127',
            'type' => 'payment.completed',
            'data' => [
                'session_id' => 'unknown_session_456',
            ],
        ];

        // Act
        $response = $this->postJson('/prahsys/webhooks/lunar', $payload, [
            'X-Prahsys-Signature' => 'valid_signature'
        ]);

        // Assert
        $response->assertStatus(400)
            ->assertJson(['error' => 'Payment session not found']);

        // Verify webhook event was stored as failed
        $this->assertDatabaseHas('prahsys_webhook_events', [
            'event_id' => 'evt_test_127',
            'event_type' => 'payment.completed',
            'status' => 'failed',
            'failure_reason' => 'Payment session not found',
        ]);
    }

    /** @test */
    public function it_handles_unknown_event_types_gracefully()
    {
        // Mock webhook service
        $mockWebhookService = $this->mock(WebhookService::class);
        $mockWebhookService->shouldReceive('verifySignature')
            ->once()
            ->andReturn(true);

        $payload = [
            'id' => 'evt_test_128',
            'type' => 'payment.unknown_event',
            'data' => [
                'session_id' => 'test_session_123',
            ],
        ];

        // Act
        $response = $this->postJson('/prahsys/webhooks/lunar', $payload, [
            'X-Prahsys-Signature' => 'valid_signature'
        ]);

        // Assert
        $response->assertSuccessful()
            ->assertJson(['success' => true]);

        // Verify webhook event was stored as processed
        $this->assertDatabaseHas('prahsys_webhook_events', [
            'event_id' => 'evt_test_128',
            'event_type' => 'payment.unknown_event',
            'status' => 'processed',
        ]);
    }

    /** @test */
    public function it_can_get_webhook_status()
    {
        // Create webhook event
        $webhookEvent = PrahsysWebhookEvent::factory()->create([
            'event_id' => 'evt_test_129',
            'event_type' => 'payment.completed',
            'status' => 'processed',
            'processed_at' => now(),
        ]);

        // Act
        $response = $this->getJson('/prahsys/webhooks/status?event_id=evt_test_129');

        // Assert
        $response->assertSuccessful()
            ->assertJson([
                'event_id' => 'evt_test_129',
                'event_type' => 'payment.completed',
                'status' => 'processed',
            ]);
    }

    /** @test */
    public function it_handles_webhook_processing_failure()
    {
        // Mock webhook service
        $mockWebhookService = $this->mock(WebhookService::class);
        $mockWebhookService->shouldReceive('verifySignature')
            ->once()
            ->andReturn(true);

        // Mock payment driver to throw exception
        $mockDriver = $this->mock(PrahsysPaymentDriver::class);
        $mockDriver->shouldReceive('order')
            ->once()
            ->andThrow(new \Exception('Database error'));

        $payload = [
            'id' => 'evt_test_130',
            'type' => 'payment.completed',
            'data' => [
                'session_id' => 'test_session_123',
            ],
        ];

        // Act
        $response = $this->postJson('/prahsys/webhooks/lunar', $payload, [
            'X-Prahsys-Signature' => 'valid_signature'
        ]);

        // Assert
        $response->assertStatus(400);

        // Verify webhook event was stored as failed
        $this->assertDatabaseHas('prahsys_webhook_events', [
            'event_id' => 'evt_test_130',
            'status' => 'failed',
        ]);
    }
}