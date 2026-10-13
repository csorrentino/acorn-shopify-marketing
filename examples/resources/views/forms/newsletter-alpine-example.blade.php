{{--
  Alpine.js newsletter signup form example.
  Copy to: resources/views/forms/newsletter.blade.php

  Requires Alpine.js on the page and the plugin's REST endpoint active.
  This view POSTs to the configured REST endpoint. It sends no nonce, so it
  keeps working on cached pages. The hidden honeypot field catches bots.

  Uncomment the fields below to send firstName, lastName, and phone to
  Shopify alongside the email address. All are optional.
--}}

<div
    x-data="{
        email: '',
        firstName: '',
        lastName: '',
        phone: '',
        honeypot: '',
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
                    },
                    body: JSON.stringify({
                        email: this.email,
                        firstName: this.firstName || undefined,
                        lastName: this.lastName || undefined,
                        phone: this.phone || undefined,
                        '{{ config('shopify-marketing.honeypot_field', 'website') }}': this.honeypot || undefined,
                    }),
                });

                const data = await res.json();

                if (data.success) {
                    this.success = true;
                    this.email = '';
                    this.firstName = '';
                    this.lastName = '';
                    this.phone = '';
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

        {{-- Honeypot: hidden from people, so only bots fill it in. --}}
        <div aria-hidden="true" style="position:absolute;left:-10000px;width:1px;height:1px;overflow:hidden;">
            <label>{{ __('Leave this field empty', 'shopify-marketing') }}
                <input type="text" x-model="honeypot" tabindex="-1" autocomplete="off">
            </label>
        </div>

        {{-- Uncomment to collect first name --}}
        {{-- <input type="text" x-model="firstName" placeholder="{{ __('First name', 'shopify-marketing') }}"> --}}

        {{-- Uncomment to collect last name --}}
        {{-- <input type="text" x-model="lastName" placeholder="{{ __('Last name', 'shopify-marketing') }}"> --}}

        <input
            type="email"
            x-model="email"
            placeholder="{{ __('Enter your email', 'shopify-marketing') }}"
            required
            :disabled="loading"
        >

        {{-- Uncomment to collect phone (E.164 format recommended) --}}
        {{-- <input type="tel" x-model="phone" placeholder="{{ __('Phone', 'shopify-marketing') }}"> --}}

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
