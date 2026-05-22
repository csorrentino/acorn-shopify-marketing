<?php

namespace Csorrentino\ShopifyMarketing\Providers;

use Csorrentino\ShopifyMarketing\Admin\OptionsPage;
use Csorrentino\ShopifyMarketing\Services\ShopifyService;
use Illuminate\Support\ServiceProvider;
use WP_REST_Request;
use WP_REST_Response;

class ShopifyMarketingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/shopify-marketing.php', 'shopify-marketing');

        add_action('rest_api_init', function () {
            register_rest_route(config('shopify-marketing.rest_namespace'), config('shopify-marketing.rest_route'), [
                'methods' => 'POST',
                'callback' => [$this, 'handleSignup'],
                'permission_callback' => '__return_true',
            ]);

            if (config('shopify-marketing.oauth_enabled')) {
                register_rest_route(config('shopify-marketing.rest_namespace'), '/disconnect', [
                    'methods' => 'POST',
                    'callback' => [$this, 'handleDisconnect'],
                    'permission_callback' => function () {
                        return current_user_can('manage_options');
                    },
                ]);
            }
        });
    }

    public function boot(): void
    {
        $this->bootHtmlFormsIntegration();

        if (config('shopify-marketing.oauth_enabled')) {
            $this->loadRoutesFrom(__DIR__ . '/../../routes/shopify-marketing.php');
            new OptionsPage()->register();
        }

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../../config/shopify-marketing.php' => $this->app->configPath('shopify-marketing.php'),
            ], 'shopify-marketing-config');

            $this->publishes([
                __DIR__ . '/../../examples/resources/views/forms/newsletter-alpine-example.blade.php' => resource_path(
                    'views/forms/newsletter.blade.php',
                ),
            ], 'shopify-marketing-views-alpine');

            $this->publishes([
                __DIR__ . '/../../examples/resources/views/forms/newsletter-html-forms-example.blade.php' => resource_path(
                    'views/forms/newsletter.blade.php',
                ),
            ], 'shopify-marketing-views-htmlforms');
        }
    }

    public function handleDisconnect(WP_REST_Request $request): WP_REST_Response
    {
        $shopify = $this->app->make(ShopifyService::class);
        $shopify->disconnect();

        return new WP_REST_Response([
            'success' => true,
            'message' => 'Shopify disconnected.',
        ]);
    }

    protected function bootHtmlFormsIntegration(): void
    {
        if (!class_exists(\HTML_Forms\Form::class)) {
            return;
        }

        $formSlug = config('shopify-marketing.html_forms_slug', 'newsletter');

        add_action(
            'hf_process_form',
            function ($form, $submission) use ($formSlug) {
                if ($form->slug !== $formSlug) {
                    return;
                }

                $data = $submission->data;
                $email = $this->extractEmailFromSubmission($data);

                if (!$email || !is_email($email)) {
                    return;
                }

                $customerData = [];

                $firstName = $data['firstName'] ?? $data['first_name'] ?? $data['FNAME'] ?? '';
                if ($firstName && is_string($firstName)) {
                    $customerData['firstName'] = sanitize_text_field($firstName);
                }

                $lastName = $data['lastName'] ?? $data['last_name'] ?? $data['LNAME'] ?? '';
                if ($lastName && is_string($lastName)) {
                    $customerData['lastName'] = sanitize_text_field($lastName);
                }

                $phone = $data['phone'] ?? $data['PHONE'] ?? '';
                if ($phone && is_string($phone)) {
                    $customerData['phone'] = sanitize_text_field($phone);
                }

                $shopify = $this->app->make(ShopifyService::class);
                $shopify->createCustomer($email, $customerData);
            },
            10,
            2,
        );
    }

    protected function extractEmailFromSubmission(array $data): string
    {
        $possibleKeys = ['email', 'EMAIL', 'emailAddress', 'email_address', 'your-email'];

        foreach ($possibleKeys as $key) {
            if (!empty($data[$key]) && is_string($data[$key])) {
                return sanitize_email($data[$key]);
            }
        }

        foreach ($data as $value) {
            if (is_string($value) && is_email($value)) {
                return sanitize_email($value);
            }
        }

        return '';
    }

    public function handleSignup(WP_REST_Request $request): WP_REST_Response
    {
        $nonce = $request->get_header('X-WP-Nonce');
        if (!wp_verify_nonce($nonce, 'wp_rest')) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Security check failed. Please refresh the page.',
            ], 403);
        }

        $email = sanitize_email($request->get_param('email'));

        if (!is_email($email)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Please enter a valid email address.',
            ], 422);
        }

        $data = [];

        $firstName = $request->get_param('firstName');
        if ($firstName && is_string($firstName)) {
            $data['firstName'] = sanitize_text_field($firstName);
        }

        $lastName = $request->get_param('lastName');
        if ($lastName && is_string($lastName)) {
            $data['lastName'] = sanitize_text_field($lastName);
        }

        $phone = $request->get_param('phone');
        if ($phone && is_string($phone)) {
            $data['phone'] = sanitize_text_field($phone);
        }

        $shopify = $this->app->make(ShopifyService::class);

        try {
            $result = $shopify->createCustomer($email, $data);
        } catch (\Throwable $e) {
            error_log('[shopify-marketing] createCustomer exception: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            return new WP_REST_Response([
                'success' => false,
                'message' => 'There was an error submitting the form.',
            ], 422);
        }

        if (!$result['success']) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'There was an error submitting the form.',
            ], 422);
        }

        return new WP_REST_Response($result, 200);
    }
}
