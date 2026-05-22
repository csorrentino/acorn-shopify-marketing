<?php

namespace Csorrentino\ShopifyMarketing\Services;

use Illuminate\Http\Request;

class ShopifyService
{
    public static function isConnected(): bool
    {
        return !empty(get_option('shopify_admin_access_token'))
            || !empty(get_option('shopify_storefront_access_token'));
    }

    public static function getStoreDomain(): string
    {
        $fromOption = get_option('shopify_store_domain');

        if ($fromOption) {
            return $fromOption;
        }

        return env('SHOPIFY_STORE_DOMAIN', '');
    }

    public function redirectToShopify(): \Illuminate\Http\RedirectResponse
    {
        $domain = self::getStoreDomain();
        $apiKey = env('SHOPIFY_API_KEY');
        $scopes = config('shopify-marketing.admin_scopes');
        $redirectUri = home_url(config('shopify-marketing.routes.callback'));
        $state = bin2hex(random_bytes(16));

        set_transient('shopify_oauth_state', $state, 600);

        $url =
            "https://{$domain}/admin/oauth/authorize?"
            . http_build_query([
                'client_id' => $apiKey,
                'scope' => $scopes,
                'redirect_uri' => $redirectUri,
                'state' => $state,
            ]);

        return redirect()->away($url);
    }

    public function handleCallback(Request $request): \Illuminate\Http\Response
    {
        $storedState = get_transient('shopify_oauth_state');
        delete_transient('shopify_oauth_state');

        if (!$storedState || $request->input('state') !== $storedState) {
            abort(403, 'Invalid OAuth state.');
        }

        $shop = $request->input('shop');

        if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9\-]*\.myshopify\.com$/', $shop)) {
            abort(403, 'Invalid shop domain.');
        }

        $hmacParam = $request->input('hmac');

        parse_str($request->getQueryString(), $params);
        unset($params['hmac']);
        ksort($params);

        $canonicalQuery = http_build_query($params);
        $computedHmac = hash_hmac('sha256', $canonicalQuery, env('SHOPIFY_API_SECRET'));

        if (!hash_equals($computedHmac, $hmacParam)) {
            abort(403, 'Invalid HMAC signature.');
        }

        $code = $request->input('code');

        $adminToken = $this->exchangeCodeForAdminToken($shop, $code);

        if (!$adminToken) {
            return response('Failed to obtain admin token from Shopify.', 500);
        }

        update_option('shopify_admin_access_token', $adminToken);
        update_option('shopify_store_domain', $shop);

        if (get_option('shopify_storefront_access_token')) {
            return redirect(home_url('/wp-admin/options-general.php?page=shopify-marketing&connected=1'));
        }

        $storefrontToken = $this->createStorefrontToken($shop, $adminToken);

        if (!$storefrontToken) {
            return response('Failed to create Storefront API token.', 500);
        }

        update_option('shopify_storefront_access_token', $storefrontToken);

        return redirect(home_url('/wp-admin/options-general.php?page=shopify-marketing&connected=1'));
    }

    public function createCustomer(string $email, array $data = []): array
    {
        $adminToken = get_option('shopify_admin_access_token');
        $storefrontToken = get_option('shopify_storefront_access_token');

        if (!$adminToken && !$storefrontToken) {
            error_log('[shopify-marketing] No access token found in wp_options.');
            return [
                'success' => false,
                'message' => 'There was an error submitting the form.',
            ];
        }

        $domain = self::getStoreDomain();
        $apiVersion = config('shopify-marketing.api_version');

        $token = $storefrontToken ?: $adminToken;
        $result = $this->createCustomerViaStorefrontGraphQL($email, $data, $domain, $apiVersion, $token);

        if (!$result['success']) {
            return $result;
        }

        if ($adminToken) {
            $tags = array_merge(
                config('shopify-marketing.tags', []),
                apply_filters('shopify_marketing_customer_tags', $data['tags'] ?? []),
            );

            if (!empty($tags)) {
                $this->addCustomerTags($result['customerId'], $tags, $domain, $apiVersion, $adminToken);
            }
        }

        return $result;
    }

    private function createCustomerViaStorefrontGraphQL(string $email, array $data, string $domain, string $apiVersion, string $token): array
    {
        $endpoint = "https://{$domain}/api/{$apiVersion}/graphql.json";

        $mutation = <<<'GRAPHQL'
        mutation customerCreate($input: CustomerCreateInput!) {
            customerCreate(input: $input) {
                customer { id email acceptsMarketing }
                customerUserErrors { code field message }
            }
        }
        GRAPHQL;

        $input = [
            'email' => $email,
            'password' => wp_generate_password(32),
            'acceptsMarketing' => true,
        ];

        if (!empty($data['firstName'])) {
            $input['firstName'] = $data['firstName'];
        }

        if (!empty($data['lastName'])) {
            $input['lastName'] = $data['lastName'];
        }

        if (!empty($data['phone'])) {
            $input['phone'] = $data['phone'];
        }

        $response = wp_remote_post($endpoint, [
            'headers' => [
                'X-Shopify-Storefront-Access-Token' => $token,
                'Content-Type' => 'application/json',
            ],
            'body' => json_encode([
                'query' => $mutation,
                'variables' => [
                    'input' => $input,
                ],
            ]),
            'timeout' => 15,
        ]);

        if (is_wp_error($response)) {
            error_log('[shopify-marketing] Storefront GraphQL create failed: ' . $response->get_error_message());
            return [
                'success' => false,
                'message' => 'There was an error submitting the form.',
            ];
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (!empty($body['errors'])) {
            error_log('[shopify-marketing] Storefront GraphQL errors: ' . json_encode($this->sanitizeErrorPayload($body['errors'])));
            return [
                'success' => false,
                'message' => 'There was an error submitting the form.',
            ];
        }

        if (!empty($body['data']['customerCreate']['customerUserErrors'])) {
            error_log('[shopify-marketing] customerCreate user errors: ' . json_encode($body['data']['customerCreate']['customerUserErrors']));
            return [
                'success' => false,
                'message' => 'There was an error submitting the form.',
            ];
        }

        return [
            'success' => true,
            'message' => "You're on the list! Thanks for signing up.",
            'customerId' => $body['data']['customerCreate']['customer']['id'] ?? null,
        ];
    }

    private function addCustomerTags(string $customerGid, array $tags, string $domain, string $apiVersion, string $adminToken): void
    {
        $mutation = <<<'GRAPHQL'
        mutation customerUpdate($input: CustomerInput!) {
            customerUpdate(input: $input) {
                customer { id tags }
                userErrors { field message }
            }
        }
        GRAPHQL;

        $response = wp_remote_post("https://{$domain}/admin/api/{$apiVersion}/graphql.json", [
            'headers' => [
                'X-Shopify-Access-Token' => $adminToken,
                'Content-Type' => 'application/json',
            ],
            'body' => json_encode([
                'query' => $mutation,
                'variables' => [
                    'input' => [
                        'id' => $customerGid,
                        'tags' => $tags,
                    ],
                ],
            ]),
            'timeout' => 15,
        ]);

        if (is_wp_error($response)) {
            error_log('[shopify-marketing] Tag update failed: ' . $response->get_error_message());
            return;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (!empty($body['data']['customerUpdate']['userErrors'])) {
            error_log('[shopify-marketing] Tag update user errors: ' . json_encode($body['data']['customerUpdate']['userErrors']));
        }
    }

    private function sanitizeErrorPayload(array $errorData): array
    {
        return array_map(function ($entry) {
            if (isset($entry['extensions']['value'])) {
                $value = &$entry['extensions']['value'];

                if (isset($value['email'])) {
                    $value['email'] = $this->obfuscateEmail($value['email']);
                }

                if (isset($value['password'])) {
                    $value['password'] = '[redacted]';
                }
            }

            return $entry;
        }, $errorData);
    }

    private function obfuscateEmail(string $email): string
    {
        $parts = explode('@', $email);

        if (count($parts) !== 2) {
            return '[redacted]';
        }

        $name = $parts[0];
        $domain = $parts[1];

        if (strlen($name) <= 2) {
            return substr($name, 0, 1) . '*@' . $domain;
        }

        return substr($name, 0, 2) . str_repeat('*', max(strlen($name) - 4, 1)) . substr($name, -2) . '@' . $domain;
    }

    private function exchangeCodeForAdminToken(string $shop, string $code): ?string
    {
        $response = wp_remote_post("https://{$shop}/admin/oauth/access_token", [
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode([
                'client_id' => env('SHOPIFY_API_KEY'),
                'client_secret' => env('SHOPIFY_API_SECRET'),
                'code' => $code,
            ]),
            'timeout' => 15,
        ]);

        if (is_wp_error($response)) {
            return null;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        return $body['access_token'] ?? null;
    }

    private function createStorefrontToken(string $shop, string $adminToken): ?string
    {
        $apiVersion = config('shopify-marketing.api_version');

        $response = wp_remote_post("https://{$shop}/admin/api/{$apiVersion}/storefront_access_tokens.json", [
            'headers' => [
                'X-Shopify-Access-Token' => $adminToken,
                'Content-Type' => 'application/json',
            ],
            'body' => json_encode([
                'storefront_access_token' => [
                    'title' => 'Acorn Shopify Marketing',
                ],
            ]),
            'timeout' => 15,
        ]);

        if (is_wp_error($response)) {
            return null;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        return $body['storefront_access_token']['access_token'] ?? null;
    }

    public function disconnect(): void
    {
        delete_option('shopify_admin_access_token');
        delete_option('shopify_storefront_access_token');
        delete_option('shopify_store_domain');
    }
}
