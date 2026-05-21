{{--
  Alpine.js newsletter signup form example.
  Copy to: resources/views/forms/newsletter.blade.php

  Requires Alpine.js on the page and the plugin's REST endpoint active.
  This view POSTs to the configured REST endpoint with a nonce.
--}}

<div
    x-data="{
        email: '',
        loading: false,
        success: false,
        error: '',
        async submitForm() {
            this.loading = true;
            this.error = '';
            this.success = false;

            try {
                const res = await fetch('{{ rest_url(config('shopify-marketing.rest_namespace') . config('shopify-marketing.rest_route')) }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': '{{ wp_create_nonce('wp_rest') }}',
                    },
                    body: JSON.stringify({ email: this.email }),
                });

                const data = await res.json();

                if (data.success) {
                    this.success = true;
                    this.email = '';
                } else {
                    this.error = data.message || 'Something went wrong.';
                }
            } catch (e) {
                this.error = 'Network error. Please try again.';
            } finally {
                this.loading = false;
            }
        }
    }"
    x-cloak
>
    <form @submit.prevent="submitForm">
        <input
            type="email"
            x-model="email"
            placeholder="{{ __('Enter your email', 'shopify-marketing') }}"
            required
            :disabled="loading"
        >

        <button
            type="submit"
            :disabled="loading || !email"
            x-text="loading ? '{{ __('Subscribing...', 'shopify-marketing') }}' : '{{ __('Subscribe', 'shopify-marketing') }}'"
        >Subscribe</button>
    </form>

    <div x-show="success" x-transition>
        <p>{{ __("You're on the list! Thanks for signing up.", 'shopify-marketing') }}</p>
    </div>

    <div x-show="error" x-transition>
        <p x-text="error"></p>
    </div>
</div>
