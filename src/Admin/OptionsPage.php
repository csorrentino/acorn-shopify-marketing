<?php

namespace Csorrentino\ShopifyMarketing\Admin;

use Csorrentino\ShopifyMarketing\Services\ShopifyService;

class OptionsPage
{
    public function register(): void
    {
        add_action('admin_menu', function () {
            add_options_page(
                'Shopify Marketing',
                'Shopify Marketing',
                'manage_options',
                'shopify-marketing',
                [$this, 'render'],
            );
        });
    }

    public function render(): void
    {
        $connected = ShopifyService::isConnected();
        $domain = ShopifyService::getStoreDomain();
        $authUrl = home_url(config('shopify-marketing.routes.redirect'));
        $restNamespace = config('shopify-marketing.rest_namespace');

        $hasAdminToken = !empty(get_option('shopify_admin_access_token'));
        $hasStorefrontToken = !empty(get_option('shopify_storefront_access_token'));

        ?>
        <div class="wrap">
            <h1>Shopify Marketing</h1>

            <?php if ($connected): ?>
                <div class="notice notice-success inline">
                    <p><strong>Connected to Shopify</strong></p>
                    <p>Store: <code><?php echo esc_html($domain); ?></code></p>
                </div>

                <p>
                    <?php if ($hasAdminToken): ?>
                        <span style="color:#46b450;font-size:16px;">&#10003;</span> Admin API — customer tags supported<br>
                    <?php else: ?>
                        <span style="color:#dc3232;font-size:16px;">&#10007;</span> Admin API — not connected<br>
                    <?php endif; ?>

                    <?php if ($hasStorefrontToken): ?>
                        <span style="color:#46b450;font-size:16px;">&#10003;</span> Storefront API — basic signups only<br>
                    <?php else: ?>
                        <span style="color:#dc3232;font-size:16px;">&#10007;</span> Storefront API — not connected<br>
                    <?php endif; ?>
                </p>

                <p>Your store is connected and newsletter signups are ready.</p>

                <p>
                    <button
                        type="button"
                        class="button button-secondary"
                        onclick="disconnectShopify()"
                        id="shopify-disconnect-btn"
                    >Disconnect Shopify</button>
                    <span id="shopify-disconnect-status" style="margin-left:10px;"></span>
                </p>

                <script>
                async function disconnectShopify() {
                    if (!confirm('Disconnect Shopify? Signup forms will stop working.')) return;

                    const btn = document.getElementById('shopify-disconnect-btn');
                    const status = document.getElementById('shopify-disconnect-status');
                    btn.disabled = true;
                    status.textContent = 'Disconnecting...';

                    try {
                        const res = await fetch('/wp-json/<?php echo esc_js($restNamespace); ?>/disconnect', {
                            method: 'POST',
                            headers: { 'X-WP-Nonce': '<?php echo wp_create_nonce('wp_rest'); ?>' },
                        });
                        const data = await res.json();
                        if (data.success) {
                            location.reload();
                        } else {
                            status.textContent = 'Failed to disconnect.';
                        }
                    } catch (e) {
                        status.textContent = 'Network error.';
                    } finally {
                        btn.disabled = false;
                    }
                }
                </script>
            <?php else: ?>
                <div class="notice notice-warning inline">
                    <p><strong>Not connected</strong></p>
                    <p>Connect your Shopify store to enable newsletter signups.</p>
                </div>

                <p>
                    <a href="<?php echo esc_url($authUrl); ?>" class="button button-primary">Connect Shopify</a>
                </p>

                <p class="description">
                    You'll be redirected to Shopify to authorize the app. Make sure your custom app has
                    <code>write_customers,read_customers</code> (Admin API) and
                    <code>unauthenticated_write_customers</code> (Storefront API) scopes enabled.
                </p>
            <?php endif; ?>
        </div>
        <?php
    }
}
