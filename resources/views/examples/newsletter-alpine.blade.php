{{--
  Alpine.js newsletter signup form.
  Uses the REST endpoint registered by this package.

  Usage: @include('shopify-marketing::examples.newsletter-alpine')
  Or publish with: wp acorn vendor:publish --tag=shopify-marketing-views
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
                const res = await fetch('/wp-json/{{ config('shopify-marketing.rest_namespace') }}/newsletter', {
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
