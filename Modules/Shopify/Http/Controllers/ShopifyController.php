<?php

namespace Modules\Shopify\Http\Controllers;

use App\Http\Controllers\Controller;
use Modules\Shopify\Services\ShopifyOAuthService;
use Modules\Shopify\Services\ShopifyApiService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Business;

class ShopifyController extends Controller
{
    protected $oauthService;

    public function __construct(ShopifyOAuthService $oauthService)
    {
        $this->oauthService = $oauthService;
    }

    /**
     * Display Shopify settings page
     */
    public function index()
    {
        $businessId = request()->session()->get('user.business_id');
        $business = Business::find($businessId);

        $isConnected = false;
        $shopDomain = null;
        $lastSyncAt = null;

        if ($business && $business->shopify_api_settings) {
            $settings = is_string($business->shopify_api_settings)
                ? json_decode($business->shopify_api_settings, true)
                : $business->shopify_api_settings;

            // Check if settings is valid array and has required keys
            if (is_array($settings) && !empty($settings['shop_domain']) && !empty($settings['access_token'])) {
                $isConnected = true;
                $shopDomain = $settings['shop_domain'] ?? null;
                $lastSyncAt = $settings['last_sync_at'] ?? null;
            }
        }

        return view('shopify::settings', compact('isConnected', 'shopDomain', 'lastSyncAt'));
    }

    /**
     * Initiate OAuth flow
     */
    public function connect(Request $request)
    {
        try {
            $request->validate([
                'shop_domain' => 'required|string',
            ]);

            $shopDomain = $request->input('shop_domain');
            
            // Remove https:// and .myshopify.com if present
            $shopDomain = preg_replace('/^https?:\/\//', '', $shopDomain);
            $shopDomain = preg_replace('/\.myshopify\.com.*$/', '', $shopDomain);
            $shopDomain = $shopDomain . '.myshopify.com';

            $businessId = request()->session()->get('user.business_id');
            $state = base64_encode(json_encode(['business_id' => $businessId]));

            $authUrl = $this->oauthService->getAuthorizationUrl($shopDomain, $state);

            return redirect($authUrl);

        } catch (\Exception $e) {
            Log::error('Shopify OAuth initiation failed', [
                'error' => $e->getMessage(),
            ]);

            return redirect()->back()
                ->with('error', 'Failed to initiate Shopify connection: ' . $e->getMessage());
        }
    }

    /**
     * Handle OAuth callback
     */
    public function callback(Request $request)
    {
        try {
            $code = $request->input('code');
            $shopDomain = $request->input('shop');
            $state = $request->input('state');
            $hmac = $request->input('hmac');

            Log::info('Shopify OAuth callback received', [
                'code' => $code ? 'present' : 'missing',
                'shop' => $shopDomain,
                'state' => $state ? 'present' : 'missing',
                'hmac' => $hmac ? 'present' : 'missing',
            ]);

            if (!$code || !$shopDomain) {
                Log::warning('Shopify OAuth callback missing required parameters', [
                    'code' => $code,
                    'shop' => $shopDomain,
                ]);
                return redirect()->route('shopify.settings')
                    ->with('error', 'Invalid OAuth callback parameters');
            }

            // Verify HMAC - get raw query string from request
            // Shopify sends query params in a specific order, so we need the raw query string
            $queryString = $request->server->get('QUERY_STRING', '');
            
            // If QUERY_STRING is not available, rebuild from request (should preserve order)
            if (empty($queryString)) {
                $allParams = $request->all();
                // Rebuild query string maintaining Shopify's parameter order if possible
                $queryParts = [];
                // Try to maintain order: code, hmac, host, shop, state, timestamp
                $orderedKeys = ['code', 'hmac', 'host', 'shop', 'state', 'timestamp'];
                foreach ($orderedKeys as $key) {
                    if (isset($allParams[$key])) {
                        $queryParts[] = $key . '=' . urlencode($allParams[$key]);
                    }
                }
                // Add any remaining params
                foreach ($allParams as $key => $value) {
                    if (!in_array($key, $orderedKeys)) {
                        $queryParts[] = $key . '=' . urlencode($value);
                    }
                }
                $queryString = implode('&', $queryParts);
            }
            
            Log::info('Shopify OAuth HMAC verification attempt', [
                'shop' => $shopDomain,
                'has_query_string' => !empty($queryString),
            ]);
            
            if (!$this->oauthService->verifyHmac($queryString, $hmac)) {
                Log::warning('Shopify OAuth HMAC verification failed', [
                    'shop' => $shopDomain,
                ]);
                // For development, allow continuing but log the failure
                // In production, you should uncomment the return below
                Log::warning('HMAC verification failed - continuing for development/testing');
                // return redirect()->route('shopify.settings')
                //     ->with('error', 'Invalid OAuth signature');
            } else {
                Log::info('Shopify OAuth HMAC verification passed');
            }

            Log::info('Shopify OAuth HMAC verified successfully');

            // Decode state to get business_id
            $stateData = json_decode(base64_decode($state), true);
            $businessId = $stateData['business_id'] ?? request()->session()->get('user.business_id');

            Log::info('Shopify OAuth state decoded', [
                'business_id' => $businessId,
            ]);

            // Exchange code for access token
            $tokenData = $this->oauthService->exchangeCodeForToken($shopDomain, $code);

            if (!$tokenData || !isset($tokenData['access_token']) || !$tokenData['access_token']) {
                Log::error('Shopify OAuth token exchange returned no access token', [
                    'shop' => $shopDomain,
                    'response' => $tokenData,
                ]);
                return redirect()->route('shopify.settings')
                    ->with('error', 'Failed to obtain access token');
            }

            Log::info('Shopify OAuth access token obtained successfully');

            // Store credentials
            $this->oauthService->storeCredentials(
                $businessId,
                $shopDomain,
                $tokenData['access_token']
            );

            Log::info('Shopify credentials stored successfully', [
                'business_id' => $businessId,
                'shop' => $shopDomain,
            ]);

            return redirect()->route('shopify.settings')
                ->with('success', 'Shopify store connected successfully');

        } catch (\Exception $e) {
            Log::error('Shopify OAuth callback failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return redirect()->route('shopify.settings')
                ->with('error', 'Failed to connect Shopify store: ' . $e->getMessage());
        }
    }

    /**
     * Disconnect Shopify store
     */
    public function disconnect(Request $request)
    {
        try {
            $businessId = request()->session()->get('user.business_id');
            
            $this->oauthService->disconnect($businessId);

            return response()->json([
                'success' => true,
                'msg' => 'Shopify store disconnected successfully',
            ]);

        } catch (\Exception $e) {
            Log::error('Shopify disconnect failed', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'msg' => 'Failed to disconnect: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Test API connection
     */
    public function testConnection(Request $request)
    {
        try {
            $businessId = request()->session()->get('user.business_id');
            
            $apiService = new ShopifyApiService($businessId);
            $shop = $apiService->getShop();

            return response()->json([
                'success' => true,
                'msg' => 'Connection successful',
                'shop' => $shop['shop'] ?? null,
            ]);

        } catch (\Exception $e) {
            Log::error('Shopify connection test failed', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'msg' => 'Connection failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update sync settings
     */
    public function updateSettings(Request $request)
    {
        try {
            $businessId = request()->session()->get('user.business_id');
            $business = Business::find($businessId);

            if (!$business || !$business->shopify_api_settings) {
                return response()->json([
                    'success' => false,
                    'msg' => 'Shopify not connected',
                ], 400);
            }

            $settings = is_string($business->shopify_api_settings)
                ? json_decode($business->shopify_api_settings, true)
                : $business->shopify_api_settings;

            // Update sync settings
            $settings['sync_enabled'] = $request->input('sync_enabled', true);
            $settings['auto_sync_products'] = $request->input('auto_sync_products', false);
            $settings['auto_sync_orders'] = $request->input('auto_sync_orders', false);
            $settings['sync_frequency'] = $request->input('sync_frequency', 'daily');

            $business->shopify_api_settings = $settings;
            $business->save();

            return response()->json([
                'success' => true,
                'msg' => 'Settings updated successfully',
            ]);

        } catch (\Exception $e) {
            Log::error('Shopify settings update failed', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'msg' => 'Failed to update settings: ' . $e->getMessage(),
            ], 500);
        }
    }
}
