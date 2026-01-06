<?php

namespace Modules\Shopify\Http\Controllers;

use App\Http\Controllers\Controller;
use Modules\Shopify\Services\ShopifyApiService;
use Modules\Shopify\Jobs\ProcessShopifyWebhook;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Business;

class ShopifyWebhookController extends Controller
{
    /**
     * Handle incoming webhook
     */
    public function handleWebhook(Request $request, $businessId)
    {
        try {
            // Verify business exists
            $business = Business::find($businessId);
            if (!$business || !$business->shopify_api_settings) {
                Log::warning('Shopify webhook received for invalid business', [
                    'business_id' => $businessId,
                ]);
                return response()->json(['error' => 'Invalid business'], 404);
            }

            // Get webhook secret
            $settings = is_string($business->shopify_api_settings)
                ? json_decode($business->shopify_api_settings, true)
                : $business->shopify_api_settings;

            $webhookSecret = $settings['webhook_secret'] ?? null;

            // Verify HMAC signature
            $hmac = $request->header('X-Shopify-Hmac-Sha256');
            $payload = $request->getContent();

            if ($webhookSecret && !ShopifyApiService::verifyWebhook($payload, $hmac, $webhookSecret)) {
                Log::warning('Shopify webhook signature verification failed', [
                    'business_id' => $businessId,
                ]);
                return response()->json(['error' => 'Invalid signature'], 401);
            }

            // Get topic from header
            $topic = $request->header('X-Shopify-Topic');
            $shopDomain = $request->header('X-Shopify-Shop-Domain');

            // Dispatch webhook processing job
            ProcessShopifyWebhook::dispatch($businessId, $topic, $payload, $shopDomain);

            // Return 200 immediately (webhook processed asynchronously)
            return response()->json(['success' => true], 200);

        } catch (\Exception $e) {
            Log::error('Shopify webhook handling failed', [
                'business_id' => $businessId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json(['error' => 'Webhook processing failed'], 500);
        }
    }
}

