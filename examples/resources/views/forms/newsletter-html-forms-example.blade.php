{{--
  Sage HTML Forms newsletter signup.
  Place in: resources/views/forms/newsletter.blade.php

  Requires Log1x/sage-html-forms: composer require log1x/sage-html-forms

  Create a form in WordPress admin (HTML Forms → Add New) with the slug
  configured in config('shopify-marketing.html_forms_slug'), default "newsletter".
  Then @include it as: @include('forms.newsletter', ['form' => 'newsletter'])
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
