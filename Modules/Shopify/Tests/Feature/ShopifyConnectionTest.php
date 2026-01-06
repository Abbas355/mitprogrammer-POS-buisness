<?php

namespace Modules\Shopify\Tests\Feature;

use Tests\TestCase;
use App\User;
use App\Business;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Session;

class ShopifyConnectionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test settings page access
     */
    public function test_settings_page_access()
    {
        $user = User::factory()->create();
        $business = Business::factory()->create(['owner_id' => $user->id]);

        $response = $this->actingAs($user)
            ->get(route('shopify.settings'));

        $response->assertStatus(200);
    }

    /**
     * Test connection flow (placeholder)
     */
    public function test_connection_flow()
    {
        // This would test the OAuth flow in a real scenario
        // Requires mocking Shopify OAuth endpoints
        $this->assertTrue(true);
    }
}

