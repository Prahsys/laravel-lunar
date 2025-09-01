<?php

declare(strict_types=1);

namespace Prahsys\Lunar\Drivers;

use Illuminate\Http\Request;
use Lunar\Base\PaymentDriverInterface;
use Lunar\Models\Cart;
use Lunar\Models\Order;
use Lunar\Models\Transaction;
use Prahsys\LaravelClerk\Models\PrahsysPaymentSession;
use Prahsys\LaravelClerk\Services\PaymentSessionManager;
use Prahsys\LaravelClerk\Exceptions\PrahsysException;

class PrahsysPaymentDriver implements PaymentDriverInterface
{
    public function __construct(
        protected PaymentSessionManager $paymentManager
    ) {}

    /**
     * Authorize payment for the given cart
     */
    public function cart(Cart $cart, array $data = []): ?Transaction
    {
        try {
            $paymentMethod = $data['payment_method'] ?? config('lunar-prahsys.payment_methods.default');
            
            // Create payment session
            $session = $this->createPaymentSession($cart, $paymentMethod, $data);
            
            // Create pending transaction
            $transaction = $this->createTransaction($cart, $session, 'pending');
            
            return $transaction;
            
        } catch (PrahsysException $e) {
            // Log error and return failed transaction
            logger()->error('Prahsys payment authorization failed', [
                'cart_id' => $cart->id,
                'error' => $e->getMessage(),
                'data' => $data
            ]);
            
            return $this->createTransaction($cart, null, 'failed', [
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Handle payment response/callback
     */
    public function order(Order $order, array $data = []): ?Transaction
    {
        try {
            $sessionId = $data['session_id'] ?? null;
            $status = $data['status'] ?? 'pending';
            
            if (!$sessionId) {
                throw new PrahsysException('Missing session ID in payment response');
            }
            
            $session = PrahsysPaymentSession::where('session_id', $sessionId)->first();
            
            if (!$session) {
                throw new PrahsysException("Payment session not found: {$sessionId}");
            }
            
            // Update order based on payment status
            return match($status) {
                'captured', 'authorized' => $this->handleSuccessfulPayment($order, $session, $data),
                'failed', 'cancelled' => $this->handleFailedPayment($order, $session, $data),
                default => $this->handlePendingPayment($order, $session, $data)
            };
            
        } catch (PrahsysException $e) {
            logger()->error('Prahsys order processing failed', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
                'data' => $data
            ]);
            
            return $this->createOrderTransaction($order, null, 'failed', [
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Process refund for the given transaction
     */
    public function refund(Transaction $transaction, int $amount = null, string $notes = null): Transaction
    {
        try {
            // Get the original payment session
            $session = PrahsysPaymentSession::where('payment_id', $transaction->reference)->first();
            
            if (!$session) {
                throw new PrahsysException('Original payment session not found');
            }
            
            $refundAmount = $amount ?? $transaction->amount->value;
            
            // Process refund through payment manager
            $refundSession = $this->paymentManager->processRefund(
                $session->payment_id,
                $refundAmount / 100, // Convert cents to dollars
                $notes ?? 'Lunar order refund'
            );
            
            // Create refund transaction
            return Transaction::create([
                'order_id' => $transaction->order_id,
                'success' => true,
                'type' => 'refund',
                'driver' => 'prahsys',
                'amount' => $refundAmount,
                'reference' => $refundSession->session_id,
                'status' => 'refunded',
                'notes' => $notes,
                'card_type' => $transaction->card_type,
                'last_four' => $transaction->last_four,
                'captured_at' => now(),
                'meta' => [
                    'refund_amount' => $refundAmount,
                    'original_transaction_id' => $transaction->id,
                    'session_id' => $refundSession->session_id,
                ]
            ]);
            
        } catch (PrahsysException $e) {
            logger()->error('Prahsys refund failed', [
                'transaction_id' => $transaction->id,
                'amount' => $amount,
                'error' => $e->getMessage()
            ]);
            
            return Transaction::create([
                'order_id' => $transaction->order_id,
                'success' => false,
                'type' => 'refund',
                'driver' => 'prahsys',
                'amount' => $amount ?? $transaction->amount->value,
                'reference' => $transaction->reference,
                'status' => 'failed',
                'notes' => "Refund failed: {$e->getMessage()}",
                'meta' => [
                    'error' => $e->getMessage(),
                    'original_transaction_id' => $transaction->id,
                ]
            ]);
        }
    }

    /**
     * Create Prahsys payment session for cart
     */
    protected function createPaymentSession(Cart $cart, string $paymentMethod, array $data): PrahsysPaymentSession
    {
        $amount = $cart->total->value / 100; // Convert cents to dollars
        $currency = $cart->currency->code;
        
        $customerEmail = $data['customer_email'] 
            ?? $cart->user?->email 
            ?? $cart->meta['guest_email'] 
            ?? null;
            
        $customerName = $data['customer_name']
            ?? $cart->user?->name
            ?? $cart->meta['guest_name']
            ?? 'Guest Customer';
        
        // Create payment session based on method
        return match($paymentMethod) {
            'pay_portal' => $this->paymentManager->createPortalSession(
                paymentId: "lunar-cart-{$cart->id}",
                amount: $amount,
                description: "Order for Cart #{$cart->id}",
                returnUrl: url(config('lunar-prahsys.checkout.success_url')),
                cancelUrl: url(config('lunar-prahsys.checkout.cancel_url')),
                merchantName: config('app.name'),
                merchantLogo: null
            ),
            
            'pay_session' => $this->paymentManager->createPaymentSession(
                paymentId: "lunar-cart-{$cart->id}",
                amount: $amount,
                description: "Order for Cart #{$cart->id}",
                customerEmail: $customerEmail,
                customerName: $customerName,
                currency: $currency
            ),
            
            default => throw new PrahsysException("Unsupported payment method: {$paymentMethod}")
        };
    }

    /**
     * Create transaction for cart
     */
    protected function createTransaction(Cart $cart, ?PrahsysPaymentSession $session, string $status, array $meta = []): Transaction
    {
        return Transaction::create([
            'success' => $status === 'captured' || $status === 'authorized',
            'type' => 'capture',
            'driver' => 'prahsys',
            'amount' => $cart->total->value,
            'reference' => $session?->session_id ?? 'failed',
            'status' => $status,
            'card_type' => $session?->card_brand,
            'last_four' => $session?->card_last4,
            'captured_at' => $status === 'captured' ? now() : null,
            'meta' => array_merge([
                'cart_id' => $cart->id,
                'session_id' => $session?->session_id,
                'payment_id' => $session?->payment_id,
                'checkout_url' => $session?->checkout_url,
            ], $meta)
        ]);
    }

    /**
     * Create transaction for order
     */
    protected function createOrderTransaction(Order $order, ?PrahsysPaymentSession $session, string $status, array $meta = []): Transaction
    {
        return Transaction::create([
            'order_id' => $order->id,
            'success' => $status === 'captured' || $status === 'authorized',
            'type' => 'capture',
            'driver' => 'prahsys',
            'amount' => $order->total->value,
            'reference' => $session?->session_id ?? 'failed',
            'status' => $status,
            'card_type' => $session?->card_brand,
            'last_four' => $session?->card_last4,
            'captured_at' => $status === 'captured' ? now() : null,
            'meta' => array_merge([
                'order_id' => $order->id,
                'session_id' => $session?->session_id,
                'payment_id' => $session?->payment_id,
            ], $meta)
        ]);
    }

    /**
     * Handle successful payment
     */
    protected function handleSuccessfulPayment(Order $order, PrahsysPaymentSession $session, array $data): Transaction
    {
        $transaction = $this->createOrderTransaction($order, $session, 'captured', $data);
        
        // Auto-fulfill if configured
        if (config('lunar-prahsys.orders.auto_fulfill')) {
            $order->update(['status' => 'fulfilled']);
        }
        
        return $transaction;
    }

    /**
     * Handle failed payment
     */
    protected function handleFailedPayment(Order $order, PrahsysPaymentSession $session, array $data): Transaction
    {
        return $this->createOrderTransaction($order, $session, 'failed', array_merge($data, [
            'failure_reason' => $data['failure_reason'] ?? 'Payment failed'
        ]));
    }

    /**
     * Handle pending payment
     */
    protected function handlePendingPayment(Order $order, PrahsysPaymentSession $session, array $data): Transaction
    {
        return $this->createOrderTransaction($order, $session, 'pending', $data);
    }
}