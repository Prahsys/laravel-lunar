<?php

declare(strict_types=1);

namespace Prahsys\Lunar\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Lunar\Models\Cart;
use Lunar\Models\Order;
use Prahsys\LaravelClerk\Models\PrahsysPaymentSession;
use Prahsys\LaravelClerk\Models\PrahsysWebhookEvent;
use Prahsys\LaravelClerk\Services\WebhookService;
use Prahsys\Lunar\Drivers\PrahsysPaymentDriver;
use Prahsys\LaravelClerk\Exceptions\PrahsysException;

class WebhookController extends Controller
{
    public function __construct(
        protected WebhookService $webhookService,
        protected PrahsysPaymentDriver $paymentDriver
    ) {}

    /**
     * Handle Lunar-specific Prahsys webhooks
     */
    public function handle(Request $request): JsonResponse
    {
        try {
            // Verify webhook signature
            if (!$this->webhookService->verifySignature($request)) {
                Log::warning('Invalid webhook signature for Lunar integration', [
                    'headers' => $request->headers->all(),
                    'ip' => $request->ip(),
                ]);
                
                return response()->json(['error' => 'Invalid signature'], 401);
            }

            $payload = $request->json()->all();
            $eventType = $payload['type'] ?? null;
            $sessionId = $payload['data']['session_id'] ?? null;

            if (!$eventType || !$sessionId) {
                return response()->json(['error' => 'Invalid webhook payload'], 400);
            }

            // Store webhook event for audit trail
            $webhookEvent = PrahsysWebhookEvent::create([
                'event_id' => $payload['id'] ?? null,
                'event_type' => $eventType,
                'payload' => $payload,
                'processed_at' => null,
                'status' => 'received',
            ]);

            // Process the webhook based on event type
            $result = $this->processWebhookEvent($eventType, $sessionId, $payload, $webhookEvent);

            if ($result['success']) {
                $webhookEvent->update([
                    'processed_at' => now(),
                    'status' => 'processed',
                ]);
                
                return response()->json(['success' => true]);
            } else {
                $webhookEvent->update([
                    'status' => 'failed',
                    'failure_reason' => $result['error'] ?? 'Unknown error',
                ]);
                
                return response()->json(['error' => $result['error']], 400);
            }

        } catch (PrahsysException $e) {
            Log::error('Prahsys webhook processing failed', [
                'error' => $e->getMessage(),
                'payload' => $request->json()->all(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return response()->json(['error' => 'Webhook processing failed'], 500);
            
        } catch (\Throwable $e) {
            Log::error('Unexpected webhook processing error', [
                'error' => $e->getMessage(),
                'payload' => $request->json()->all(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return response()->json(['error' => 'Internal server error'], 500);
        }
    }

    /**
     * Process specific webhook event types for Lunar
     */
    protected function processWebhookEvent(
        string $eventType,
        string $sessionId,
        array $payload,
        PrahsysWebhookEvent $webhookEvent
    ): array {
        $session = PrahsysPaymentSession::where('session_id', $sessionId)->first();
        
        if (!$session) {
            return ['success' => false, 'error' => 'Payment session not found'];
        }

        // Find associated Lunar cart and order
        $cart = Cart::where('meta->payment_id', $session->payment_id)->first();
        
        if (!$cart) {
            return ['success' => false, 'error' => 'Cart not found'];
        }

        $order = Order::where('cart_id', $cart->id)->first();

        switch ($eventType) {
            case 'payment.completed':
                return $this->handlePaymentCompleted($session, $cart, $order, $payload);
                
            case 'payment.failed':
                return $this->handlePaymentFailed($session, $cart, $order, $payload);
                
            case 'payment.cancelled':
                return $this->handlePaymentCancelled($session, $cart, $order, $payload);
                
            case 'payment.refunded':
                return $this->handlePaymentRefunded($session, $cart, $order, $payload);
                
            case 'payment.partially_refunded':
                return $this->handlePartialRefund($session, $cart, $order, $payload);
                
            default:
                Log::info('Unhandled Lunar webhook event type', [
                    'event_type' => $eventType,
                    'session_id' => $sessionId,
                ]);
                
                return ['success' => true]; // Don't fail for unknown events
        }
    }

    /**
     * Handle successful payment completion
     */
    protected function handlePaymentCompleted(
        PrahsysPaymentSession $session,
        Cart $cart,
        ?Order $order,
        array $payload
    ): array {
        try {
            // Create order if it doesn't exist
            if (!$order) {
                $order = $cart->createOrder();
                
                if (!$order) {
                    return ['success' => false, 'error' => 'Failed to create order'];
                }
            }

            // Update payment status via driver
            $transaction = $this->paymentDriver->order($order, [
                'session_id' => $session->session_id,
                'status' => 'captured',
                'webhook_data' => $payload,
                'amount' => $payload['data']['amount'] ?? null,
                'reference' => $payload['data']['transaction_id'] ?? null,
            ]);

            if (!$transaction || !$transaction->success) {
                return ['success' => false, 'error' => 'Failed to update payment status'];
            }

            // Update session status
            $session->update([
                'status' => 'completed',
                'completed_at' => now(),
            ]);

            // Log successful completion
            Log::info('Lunar payment completed via webhook', [
                'session_id' => $session->session_id,
                'order_id' => $order->id,
                'amount' => $session->amount,
            ]);

            return ['success' => true];
            
        } catch (\Throwable $e) {
            Log::error('Failed to handle payment completion', [
                'session_id' => $session->session_id,
                'error' => $e->getMessage(),
            ]);
            
            return ['success' => false, 'error' => 'Payment completion handling failed'];
        }
    }

    /**
     * Handle payment failure
     */
    protected function handlePaymentFailed(
        PrahsysPaymentSession $session,
        Cart $cart,
        ?Order $order,
        array $payload
    ): array {
        try {
            if ($order) {
                $this->paymentDriver->order($order, [
                    'session_id' => $session->session_id,
                    'status' => 'failed',
                    'webhook_data' => $payload,
                    'failure_reason' => $payload['data']['failure_reason'] ?? 'Payment failed',
                ]);
            }

            $session->update([
                'status' => 'failed',
                'failure_reason' => $payload['data']['failure_reason'] ?? 'Payment failed',
            ]);

            Log::info('Lunar payment failed via webhook', [
                'session_id' => $session->session_id,
                'reason' => $payload['data']['failure_reason'] ?? 'Unknown',
            ]);

            return ['success' => true];
            
        } catch (\Throwable $e) {
            Log::error('Failed to handle payment failure', [
                'session_id' => $session->session_id,
                'error' => $e->getMessage(),
            ]);
            
            return ['success' => false, 'error' => 'Payment failure handling failed'];
        }
    }

    /**
     * Handle payment cancellation
     */
    protected function handlePaymentCancelled(
        PrahsysPaymentSession $session,
        Cart $cart,
        ?Order $order,
        array $payload
    ): array {
        try {
            if ($order) {
                $this->paymentDriver->order($order, [
                    'session_id' => $session->session_id,
                    'status' => 'cancelled',
                    'webhook_data' => $payload,
                ]);
            }

            $session->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
            ]);

            Log::info('Lunar payment cancelled via webhook', [
                'session_id' => $session->session_id,
            ]);

            return ['success' => true];
            
        } catch (\Throwable $e) {
            Log::error('Failed to handle payment cancellation', [
                'session_id' => $session->session_id,
                'error' => $e->getMessage(),
            ]);
            
            return ['success' => false, 'error' => 'Payment cancellation handling failed'];
        }
    }

    /**
     * Handle payment refund
     */
    protected function handlePaymentRefunded(
        PrahsysPaymentSession $session,
        Cart $cart,
        ?Order $order,
        array $payload
    ): array {
        try {
            if (!$order) {
                return ['success' => false, 'error' => 'Order not found for refund'];
            }

            $refundAmount = $payload['data']['refund_amount'] ?? $session->amount;
            $refundReference = $payload['data']['refund_id'] ?? null;

            $transaction = $this->paymentDriver->refund($order, [
                'amount' => $refundAmount,
                'reference' => $refundReference,
                'webhook_data' => $payload,
                'notes' => $payload['data']['refund_reason'] ?? 'Refund via webhook',
            ]);

            if (!$transaction || !$transaction->success) {
                return ['success' => false, 'error' => 'Failed to process refund'];
            }

            Log::info('Lunar payment refunded via webhook', [
                'session_id' => $session->session_id,
                'order_id' => $order->id,
                'refund_amount' => $refundAmount,
                'refund_reference' => $refundReference,
            ]);

            return ['success' => true];
            
        } catch (\Throwable $e) {
            Log::error('Failed to handle payment refund', [
                'session_id' => $session->session_id,
                'error' => $e->getMessage(),
            ]);
            
            return ['success' => false, 'error' => 'Payment refund handling failed'];
        }
    }

    /**
     * Handle partial refund
     */
    protected function handlePartialRefund(
        PrahsysPaymentSession $session,
        Cart $cart,
        ?Order $order,
        array $payload
    ): array {
        // Partial refunds are handled the same way as full refunds
        // The amount difference is in the payload
        return $this->handlePaymentRefunded($session, $cart, $order, $payload);
    }

    /**
     * Get webhook processing status for debugging
     */
    public function status(Request $request): JsonResponse
    {
        $eventId = $request->input('event_id');
        
        if (!$eventId) {
            return response()->json(['error' => 'Event ID required'], 400);
        }

        $webhookEvent = PrahsysWebhookEvent::where('event_id', $eventId)->first();
        
        if (!$webhookEvent) {
            return response()->json(['error' => 'Webhook event not found'], 404);
        }

        return response()->json([
            'event_id' => $webhookEvent->event_id,
            'event_type' => $webhookEvent->event_type,
            'status' => $webhookEvent->status,
            'processed_at' => $webhookEvent->processed_at,
            'failure_reason' => $webhookEvent->failure_reason,
            'created_at' => $webhookEvent->created_at,
        ]);
    }
}