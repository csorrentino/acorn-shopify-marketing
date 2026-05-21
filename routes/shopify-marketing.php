<?php

use Csorrentino\ShopifyMarketing\Services\ShopifyService;
use Illuminate\Support\Facades\Route;

if (config('shopify-marketing.oauth_enabled')) {
    Route::get(config('shopify-marketing.routes.redirect'), [ShopifyService::class, 'redirectToShopify']);
    Route::get(config('shopify-marketing.routes.callback'), [ShopifyService::class, 'handleCallback']);
}
