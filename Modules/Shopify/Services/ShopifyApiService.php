<?php

namespace Modules\Shopify\Services;

use App\Business;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

class ShopifyApiService
{
    protected $client;
    protected $shopDomain;
    protected $accessToken;
    protected $apiVersion = '2024-01';
    protected $rateLimitRemaining = 40;
    protected $rateLimitResetAt = null;

    public function __construct($businessId)
    {
        $business = Business::find($businessId);
        
        if (!$business || !$business->shopify_api_settings) {
            throw new \Exception('Shopify API settings not configured for this business');
        }

        $settings = is_string($business->shopify_api_settings) 
            ? json_decode($business->shopify_api_settings, true) 
            : $business->shopify_api_settings;

        $this->shopDomain = $settings['shop_domain'] ?? null;
        
        // Decrypt access token if encrypted
        $accessToken = $settings['access_token'] ?? null;
        if ($accessToken) {
            try {
                $this->accessToken = Crypt::decryptString($accessToken);
            } catch (\Exception $e) {
                // If decryption fails, assume it's not encrypted (for backward compatibility)
                $this->accessToken = $accessToken;
            }
        }

        if (!$this->shopDomain || !$this->accessToken) {
            throw new \Exception('Shopify credentials not configured');
        }

        $this->client = new Client([
            'base_uri' => "https://{$this->shopDomain}/admin/api/{$this->apiVersion}/",
            'headers' => [
                'X-Shopify-Access-Token' => $this->accessToken,
                'Content-Type' => 'application/json',
            ],
            'timeout' => 30,
        ]);
    }

    /**
     * Make API request with rate limiting and retry logic
     */
    protected function makeRequest($method, $endpoint, $options = [])
    {
        $maxRetries = 3;
        $retryDelay = 1;

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                // Check rate limit
                $this->checkRateLimit();

                $response = $this->client->request($method, $endpoint, $options);
                
                // Update rate limit info from headers
                $this->updateRateLimitInfo($response);

                return json_decode($response->getBody()->getContents(), true);

            } catch (RequestException $e) {
                $statusCode = $e->getResponse() ? $e->getResponse()->getStatusCode() : 0;

                // Handle rate limiting
                if ($statusCode === 429) {
                    $retryAfter = $e->getResponse()->getHeader('Retry-After')[0] ?? 2;
                    Log::warning("Shopify API rate limit hit. Retrying after {$retryAfter} seconds");
                    sleep($retryAfter);
                    continue;
                }

                // Handle server errors with retry
                if ($statusCode >= 500 && $attempt < $maxRetries) {
                    Log::warning("Shopify API server error. Retrying attempt {$attempt}/{$maxRetries}");
                    sleep($retryDelay * $attempt);
                    continue;
                }

                // Log error and rethrow
                Log::error('Shopify API Error', [
                    'method' => $method,
                    'endpoint' => $endpoint,
                    'status' => $statusCode,
                    'message' => $e->getMessage(),
                ]);

                throw $e;
            }
        }

        throw new \Exception('Failed to make Shopify API request after ' . $maxRetries . ' attempts');
    }

    /**
     * Check and enforce rate limits
     */
    protected function checkRateLimit()
    {
        $cacheKey = "shopify_rate_limit_{$this->shopDomain}";
        $rateLimit = Cache::get($cacheKey, ['remaining' => 40, 'reset_at' => now()->addSeconds(2)]);

        if ($rateLimit['remaining'] <= 0 && now()->lt($rateLimit['reset_at'])) {
            $waitSeconds = now()->diffInSeconds($rateLimit['reset_at']) + 1;
            Log::warning("Shopify rate limit reached. Waiting {$waitSeconds} seconds");
            sleep($waitSeconds);
        }
    }

    /**
     * Update rate limit info from response headers
     */
    protected function updateRateLimitInfo($response)
    {
        $cacheKey = "shopify_rate_limit_{$this->shopDomain}";
        $remaining = (int) ($response->getHeader('X-Shopify-Shop-Api-Call-Limit')[0] ?? '40/40');
        $remaining = explode('/', $remaining)[0] ?? 40;

        Cache::put($cacheKey, [
            'remaining' => (int) $remaining,
            'reset_at' => now()->addSeconds(2),
        ], 5);
    }

    /**
     * Get products from Shopify (using cursor-based pagination with since_id)
     * Returns array with 'products' array
     */
    public function getProducts($params = [])
    {
        $defaultParams = [
            'limit' => 250,
        ];

        // Remove 'page' parameter if present (Shopify doesn't support it)
        unset($params['page']);

        $params = array_merge($defaultParams, $params);
        
        // Only include since_id if it's provided and not null
        // Note: We don't check for empty() because 0 could be a valid ID
        if (array_key_exists('since_id', $params) && $params['since_id'] === null) {
            unset($params['since_id']);
        }
        
        $queryString = http_build_query($params);

        $result = $this->makeRequest('GET', "products.json?{$queryString}");
        
        return $result;
    }

    /**
     * Get single product by ID
     */
    public function getProduct($productId)
    {
        return $this->makeRequest('GET', "products/{$productId}.json");
    }

    /**
     * Create product in Shopify
     */
    public function createProduct($productData)
    {
        return $this->makeRequest('POST', 'products.json', [
            'json' => ['product' => $productData],
        ]);
    }

    /**
     * Update product in Shopify
     */
    public function updateProduct($productId, $productData)
    {
        return $this->makeRequest('PUT', "products/{$productId}.json", [
            'json' => ['product' => $productData],
        ]);
    }

    /**
     * Get orders from Shopify
     */
    public function getOrders($params = [])
    {
        $defaultParams = [
            'limit' => 250,
            'status' => 'any',
        ];

        $params = array_merge($defaultParams, $params);
        $queryString = http_build_query($params);

        return $this->makeRequest('GET', "orders.json?{$queryString}");
    }

    /**
     * Get single order by ID
     */
    public function getOrder($orderId)
    {
        return $this->makeRequest('GET', "orders/{$orderId}.json");
    }

    /**
     * Update inventory level
     */
    public function updateInventory($locationId, $inventoryItemId, $quantity)
    {
        return $this->makeRequest('POST', 'inventory_levels/set.json', [
            'json' => [
                'location_id' => $locationId,
                'inventory_item_id' => $inventoryItemId,
                'available' => $quantity,
            ],
        ]);
    }

    /**
     * Get inventory levels for a location
     */
    public function getInventoryLevels($locationId, $params = [])
    {
        $defaultParams = [
            'location_id' => $locationId,
            'limit' => 250,
        ];

        $params = array_merge($defaultParams, $params);
        $queryString = http_build_query($params);

        return $this->makeRequest('GET', "inventory_levels.json?{$queryString}");
    }

    /**
     * Create webhook
     */
    public function createWebhook($topic, $address)
    {
        return $this->makeRequest('POST', 'webhooks.json', [
            'json' => [
                'webhook' => [
                    'topic' => $topic,
                    'address' => $address,
                    'format' => 'json',
                ],
            ],
        ]);
    }

    /**
     * Get webhooks
     */
    public function getWebhooks()
    {
        return $this->makeRequest('GET', 'webhooks.json');
    }

    /**
     * Delete webhook
     */
    public function deleteWebhook($webhookId)
    {
        return $this->makeRequest('DELETE', "webhooks/{$webhookId}.json");
    }

    /**
     * Verify webhook signature
     */
    public static function verifyWebhook($payload, $signature, $secret)
    {
        $calculatedSignature = base64_encode(
            hash_hmac('sha256', $payload, $secret, true)
        );

        return hash_equals($calculatedSignature, $signature);
    }

    /**
     * Get shop information
     */
    public function getShop()
    {
        return $this->makeRequest('GET', 'shop.json');
    }
}

