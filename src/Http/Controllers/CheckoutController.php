<?php

declare(strict_types=1);

namespace Prahsys\Lunar\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Lunar\Facades\CartSession;
use Lunar\Models\Cart;
use Lunar\Models\Order;
use Prahsys\LaravelClerk\Models\PrahsysPaymentSession;
use Prahsys\Lunar\Drivers\PrahsysPaymentDriver;
use Prahsys\LaravelClerk\Exceptions\PrahsysException;

class CheckoutController extends Controller
{
    public function __construct(
        protected PrahsysPaymentDriver $paymentDriver
    ) {}

    /**
     * Initiate checkout process for cart
     */
    public function create(Request $request): JsonResponse|RedirectResponse
    {
        try {
            $request->validate([
                'payment_method' => 'required|string|in:pay_portal,pay_session',
                'customer_email' => 'required_if:payment_method,pay_session|email',
                'customer_name' => 'required_if:payment_method,pay_session|string|max:255',
            ]);

            $cart = CartSession::current();
            
            if (!$cart || $cart->lines->isEmpty()) {
                return $this->errorResponse('Cart is empty', 400);
            }

            // Create payment transaction
            $transaction = $this->paymentDriver->cart($cart, $request->all());
            
            if (!$transaction || !$transaction->success) {
                return $this->errorResponse('Failed to initialize payment', 400);
            }

            // Get payment session details
            $session = PrahsysPaymentSession::where('session_id', $transaction->reference)->first();
            
            if (!$session) {
                return $this->errorResponse('Payment session not found', 400);
            }

            $response = [
                'success' => true,
                'transaction_id' => $transaction->id,
                'session_id' => $session->session_id,
                'payment_id' => $session->payment_id,
            ];

            // Return appropriate response based on payment method
            if ($request->input('payment_method') === 'pay_portal') {
                $response['redirect_url'] = $session->portal_url ?? $session->checkout_url;
                
                if ($request->expectsJson()) {
                    return response()->json($response);
                }
                
                return redirect($response['redirect_url']);
            }
            
            // For embedded checkout (pay_session)
            $response['checkout_url'] = $session->checkout_url;
            $response['session_config'] = $session->portal_configuration ?? [];
            
            return response()->json($response);
            
        } catch (PrahsysException $e) {
            return $this->errorResponse($e->getMessage(), 400);
        } catch (\Throwable $e) {
            logger()->error('Checkout creation failed', [
                'error' => $e->getMessage(),
                'request' => $request->all()
            ]);
            
            return $this->errorResponse('Checkout creation failed', 500);
        }
    }

    /**
     * Handle successful payment callback
     */
    public function success(Request $request): RedirectResponse
    {
        try {
            $sessionId = $request->input('session_id');
            
            if (!$sessionId) {
                return $this->redirectWithError('Missing session ID');
            }

            $session = PrahsysPaymentSession::where('session_id', $sessionId)->first();
            
            if (!$session) {
                return $this->redirectWithError('Payment session not found');
            }

            // Find the cart and convert to order if not already done
            $cart = Cart::where('meta->payment_id', $session->payment_id)->first();
            
            if (!$cart) {
                return $this->redirectWithError('Cart not found');
            }

            // Create order if it doesn't exist
            $order = Order::where('cart_id', $cart->id)->first();
            
            if (!$order) {
                $order = $cart->createOrder();
            }

            // Process the successful payment
            $this->paymentDriver->order($order, [
                'session_id' => $sessionId,
                'status' => 'captured'
            ]);

            // Clear the cart session
            CartSession::forget();

            return redirect()
                ->route('checkout.confirmation', ['order' => $order])
                ->with('success', 'Payment completed successfully!');
                
        } catch (\Throwable $e) {
            logger()->error('Checkout success handling failed', [
                'error' => $e->getMessage(),
                'request' => $request->all()
            ]);
            
            return $this->redirectWithError('Payment processing failed');
        }
    }

    /**
     * Handle payment cancellation
     */
    public function cancel(Request $request): RedirectResponse
    {
        $sessionId = $request->input('session_id');
        
        if ($sessionId) {
            $session = PrahsysPaymentSession::where('session_id', $sessionId)->first();
            
            if ($session) {
                // Find associated cart/order and mark payment as cancelled
                $cart = Cart::where('meta->payment_id', $session->payment_id)->first();
                
                if ($cart) {
                    $order = Order::where('cart_id', $cart->id)->first();
                    
                    if ($order) {
                        $this->paymentDriver->order($order, [
                            'session_id' => $sessionId,
                            'status' => 'cancelled'
                        ]);
                    }
                }
            }
        }

        return redirect()
            ->route('cart.show')
            ->with('error', 'Payment was cancelled. Please try again.');
    }

    /**
     * Get payment status
     */
    public function status(Request $request, string $sessionId): JsonResponse
    {
        try {
            $session = PrahsysPaymentSession::where('session_id', $sessionId)->first();
            
            if (!$session) {
                return response()->json(['error' => 'Session not found'], 404);
            }

            return response()->json([
                'session_id' => $session->session_id,
                'status' => $session->status,
                'amount' => $session->amount,
                'currency' => $session->currency,
                'completed_at' => $session->completed_at,
                'expires_at' => $session->expires_at,
                'is_expired' => $session->isExpired(),
                'is_completed' => $session->isCompleted(),
            ]);
            
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Failed to get payment status'], 500);
        }
    }

    /**
     * Handle webhook notifications (separate from main clerk webhooks)
     */
    public function webhook(Request $request): JsonResponse
    {
        try {
            // This webhook is specifically for Lunar integration updates
            $payload = $request->all();
            $sessionId = $payload['session_id'] ?? null;
            $status = $payload['status'] ?? null;
            
            if (!$sessionId || !$status) {
                return response()->json(['error' => 'Invalid webhook payload'], 400);
            }

            $session = PrahsysPaymentSession::where('session_id', $sessionId)->first();
            
            if (!$session) {
                return response()->json(['error' => 'Session not found'], 404);
            }

            // Find associated order and update transaction
            $cart = Cart::where('meta->payment_id', $session->payment_id)->first();
            
            if ($cart) {
                $order = Order::where('cart_id', $cart->id)->first();
                
                if ($order) {
                    $this->paymentDriver->order($order, [
                        'session_id' => $sessionId,
                        'status' => $status,
                        'webhook_data' => $payload
                    ]);
                }
            }

            return response()->json(['success' => true]);
            
        } catch (\Throwable $e) {
            logger()->error('Lunar webhook processing failed', [
                'error' => $e->getMessage(),
                'payload' => $request->all()
            ]);
            
            return response()->json(['error' => 'Webhook processing failed'], 500);
        }
    }

    /**
     * Return error response
     */
    protected function errorResponse(string $message, int $status = 400): JsonResponse
    {
        return response()->json(['error' => $message], $status);
    }

    /**
     * Redirect with error message
     */
    protected function redirectWithError(string $message): RedirectResponse
    {
        return redirect()
            ->route('cart.show')
            ->with('error', $message);
    }
}