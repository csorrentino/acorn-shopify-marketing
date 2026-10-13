<?php

return [
    'api_version' => '2025-04',
    'admin_scopes' => 'write_customers,read_customers',
    'oauth_enabled' => true,
    'routes' => [
        'redirect' => '/shopify/marketing/connect',
        'callback' => '/shopify/marketing/callback',
    ],
    'rest_namespace' => 'shopify-marketing/v1',
    'rest_route' => '/newsletter',
    'tags' => [],
    'html_forms_slug' => 'newsletter',
    // Hidden form field that people leave empty. Requests that fill it in are
    // treated as spam: they get a success response but nothing is sent to Shopify.
    'honeypot_field' => 'website',
    // Signup attempts allowed per IP address within the window.
    'rate_limit' => [
        'max_attempts' => 5,
        'window_seconds' => 600,
    ],
];
