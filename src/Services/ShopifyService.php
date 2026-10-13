<?php

namespace Csorrentino\ShopifyMarketing\Services;

use Illuminate\Http\Request;

class ShopifyService
{
    public static function isConnected(): bool
    {
        return TokenStore::get(TokenStore::ADMIN_TOKEN) !== ''
            || TokenStore::get(TokenStore::STOREFRONT_TOKEN) !== '';
    }

    public static function getStoreDomain(): string
    {
        $fromOption = TokenStore::get(TokenStore::STORE_DOMAIN);

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

        TokenStore::put(TokenStore::ADMIN_TOKEN, $adminToken);
        TokenStore::put(TokenStore::STORE_DOMAIN, $shop);

        if (TokenStore::get(TokenStore::STOREFRONT_TOKEN) !== '') {
            return redirect(home_url('/wp-admin/options-general.php?page=shopify-marketing&connected=1'));
        }

        $storefrontToken = $this->createStorefrontToken($shop, $adminToken);

        if (!$storefrontToken) {
            return response('Failed to create Storefront API token.', 500);
        }

        TokenStore::put(TokenStore::STOREFRONT_TOKEN, $storefrontToken);

        return redirect(home_url('/wp-admin/options-general.php?page=shopify-marketing&connected=1'));
    }

    /**
     * Subscribe an email to marketing, creating the customer if needed.
     *
     * With an Admin token, existing customers are found and subscribed, and
     * new ones are created with marketing consent. The Admin path needs the
     * app's protected customer data access (email). If Shopify denies it, the
     * signup falls back to the Storefront API, which can only create new
     * customers.
     */
    public function createCustomer(string $email, array $data = []): array
    {
        $adminToken = TokenStore::get(TokenStore::ADMIN_TOKEN);
        $storefrontToken = TokenStore::get(TokenStore::STOREFRONT_TOKEN);

        if ($adminToken === '' && $storefrontToken === '') {
            error_log('[shopify-marketing] No access token found. Connect Shopify in Settings → Shopify Marketing.');
            return $this->failure();
        }

        $domain = self::getStoreDomain();
        $apiVersion = config('shopify-marketing.api_version');
        $tags = array_values(array_unique(array_merge(
            config('shopify-marketing.tags', []),
            apply_filters('shopify_marketing_customer_tags', $data['tags'] ?? []),
        )));

        if ($adminToken !== '') {
            $result = $this->subscribeViaAdmin($email, $data, $tags, $domain, $apiVersion, $adminToken);

            if ($result !== null) {
                return $result;
            }
        }

        if ($storefrontToken === '') {
            return $this->failure();
        }

        $result = $this->createCustomerViaStorefrontGraphQL($email, $data, $domain, $apiVersion, $storefrontToken);

        if ($result['success'] && $adminToken !== '' && !empty($tags) && !empty($result['customerId'])) {
            $this->addCustomerTags($result['customerId'], $tags, $domain, $apiVersion, $adminToken);
        }

        return $result;
    }

    /**
     * Find or create the customer with the Admin API and subscribe them.
     *
     * Returns null when the Admin API can't be used (request failed or access
     * denied), so the caller can fall back to the Storefront API.
     */
    private function subscribeViaAdmin(string $email, array $data, array $tags, string $domain, string $apiVersion, string $adminToken): ?array
    {
        $lookup = $this->adminGraphQL(
            $domain,
            $apiVersion,
            $adminToken,
            'query findCustomer($query: String!) { customers(first: 1, query: $query) { edges { node { id } } } }',
            ['query' => 'email:"' . addcslashes($email, '"\\') . '"'],
        );

        if ($lookup === null) {
            return null;
        }

        $customerId = $lookup['data']['customers']['edges'][0]['node']['id'] ?? null;
        $consent = [
            'marketingState' => 'SUBSCRIBED',
            'marketingOptInLevel' => 'SINGLE_OPT_IN',
        ];

        if ($customerId) {
            $update = $this->adminGraphQL(
                $domain,
                $apiVersion,
                $adminToken,
                'mutation subscribe($input: CustomerEmailMarketingConsentUpdateInput!) { customerEmailMarketingConsentUpdate(input: $input) { customer { id } userErrors { field message } } }',
                ['input' => ['customerId' => $customerId, 'emailMarketingConsent' => $consent]],
            );

            if ($update === null) {
                return null;
            }

            if (!empty($update['data']['customerEmailMarketingConsentUpdate']['userErrors'])) {
                error_log('[shopify-marketing] Consent update user errors: ' . json_encode($update['data']['customerEmailMarketingConsentUpdate']['userErrors']));
                return $this->failure();
            }

            if (!empty($tags)) {
                $this->addCustomerTags($customerId, $tags, $domain, $apiVersion, $adminToken);
            }

            return $this->success();
        }

        $input = array_filter([
            'email' => $email,
            'firstName' => $data['firstName'] ?? '',
            'lastName' => $data['lastName'] ?? '',
            'phone' => $data['phone'] ?? '',
        ]);
        $input['emailMarketingConsent'] = $consent;

        if (!empty($tags)) {
            $input['tags'] = $tags;
        }

        $create = $this->adminGraphQL(
            $domain,
            $apiVersion,
            $adminToken,
            'mutation customerCreate($input: CustomerInput!) { customerCreate(input: $input) { customer { id } userErrors { field message } } }',
            ['input' => $input],
        );

        if ($create === null) {
            return null;
        }

        if (!empty($create['data']['customerCreate']['userErrors'])) {
            error_log('[shopify-marketing] Admin customerCreate user errors: ' . json_encode($create['data']['customerCreate']['userErrors']));
            return $this->failure();
        }

        return $this->success($create['data']['customerCreate']['customer']['id'] ?? null);
    }

    /**
     * Run an Admin API GraphQL request.
     *
     * Returns the decoded body, or null when the request failed or Shopify
     * returned top-level errors (for example ACCESS_DENIED).
     */
    private function adminGraphQL(string $domain, string $apiVersion, string $adminToken, string $query, array $variables): ?array
    {
        $response = wp_remote_post("https://{$domain}/admin/api/{$apiVersion}/graphql.json", [
            'headers' => [
                'X-Shopify-Access-Token' => $adminToken,
                'Content-Type' => 'application/json',
            ],
            'body' => json_encode(['query' => $query, 'variables' => $variables]),
            'timeout' => 15,
        ]);

        if (is_wp_error($response)) {
            error_log('[shopify-marketing] Admin API request failed: ' . $response->get_error_message());
            return null;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (!is_array($body)) {
            error_log('[shopify-marketing] Admin API returned HTTP ' . wp_remote_retrieve_response_code($response) . ' with no JSON body.');
            return null;
        }

        if (!empty($body['errors'])) {
            error_log('[shopify-marketing] Admin API errors (check the app has protected customer data access): ' . json_encode($this->sanitizeErrorPayload((array) $body['errors'])));
            return null;
        }

        return $body;
    }

    private function success(?string $customerId = null): array
    {
        return [
            'success' => true,
            'message' => "You're on the list! Thanks for signing up.",
            'customerId' => $customerId,
        ];
    }

    private function failure(string $message = 'There was an error submitting the form.'): array
    {
        return [
            'success' => false,
            'message' => $message,
        ];
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

        $userErrors = $body['data']['customerCreate']['customerUserErrors'] ?? [];

        if (!empty($userErrors)) {
            error_log('[shopify-marketing] customerCreate user errors: ' . json_encode($userErrors));

            // The Storefront API can't update an existing customer's consent.
            $existing = array_intersect(['TAKEN', 'CUSTOMER_DISABLED'], array_column($userErrors, 'code'));

            if (!empty($existing)) {
                return $this->failure('This email is already registered with our store. Please contact us to update your subscription.');
            }

            return $this->failure();
        }

        return $this->success($body['data']['customerCreate']['customer']['id'] ?? null);
    }

    /**
     * Add tags without replacing the customer's existing tags.
     */
    private function addCustomerTags(string $customerGid, array $tags, string $domain, string $apiVersion, string $adminToken): void
    {
        $body = $this->adminGraphQL(
            $domain,
            $apiVersion,
            $adminToken,
            'mutation addTags($id: ID!, $tags: [String!]!) { tagsAdd(id: $id, tags: $tags) { node { id } userErrors { field message } } }',
            ['id' => $customerGid, 'tags' => $tags],
        );

        if (!empty($body['data']['tagsAdd']['userErrors'])) {
            error_log('[shopify-marketing] Tag update user errors: ' . json_encode($body['data']['tagsAdd']['userErrors']));
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
        TokenStore::deleteAll();
    }
}
