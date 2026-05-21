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

Set the callback URL to `https://your-site.test/shopify/auth/callback`.

### 3. Routing

Add route registration to `functions.php` (or wherever you configure Acorn):

```php
Application::configure()
    ->withProviders([...])
    ->withRouting(
        web: base_path('routes/web.php'),
    )
    ->boot();
```

If you don't have a `routes/web.php` yet, create it and require the package routes:

```php
<?php
// routes/web.php
require base_path('vendor/csorrentino/acorn-shopify-marketing/routes/web.php');
```

### 4. Connect Shopify

Visit `/shopify/auth/redirect` in your browser. You'll be redirected to Shopify to authorize the app. After authorization:

- An Admin API token is stored in `wp_options` (`shopify_admin_access_token`)
- A Storefront API token is created and stored (`shopify_storefront_access_token`)

## Usage

### Option A: Sage HTML Forms (Recommended)

If using [Log1x/sage-html-forms](https://github.com/Log1x/sage-html-forms):

1. Create a form in **WordPress admin → HTML Forms → Add New**
2. Give it the slug `newsletter` (or customize via `config('shopify-marketing.html_forms_slug')`)
3. Add an email field with `name="email"`
4. Render the form anywhere with the included Blade view:

```blade
@include('shopify-marketing::examples.newsletter-html-forms', ['form' => 'newsletter'])
```

When the form is submitted, the package hooks into `hf_process_form` and sends the email to Shopify via the Storefront API. The form still goes through all of HTML Forms' normal validation, spam protection, and submission logging.

### Option B: Alpine.js

For Alpine.js-based forms (no additional dependencies), include the example view:

```blade
@include('shopify-marketing::examples.newsletter-alpine')
```

The Alpine view POSTs directly to the REST endpoint. Publish the views to customize them:

```bash
wp acorn vendor:publish --tag=shopify-marketing-views
```

### REST Endpoint (manual)

Submit email signups to `POST /wp-json/shopify-marketing/v1/newsletter`:

```javascript
fetch('/wp-json/shopify-marketing/v1/newsletter', {
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
