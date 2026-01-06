<?php

namespace Modules\Shopify\Tests\Unit;

use Tests\TestCase;
use Modules\Shopify\Services\ShopifyApiService;
use App\Business;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ShopifyApiServiceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test API service initialization
     */
    public function test_api_service_initialization()
    {
        // This is a placeholder test
        // In a real scenario, you would mock the Business model and test initialization
        $this->assertTrue(true);
    }

    /**
     * Test webhook signature verification
     */
    public function test_webhook_signature_verification()
    {
        $payload = '{"test": "data"}';
        $secret = 'test_secret';
        $signature = base64_encode(hash_hmac('sha256', $payload, $secret, true));

        $result = ShopifyApiService::verifyWebhook($payload, $signature, $secret);
        $this->assertTrue($result);

        $result = ShopifyApiService::verifyWebhook($payload, 'invalid_signature', $secret);
        $this->assertFalse($result);
    }
}

