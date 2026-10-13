<?php

namespace Csorrentino\ShopifyMarketing\Services;

/**
 * Stores the Shopify connection in wp_options.
 *
 * Tokens are encrypted with a key derived from the site's auth salts and are
 * not autoloaded. A token copied to another site (for example by a database
 * migration) cannot be decrypted there, so that site shows "Not connected"
 * instead of sending signups to the wrong store.
 */
class TokenStore
{
    public const ADMIN_TOKEN = 'shopify_admin_access_token';

    public const STOREFRONT_TOKEN = 'shopify_storefront_access_token';

    public const STORE_DOMAIN = 'shopify_store_domain';

    public const OPTIONS = [
        self::ADMIN_TOKEN,
        self::STOREFRONT_TOKEN,
        self::STORE_DOMAIN,
    ];

    private const PREFIX = 'enc:v1:';

    public static function get(string $option): string
    {
        $stored = get_option($option, '');

        if (!is_string($stored) || $stored === '') {
            return '';
        }

        // The store domain is not a secret, so it is stored as plain text.
        if ($option === self::STORE_DOMAIN) {
            return $stored;
        }

        // Saved in plain text by an older version: encrypt it now.
        if (!str_starts_with($stored, self::PREFIX)) {
            self::put($option, $stored);

            return $stored;
        }

        $token = self::decrypt(substr($stored, strlen(self::PREFIX)));

        if ($token === '') {
            error_log("[shopify-marketing] Could not decrypt {$option}. The auth salts changed or the database came from another site. Reconnect Shopify.");
        }

        return $token;
    }

    public static function put(string $option, string $value): void
    {
        $stored = $option === self::STORE_DOMAIN
            ? $value
            : self::PREFIX . self::encrypt($value);

        update_option($option, $stored, false);
    }

    public static function deleteAll(): void
    {
        foreach (self::OPTIONS as $option) {
            delete_option($option);
        }
    }

    private static function key(): string
    {
        return hash('sha256', 'acorn-shopify-marketing|' . wp_salt('auth'), true);
    }

    private static function encrypt(string $value): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return base64_encode($nonce . sodium_crypto_secretbox($value, $nonce, self::key()));
    }

    private static function decrypt(string $payload): string
    {
        $decoded = base64_decode($payload, true);

        if ($decoded === false || strlen($decoded) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return '';
        }

        $nonce = substr($decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open($cipher, $nonce, self::key());

        return $plain === false ? '' : $plain;
    }
}
