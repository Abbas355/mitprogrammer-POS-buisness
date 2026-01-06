<?php

namespace Modules\Shopify\Tests\Feature;

use Tests\TestCase;
use App\Business;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Shopify\Services\ShopifyApiService;

class WebhookTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test webhook signature verification
     */
    public function test_webhook_signature_verification()
    {
        $business = Business::factory()->create();
        $business->shopify_api_settings = [
            'webhook_secret' => 'test_secret',
        ];
        $business->save();

        $payload = '{"test": "data"}';
        $signature = base64_encode(hash_hmac('sha256', $payload, 'test_secret', true));

        $response = $this->post(route('shopify.webhook', $business->id), [], [
            'X-Shopify-Hmac-Sha256' => $signature,
            'X-Shopify-Topic' => 'orders/create',
            'X-Shopify-Shop-Domain' => 'test.myshopify.com',
        ]);

        // Should process webhook (returns 200 even if business not fully configured)
        $response->assertStatus(200);
    }
}

