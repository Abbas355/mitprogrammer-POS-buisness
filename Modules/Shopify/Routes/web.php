<?php

use Illuminate\Support\Facades\Route;
use Modules\Shopify\Http\Controllers\ShopifyController;
use Modules\Shopify\Http\Controllers\ShopifySyncController;
use Modules\Shopify\Http\Controllers\ShopifyWebhookController;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

Route::prefix('shopify')->middleware(['web', 'auth', 'SetSessionData', 'AdminSidebarMenu'])->group(function() {
    Route::get('/settings', [ShopifyController::class, 'index'])->name('shopify.settings');
    Route::post('/connect', [ShopifyController::class, 'connect'])->name('shopify.connect');
    Route::get('/callback', [ShopifyController::class, 'callback'])->name('shopify.callback');
    Route::post('/disconnect', [ShopifyController::class, 'disconnect'])->name('shopify.disconnect');
    Route::post('/test-connection', [ShopifyController::class, 'testConnection'])->name('shopify.test-connection');
    Route::post('/settings', [ShopifyController::class, 'updateSettings'])->name('shopify.update-settings');
    
    Route::post('/sync/products', [ShopifySyncController::class, 'syncProducts'])->name('shopify.sync.products');
    Route::post('/sync/orders', [ShopifySyncController::class, 'syncOrders'])->name('shopify.sync.orders');
    Route::post('/sync/product/{productId}', [ShopifySyncController::class, 'syncProduct'])->name('shopify.sync.product');
    Route::get('/sync/status', [ShopifySyncController::class, 'syncStatus'])->name('shopify.sync.status');
    Route::post('/sync/cleanup-duplicates', [ShopifySyncController::class, 'cleanupDuplicates'])->name('shopify.sync.cleanup-duplicates');
});

// Install/Uninstall routes for module management
Route::middleware(['web', 'auth', 'SetSessionData', 'AdminSidebarMenu'])->group(function() {
    Route::get('/shopify/install', [\Modules\Shopify\Http\Controllers\InstallController::class, 'index'])->name('shopify.install');
    Route::get('/shopify/update', [\Modules\Shopify\Http\Controllers\InstallController::class, 'update'])->name('shopify.update');
    Route::get('/shopify/uninstall', [\Modules\Shopify\Http\Controllers\InstallController::class, 'uninstall'])->name('shopify.uninstall');
});


// Webhook route (no auth, signature verified)
Route::post('/shopify/webhook/{businessId}', [ShopifyWebhookController::class, 'handleWebhook'])
    ->middleware('shopify.webhook')
    ->name('shopify.webhook');
