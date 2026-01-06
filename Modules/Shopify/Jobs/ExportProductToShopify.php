<?php

namespace Modules\Shopify\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Shopify\Services\ShopifyApiService;
use Modules\Shopify\Services\ProductMappingService;
use App\Product;
use App\Business;
use App\BusinessLocation;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class ExportProductToShopify implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $businessId;
    protected $productId;

    /**
     * Create a new job instance.
     */
    public function __construct($businessId, $productId)
    {
        $this->businessId = $businessId;
        $this->productId = $productId;
    }

    /**
     * Execute the job.
     */
    public function handle()
    {
        try {
            $product = Product::find($this->productId);
            
            if (!$product || $product->business_id != $this->businessId) {
                Log::error('Shopify export: Product not found', [
                    'product_id' => $this->productId,
                    'business_id' => $this->businessId,
                ]);
                return;
            }

            // Check if sync is disabled
            if ($product->shopify_disable_sync) {
                Log::info('Shopify export: Sync disabled for product', [
                    'product_id' => $this->productId,
                ]);
                return;
            }

            $business = Business::find($this->businessId);
            if (!$business || !$business->shopify_api_settings) {
                Log::error('Shopify export: Business not configured', [
                    'business_id' => $this->businessId,
                ]);
                return;
            }

            $apiService = new ShopifyApiService($this->businessId);
            $mappingService = new ProductMappingService();

            // Get default location
            $location = BusinessLocation::where('business_id', $this->businessId)->first();
            if (!$location) {
                Log::error('Shopify export: No business location found', [
                    'business_id' => $this->businessId,
                ]);
                return;
            }

            // Map product to Shopify format
            $shopifyProductData = $mappingService->mapToShopify($product, $location->id);

            DB::beginTransaction();
            try {
                if ($product->shopify_product_id) {
                    // Update existing product
                    $response = $apiService->updateProduct($product->shopify_product_id, $shopifyProductData);
                    Log::info('Shopify export: Product updated', [
                        'product_id' => $this->productId,
                        'shopify_product_id' => $product->shopify_product_id,
                    ]);
                } else {
                    // Create new product
                    $response = $apiService->createProduct($shopifyProductData);
                    $shopifyProduct = $response['product'] ?? null;

                    if ($shopifyProduct) {
                        // Update product with Shopify ID
                        $product->shopify_product_id = (string) $shopifyProduct['id'];
                        
                        // Update variant IDs
                        if (!empty($shopifyProduct['variants'])) {
                            $variations = $product->variations;
                            foreach ($shopifyProduct['variants'] as $index => $shopifyVariant) {
                                if (isset($variations[$index])) {
                                    $variations[$index]->shopify_variant_id = (string) $shopifyVariant['id'];
                                    $variations[$index]->save();
                                }
                            }
                        }

                        $product->save();

                        Log::info('Shopify export: Product created', [
                            'product_id' => $this->productId,
                            'shopify_product_id' => $shopifyProduct['id'],
                        ]);
                    }
                }

                // Update last synced timestamp
                $product->shopify_last_synced_at = now();
                $product->save();

                DB::commit();

            } catch (\Exception $e) {
                DB::rollBack();
                Log::error('Shopify export: Failed to sync product', [
                    'product_id' => $this->productId,
                    'error' => $e->getMessage(),
                ]);
                throw $e;
            }

        } catch (\Exception $e) {
            Log::error('Shopify product export failed', [
                'product_id' => $this->productId,
                'business_id' => $this->businessId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}

