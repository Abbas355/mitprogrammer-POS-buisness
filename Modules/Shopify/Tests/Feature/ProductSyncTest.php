<?php

namespace Modules\Shopify\Tests\Feature;

use Tests\TestCase;
use App\User;
use App\Business;
use App\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ProductSyncTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test product sync endpoint
     */
    public function test_product_sync_endpoint()
    {
        $user = User::factory()->create();
        $business = Business::factory()->create(['owner_id' => $user->id]);

        $response = $this->actingAs($user)
            ->post(route('shopify.sync.products'), [
                '_token' => csrf_token(),
            ]);

        // Should return success even if not connected (will fail in job)
        $response->assertStatus(200);
    }
}

