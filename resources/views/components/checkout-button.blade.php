@props([
    'cart' => null,
    'disabled' => false,
    'loading' => false
])

@php
    $cart = $cart ?? $getCart();
    $hasItems = $hasItems();
    $isDisabled = $disabled || !$hasItems || $loading;
    $paymentConfig = $getPaymentMethodConfig();
    $requiresCustomer = $requiresCustomerInfo();
@endphp

<div class="prahsys-checkout-wrapper" 
     x-data="prahsysCheckout(@js([
         'payment_method' => $paymentMethod,
         'requires_customer_info' => $requiresCustomer,
         'checkout_url' => $getCheckoutUrl(),
         'total' => $getFormattedTotal()
     ]))"
     x-init="init()">
     
    @if(!$hasItems)
        <div class="alert alert-warning">
            <p>Your cart is empty. Add items to proceed with checkout.</p>
        </div>
    @else
        <form @submit.prevent="submitCheckout" class="prahsys-checkout-form">
            @if($requiresCustomer)
                <div class="customer-info-section mb-4">
                    <h4>Customer Information</h4>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="customer_name" class="form-label">Full Name</label>
                            <input type="text" 
                                   class="form-control" 
                                   id="customer_name"
                                   x-model="customerInfo.name"
                                   :disabled="loading"
                                   required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="customer_email" class="form-label">Email Address</label>
                            <input type="email" 
                                   class="form-control" 
                                   id="customer_email"
                                   x-model="customerInfo.email"
                                   :disabled="loading"
                                   required>
                        </div>
                    </div>
                </div>
            @endif

            <div class="checkout-summary mb-4">
                <div class="d-flex justify-content-between align-items-center">
                    <span class="checkout-total-label">Total:</span>
                    <span class="checkout-total-amount fw-bold">{{ $getFormattedTotal() }}</span>
                </div>
            </div>

            <button type="submit" 
                    class="{{ $class }}"
                    :disabled="loading || !canCheckout"
                    :class="{ 'btn-loading': loading }">
                <span x-show="!loading">{{ $buttonText }}</span>
                <span x-show="loading" class="d-flex align-items-center">
                    <span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>
                    Processing...
                </span>
            </button>
        </form>

        <!-- Error display -->
        <div x-show="error" 
             x-transition 
             class="alert alert-danger mt-3"
             x-text="error">
        </div>
    @endif
</div>

<script>
function prahsysCheckout(config) {
    return {
        loading: false,
        error: null,
        customerInfo: {
            name: '',
            email: ''
        },
        
        init() {
            // Initialize component
            console.log('Prahsys Checkout initialized', config);
        },
        
        get canCheckout() {
            if (!config.requires_customer_info) return true;
            return this.customerInfo.name.trim() !== '' && 
                   this.customerInfo.email.trim() !== '' &&
                   this.isValidEmail(this.customerInfo.email);
        },
        
        isValidEmail(email) {
            return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
        },
        
        async submitCheckout() {
            this.loading = true;
            this.error = null;
            
            try {
                const formData = {
                    payment_method: config.payment_method,
                };
                
                if (config.requires_customer_info) {
                    formData.customer_name = this.customerInfo.name;
                    formData.customer_email = this.customerInfo.email;
                }
                
                const response = await fetch(config.checkout_url, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content'),
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: JSON.stringify(formData)
                });
                
                const data = await response.json();
                
                if (!response.ok) {
                    throw new Error(data.error || 'Checkout failed');
                }
                
                if (data.success) {
                    this.handleCheckoutSuccess(data);
                } else {
                    throw new Error('Checkout failed');
                }
                
            } catch (error) {
                this.error = error.message;
                console.error('Checkout error:', error);
            } finally {
                this.loading = false;
            }
        },
        
        handleCheckoutSuccess(data) {
            if (config.payment_method === 'pay_portal' && data.redirect_url) {
                // Redirect to hosted checkout
                window.location.href = data.redirect_url;
            } else if (config.payment_method === 'pay_session' && data.checkout_url) {
                // Handle embedded checkout
                this.initializeEmbeddedCheckout(data);
            }
        },
        
        initializeEmbeddedCheckout(data) {
            // This would integrate with Prahsys embedded checkout SDK
            console.log('Initialize embedded checkout', data);
            // Implementation would depend on Prahsys SDK
        }
    }
}
</script>

<style>
.prahsys-checkout-wrapper {
    max-width: 500px;
}

.btn-loading {
    position: relative;
    pointer-events: none;
}

.checkout-total-amount {
    font-size: 1.25rem;
    color: var(--bs-success, #198754);
}

.customer-info-section {
    border: 1px solid var(--bs-border-color, #dee2e6);
    border-radius: 0.375rem;
    padding: 1rem;
    background-color: var(--bs-light, #f8f9fa);
}

.prahsys-checkout-form .form-control:focus {
    border-color: var(--bs-primary, #0d6efd);
    box-shadow: 0 0 0 0.2rem rgba(13, 110, 253, 0.25);
}
</style>