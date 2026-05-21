{{--
  Sage HTML Forms newsletter signup.
  Requires Log1x/sage-html-forms: composer require log1x/sage-html-forms

  Create a form in the WordPress admin (HTML Forms → Add New) and give it
  the slug configured in config('shopify-marketing.html_forms_slug'), default "newsletter".

  This view replaces the form markup via Blade. The email field name must be
  "email" so the package can detect it and forward to Shopify.

  Usage: @include('shopify-marketing::examples.newsletter-html-forms', ['form' => $form->ID])
  Or publish with: wp acorn vendor:publish --tag=shopify-marketing-views
--}}

<x-html-forms :form="$form">
  <input
    name="email"
    type="email"
    placeholder="{{ __('Enter your email', 'shopify-marketing') }}"
    required
  >

  <input
    type="submit"
    value="{{ __('Subscribe', 'shopify-marketing') }}"
  />
</x-html-forms>
