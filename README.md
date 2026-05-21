# Acorn Shopify Marketing

Shopify OAuth and newsletter signup integration for Acorn projects.

## Installation

```bash
composer require csorrentino/acorn-shopify-marketing
```

The package auto-discovers its service provider via the `extra.acorn.providers` key in `composer.json`.

## Setup

### 1. Environment Variables

Add to your `.env`:

```
SHOPIFY_API_KEY="your-custom-app-api-key"
SHOPIFY_API_SECRET="your-custom-app-api-secret"
SHOPIFY_STORE_DOMAIN="your-store.myshopify.com"
```

### 2. Shopify Custom App

Create a custom app in your Shopify Partner dashboard with these scopes:

- **Admin API:** `write_customers`, `read_customers`
- **Storefront API:** `unauthenticated_write_customers`, `unauthenticated_read_customers`

Set the callback URL to `https://your-site.test/shopify/marketing/callback`.

### 3. Routing

Routes are registered automatically by the service provider when `oauth_enabled` is `true` (the default). No manual configuration is needed.

To change the default route paths, publish the config and update the `routes` keys:

```bash
wp acorn vendor:publish --tag=shopify-marketing-config
```

```php
// config/shopify-marketing.php
'routes' => [
    'redirect' => '/shopify/marketing/connect',
    'callback' => '/shopify/marketing/callback',
],
```

### 4. Connect Shopify

Visit Settings → Shopify Marketing in the WordPress admin, or go to `/shopify/marketing/connect` directly. You'll be redirected to Shopify to authorize the app. After authorization:

- An Admin API token is stored in `wp_options` (`shopify_admin_access_token`)
- A Storefront API token is created and stored (`shopify_storefront_access_token`)

## Usage

### Option A: Sage HTML Forms (Recommended)

If using [Log1x/sage-html-forms](https://github.com/Log1x/sage-html-forms):

1. Copy `examples/resources/views/forms/newsletter-html-forms-example.blade.php` to `resources/views/forms/newsletter.blade.php`
2. Create a form in **WordPress admin → HTML Forms → Add New**
3. Give it the slug `newsletter` (or customize via `config('shopify-marketing.html_forms_slug')`)
4. Add an email field with `name="email"`
5. Include the form in your layout:

```blade
@include('forms.newsletter', ['form' => 'newsletter'])
```

When the form is submitted, the package hooks into `hf_process_form` and sends the email to Shopify via the Storefront API. The form still goes through all of HTML Forms' normal validation, spam protection, and submission logging.

### Option B: Alpine.js

For Alpine.js-based forms (no additional dependencies):

1. Copy `examples/resources/views/forms/newsletter-alpine-example.blade.php` to `resources/views/forms/newsletter.blade.php`
2. Include it in your layout:

```blade
@include('forms.newsletter')
```

The Alpine view POSTs directly to the REST endpoint.

### REST Endpoint (manual)

The endpoint URL is built from `rest_namespace` and `rest_route` in your config. With defaults it resolves to `POST /wp-json/shopify-marketing/v1/newsletter`:

```javascript
fetch(wpApiSettings.root + 'shopify-marketing/v1/newsletter', {
    method: 'POST',
    headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': wpApiSettings.nonce,
    },
    body: JSON.stringify({ email: 'customer@example.com' }),
});
```

### Config

Publish the config to customize:

```bash
wp acorn vendor:publish --tag=shopify-marketing-config
```

Options:

- `api_version` — Shopify API version (default: `2025-04`)
- `admin_scopes` — OAuth scopes for admin access
- `oauth_enabled` — Enable/disable the built-in OAuth flow (default: `true`)
- `routes.redirect` / `routes.callback` — OAuth route paths
- `rest_namespace` / `rest_route` — REST API endpoint paths
- `html_forms_slug` — Form slug to watch for HTML Forms integration

## Disabling the OAuth Flow

If another package or custom code handles Shopify authentication (e.g., a general-purpose Shopify SDK that also manages products, orders, or discounts), you can disable this package's built-in OAuth flow:

```php
// config/shopify-marketing.php
'oauth_enabled' => false,
```

When disabled:
- The `/shopify/auth/redirect` and `/shopify/auth/callback` routes are not registered
- The admin options page (Settings → Shopify Marketing) is not registered
- The disconnect REST endpoint is not registered

The package still handles newsletter signups — it reads `shopify_storefront_access_token` and `shopify_store_domain` from `wp_options` (or `SHOPIFY_STORE_DOMAIN` from `.env`), however they were set. Just make sure those options are populated by your other Shopify integration.
