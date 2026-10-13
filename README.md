# Acorn Shopify Marketing

Connect your WordPress frontend to Shopify's email marketing tools. This [Roots/acorn](https://github.com/roots/acorn) package lets you embed newsletter signup forms on your WordPress site that sync subscribers directly into Shopify's customer and list management — useful for setups where the Shopify store runs on a subdomain of the main WP site (e.g. `shop.yourdomain.com`). Supports [Log1x/sage-html-forms](https://github.com/Log1x/sage-html-forms) server-side or any custom form via a REST endpoint, and handles OAuth and Storefront token management automatically.

## Requirements

- PHP 8.4+
- [Roots/Acorn](https://github.com/roots/acorn) 6.x
- A WordPress setup with `.env` support — [Roots/Bedrock](https://github.com/roots/bedrock) or equivalent
- A Shopify custom app with Admin API and Storefront API access

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

- **Admin API:** `write_customers`, `read_customers` — required for OAuth, customer tags, and the disconnect REST endpoint
- **Storefront API:** `unauthenticated_write_customers`, `unauthenticated_read_customers` — required for creating customers

Set the callback URL to `https://your-site.test/shopify/marketing/callback`.

#### How the two APIs are used

**Admin API** — The primary path. The package looks up the email. An existing customer gets their email marketing consent set to subscribed. A new customer is created with consent and tags. Tags are added with `tagsAdd`, so existing tags are kept. This path reads and writes customer emails, so the app needs **protected customer data** access for email (Partner dashboard → your app → API access → Protected customer data access).

**Storefront API** — The fallback. If there is no Admin token, or Shopify denies the Admin request (for example, protected customer data access is missing), the package creates the customer with the Storefront API. It can't update an existing customer: that visitor sees "This email is already registered with our store." Check the PHP error log for `[shopify-marketing] Admin API errors` to see why the Admin path was skipped.

Both tokens are obtained automatically during the OAuth flow. The admin options page shows which APIs are connected with green checkmarks or red crosses.

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

Add each site's callback URL (local, staging, production) to the app's allowed redirection URLs first.

Visit Settings → Shopify Marketing in the WordPress admin, or go to `/shopify/marketing/connect` directly. You'll be redirected to Shopify to authorize the app. After authorization:

- An Admin API token is stored in `wp_options` (`shopify_admin_access_token`)
- A Storefront API token is created and stored (`shopify_storefront_access_token`)

### Credential storage

- Both tokens are encrypted in `wp_options` with a key derived from the site's auth salts (`wp_salt('auth')`), and they are not autoloaded. Tokens saved in plain text by older versions are encrypted the next time they are read.
- A database copied to another site can't decrypt the tokens, so that site shows "Not connected" instead of sending signups to the wrong store. Connect each site separately.
- Changing the auth salts has the same effect: reconnect Shopify afterwards.
- With WP Migrate DB (Pro), the three options (`shopify_admin_access_token`, `shopify_storefront_access_token`, `shopify_store_domain`) are preserved on the destination, so a push or pull keeps each site's own connection.

## Usage

### Option A: Sage HTML Forms (Recommended)

If using [Log1x/sage-html-forms](https://github.com/Log1x/sage-html-forms):

1. Publish the example view:

```bash
wp acorn vendor:publish --tag=shopify-marketing-views-htmlforms
```

2. Create a form in **WordPress admin → HTML Forms → Add New**
3. Give it the slug `newsletter` (or customize via `config('shopify-marketing.html_forms_slug')`)
4. Add an email field with `name="email"`
5. Include the form in your layout:

```blade
@include('forms.newsletter', ['form' => 'newsletter'])
```

When the form is submitted, the package hooks into `hf_process_form` and sends the email to Shopify via the Storefront API. The form still goes through all of HTML Forms' normal validation, spam protection, and submission logging.

### Option B: Custom form via REST endpoint

Any form that can make a `POST` request works — Alpine.js, vanilla JS, React, or anything else. The package exposes a REST endpoint that accepts an email and sends it to Shopify.

An Alpine.js example view is included. To use it as a starting point:

1. Publish the example view:

```bash
wp acorn vendor:publish --tag=shopify-marketing-views-alpine
```

2. Include it in your layout:

```blade
@include('forms.newsletter')
```

Or build your own form and POST to the endpoint directly — see the REST Endpoint section below.

### REST Endpoint (manual)

The endpoint URL is built from `rest_namespace` and `rest_route` in your config. With defaults it resolves to `POST /wp-json/shopify-marketing/v1/newsletter`.

Only `email` is required. Optionally include `firstName`, `lastName`, and `phone` — they'll be forwarded to Shopify.

The endpoint is public and needs no nonce, so forms keep working on cached pages. Don't send an `X-WP-Nonce` header from cached HTML. Spam is limited in two ways:

- **Honeypot:** add a hidden, empty field named after `honeypot_field` (default `website`). Requests that fill it in get a success response, but nothing is sent to Shopify.
- **Rate limit:** each IP address gets `rate_limit.max_attempts` signups per window. Extra requests get HTTP 429. Behind a proxy, return the real client IP from the `shopify_marketing_client_ip` filter.



```javascript
fetch(wpApiSettings.root + 'shopify-marketing/v1/newsletter', {
    method: 'POST',
    headers: {
        'Content-Type': 'application/json',
    },
    body: JSON.stringify({
        email: 'customer@example.com',
        firstName: 'Jane',
        lastName: 'Doe',
        phone: '+15555555555',
    }),
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
- `tags` — Array of tags to apply to every customer (default: `[]`)
- `html_forms_slug` — Form slug to watch for HTML Forms integration
- `honeypot_field` — Hidden field name for the spam honeypot (default: `website`)
- `rate_limit.max_attempts` / `rate_limit.window_seconds` — Signups allowed per IP address per window (default: 5 per 600 seconds). Set `max_attempts` to `0` to turn the limit off.

### Customer Tags

Set default tags in the config:

```php
// config/shopify-marketing.php
'tags' => ['newsletter', 'site-subscriber'],
```

#### Dynamic tags via filter

Use the `shopify_marketing_customer_tags` filter to add or remove tags based on conditions:

```php
add_filter('shopify_marketing_customer_tags', function (array $tags) {
    $tags[] = 'site-subscriber';
    return $tags;
});
```

## Disabling the OAuth Flow

If another package or custom code handles Shopify authentication (e.g., a general-purpose Shopify SDK that also manages products, orders, or discounts), you can disable this package's built-in OAuth flow:

```php
// config/shopify-marketing.php
'oauth_enabled' => false,
```

When disabled:
- The `/shopify/marketing/connect` and `/shopify/marketing/callback` routes are not registered
- The admin options page (Settings → Shopify Marketing) is not registered
- The disconnect REST endpoint is not registered

The package still handles newsletter signups — it reads `shopify_storefront_access_token` and `shopify_store_domain` from `wp_options` (or `SHOPIFY_STORE_DOMAIN` from `.env`), however they were set. Just make sure those options are populated by your other Shopify integration.
