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
];
