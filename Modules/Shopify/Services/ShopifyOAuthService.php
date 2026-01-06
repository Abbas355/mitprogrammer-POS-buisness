<?php

namespace Modules\Shopify\Services;

use App\Business;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Crypt;
use GuzzleHttp\Client;

class ShopifyOAuthService
{
    protected $apiKey;
    protected $apiSecret;
    protected $redirectUri;
    protected $scopes = 'read_products,write_products,read_orders,write_orders,read_inventory,write_inventory';

    public function __construct()
    {
        $this->apiKey = config('shopify.api_key', env('SHOPIFY_API_KEY'));
        $this->apiSecret = config('shopify.api_secret', env('SHOPIFY_API_SECRET'));
        $this->redirectUri = config('shopify.redirect_uri', env('SHOPIFY_REDIRECT_URI', url('/shopify/callback')));
    }

    /**
     * Generate OAuth authorization URL
     */
    public function getAuthorizationUrl($shopDomain, $state = null)
    {
        if (!$this->apiKey) {
            throw new \Exception('Shopify API key not configured');
        }

        $params = [
            'client_id' => $this->apiKey,
            'scope' => $this->scopes,
            'redirect_uri' => $this->redirectUri,
        ];

        if ($state) {
            $params['state'] = $state;
        }

        $queryString = http_build_query($params);

        return "https://{$shopDomain}/admin/oauth/authorize?{$queryString}";
    }

    /**
     * Exchange authorization code for access token
     */
    public function exchangeCodeForToken($shopDomain, $code)
    {
        if (!$this->apiSecret) {
            throw new \Exception('Shopify API secret not configured');
        }

        $client = new Client();

        try {
            $response = $client->post("https://{$shopDomain}/admin/oauth/access_token", [
                'json' => [
                    'client_id' => $this->apiKey,
                    'client_secret' => $this->apiSecret,
                    'code' => $code,
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            return [
                'access_token' => $data['access_token'] ?? null,
                'scope' => $data['scope'] ?? null,
            ];

        } catch (\Exception $e) {
            Log::error('Shopify OAuth token exchange failed', [
                'shop_domain' => $shopDomain,
                'error' => $e->getMessage(),
            ]);

            throw new \Exception('Failed to exchange code for access token: ' . $e->getMessage());
        }
    }

    /**
     * Store Shopify credentials for a business
     */
    public function storeCredentials($businessId, $shopDomain, $accessToken, $additionalData = [])
    {
        $business = Business::find($businessId);

        if (!$business) {
            throw new \Exception('Business not found');
        }

        $settings = [
            'shop_domain' => $shopDomain,
            'access_token' => $accessToken,
            'api_key' => $this->apiKey,
            'api_secret' => $this->apiSecret,
            'sync_enabled' => true,
            'last_sync_at' => null,
            'connected_at' => now()->toDateTimeString(),
        ];

        // Merge additional data
        $settings = array_merge($settings, $additionalData);

        // Encrypt sensitive data
        $settings['access_token'] = Crypt::encryptString($accessToken);
        if (isset($settings['api_secret'])) {
            $settings['api_secret'] = Crypt::encryptString($settings['api_secret']);
        }

        $business->shopify_api_settings = $settings;
        $business->save();

        Log::info('Shopify credentials stored', [
            'business_id' => $businessId,
            'shop_domain' => $shopDomain,
        ]);

        return true;
    }

    /**
     * Get stored credentials for a business
     */
    public function getCredentials($businessId)
    {
        $business = Business::find($businessId);

        if (!$business || !$business->shopify_api_settings) {
            return null;
        }

        $settings = is_string($business->shopify_api_settings)
            ? json_decode($business->shopify_api_settings, true)
            : $business->shopify_api_settings;

        if (!$settings) {
            return null;
        }

        // Decrypt sensitive data
        if (isset($settings['access_token'])) {
            try {
                $settings['access_token'] = Crypt::decryptString($settings['access_token']);
            } catch (\Exception $e) {
                Log::error('Failed to decrypt Shopify access token', [
                    'business_id' => $businessId,
                    'error' => $e->getMessage(),
                ]);
                return null;
            }
        }

        if (isset($settings['api_secret'])) {
            try {
                $settings['api_secret'] = Crypt::decryptString($settings['api_secret']);
            } catch (\Exception $e) {
                // API secret decryption failure is not critical
            }
        }

        return $settings;
    }

    /**
     * Remove Shopify connection
     */
    public function disconnect($businessId)
    {
        $business = Business::find($businessId);

        if (!$business) {
            throw new \Exception('Business not found');
        }

        $business->shopify_api_settings = null;
        $business->save();

        Log::info('Shopify connection removed', [
            'business_id' => $businessId,
        ]);

        return true;
    }

    /**
     * Verify HMAC signature from Shopify
     */
    public function verifyHmac($query, $hmac)
    {
        if (!$this->apiSecret) {
            return false;
        }

        // Remove hmac and signature from query
        $params = [];
        parse_str($query, $params);
        unset($params['hmac']);
        unset($params['signature']);

        // Sort and encode
        ksort($params);
        $queryString = http_build_query($params);

        // Calculate HMAC
        $calculatedHmac = hash_hmac('sha256', $queryString, $this->apiSecret);

        return hash_equals($hmac, $calculatedHmac);
    }
}

