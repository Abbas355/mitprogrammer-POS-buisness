<?php

namespace Modules\Shopify\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Shopify\Services\ShopifyApiService;
use App\Business;
use Illuminate\Support\Facades\Log;

class VerifyShopifyWebhook
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        $businessId = $request->route('businessId');
        $business = Business::find($businessId);

        if (!$business || !$business->shopify_api_settings) {
            Log::warning('Shopify webhook: Business not found or not configured', [
                'business_id' => $businessId,
            ]);
            return response()->json(['error' => 'Business not found'], 404);
        }

        // Get webhook secret
        $settings = is_string($business->shopify_api_settings)
            ? json_decode($business->shopify_api_settings, true)
            : $business->shopify_api_settings;

        $webhookSecret = $settings['webhook_secret'] ?? null;

        if (!$webhookSecret) {
            Log::warning('Shopify webhook: Webhook secret not configured', [
                'business_id' => $businessId,
            ]);
            return response()->json(['error' => 'Webhook not configured'], 400);
        }

        // Verify HMAC signature
        $hmac = $request->header('X-Shopify-Hmac-Sha256');
        $payload = $request->getContent();

        if (!$hmac || !ShopifyApiService::verifyWebhook($payload, $hmac, $webhookSecret)) {
            Log::warning('Shopify webhook: Invalid signature', [
                'business_id' => $businessId,
            ]);
            return response()->json(['error' => 'Invalid signature'], 401);
        }

        return $next($request);
    }
}

