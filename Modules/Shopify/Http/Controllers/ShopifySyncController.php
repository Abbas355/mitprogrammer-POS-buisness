<?php

namespace Modules\Shopify\Http\Controllers;

use App\Http\Controllers\Controller;
use Modules\Shopify\Services\ShopifyApiService;
use Modules\Shopify\Jobs\SyncShopifyProducts;
use Modules\Shopify\Jobs\SyncShopifyOrders;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Business;

class ShopifySyncController extends Controller
{
    /**
     * Trigger product sync
     */
    public function syncProducts(Request $request)
    {
        try {
            $businessId = request()->session()->get('user.business_id');
            
            // Dispatch sync job
            SyncShopifyProducts::dispatch($businessId);

            return response()->json([
                'success' => true,
                'msg' => 'Product sync started',
            ]);

        } catch (\Exception $e) {
            Log::error('Shopify product sync failed', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'msg' => 'Failed to start sync: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Trigger order sync
     */
    public function syncOrders(Request $request)
    {
        try {
            $businessId = request()->session()->get('user.business_id');
            
            // Dispatch sync job
            SyncShopifyOrders::dispatch($businessId);

            return response()->json([
                'success' => true,
                'msg' => 'Order sync started',
            ]);

        } catch (\Exception $e) {
            Log::error('Shopify order sync failed', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'msg' => 'Failed to start sync: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Sync single product
     */
    public function syncProduct($productId, Request $request)
    {
        try {
            $businessId = request()->session()->get('user.business_id');
            
            // Dispatch single product sync job
            \Modules\Shopify\Jobs\ExportProductToShopify::dispatch($businessId, $productId);

            return response()->json([
                'success' => true,
                'msg' => 'Product sync started',
            ]);

        } catch (\Exception $e) {
            Log::error('Shopify single product sync failed', [
                'product_id' => $productId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'msg' => 'Failed to sync product: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get sync status
     */
    public function syncStatus(Request $request)
    {
        try {
            $businessId = request()->session()->get('user.business_id');
            $business = Business::find($businessId);

            $status = [
                'connected' => false,
                'last_sync_at' => null,
                'sync_in_progress' => false,
            ];

            if ($business && $business->shopify_api_settings) {
                $settings = is_string($business->shopify_api_settings)
                    ? json_decode($business->shopify_api_settings, true)
                    : $business->shopify_api_settings;

                $status['connected'] = !empty($settings['shop_domain']);
                $status['last_sync_at'] = $settings['last_sync_at'] ?? null;
                $status['sync_enabled'] = $settings['sync_enabled'] ?? false;
            }

            return response()->json([
                'success' => true,
                'status' => $status,
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to get sync status', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'msg' => 'Failed to get status: ' . $e->getMessage(),
            ], 500);
        }
    }
}

