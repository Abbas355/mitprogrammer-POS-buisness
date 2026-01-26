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
use App\Variation;
use App\Business;
use App\BusinessLocation;
use App\Unit;
use App\Utils\ProductUtil;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class SyncShopifyProducts implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $businessId;

    /**
     * Create a new job instance.
     */
    public function __construct($businessId)
    {
        $this->businessId = $businessId;
    }

    /**
     * Execute the job.
     */
    public function handle()
    {
        try {
            $business = Business::find($this->businessId);
            
            if (!$business || !$business->shopify_api_settings) {
                Log::error('Shopify sync: Business not configured', [
                    'business_id' => $this->businessId,
                ]);
                return;
            }

            $apiService = new ShopifyApiService($this->businessId);
            $mappingService = new ProductMappingService();

            // Get default location
            $location = BusinessLocation::where('business_id', $this->businessId)->first();
            if (!$location) {
                Log::error('Shopify sync: No business location found', [
                    'business_id' => $this->businessId,
                ]);
                return;
            }

            // Get default unit for this business (units are business-specific)
            $defaultUnit = Unit::where('business_id', $this->businessId)
                ->whereNull('base_unit_id') // Base unit, not a sub-unit
                ->first();
            
            if (!$defaultUnit) {
                Log::error('Shopify sync: No unit found for business', [
                    'business_id' => $this->businessId,
                ]);
                return;
            }

            // Use cursor-based pagination with since_id
            $sinceId = null;
            $hasMore = true;
            $totalSynced = 0;
            $maxIterations = 1000; // Safety limit to prevent infinite loops
            $iterationCount = 0;

            Log::info('Shopify product sync started', [
                'business_id' => $this->businessId,
            ]);

            while ($hasMore && $iterationCount < $maxIterations) {
                $iterationCount++;
                
                $params = ['limit' => 250];
                if ($sinceId) {
                    $params['since_id'] = $sinceId;
                }
                
                Log::info('Shopify sync: Fetching products batch', [
                    'iteration' => $iterationCount,
                    'since_id' => $sinceId,
                ]);
                
                $response = $apiService->getProducts($params);
                $products = $response['products'] ?? [];

                Log::info('Shopify sync: Products fetched', [
                    'count' => count($products),
                ]);

                if (empty($products)) {
                    Log::info('Shopify sync: No more products to sync');
                    $hasMore = false;
                    break;
                }

                // Track the last product ID for pagination (must be outside try/catch)
                $lastProductId = null;

                foreach ($products as $shopifyProduct) {
                    $lastProductId = $shopifyProduct['id'] ?? null;
                    
                    DB::beginTransaction();
                    try {
                        $this->syncProduct($shopifyProduct, $business, $location, $mappingService, $defaultUnit->id);
                        DB::commit();
                        $totalSynced++;
                        
                        Log::debug('Shopify sync: Product synced successfully', [
                            'product_id' => $lastProductId,
                            'total_synced' => $totalSynced,
                        ]);
                    } catch (\Exception $e) {
                        DB::rollBack();
                        Log::error('Shopify sync: Failed to sync product', [
                            'product_id' => $lastProductId,
                            'error' => $e->getMessage(),
                            'trace' => $e->getTraceAsString(),
                        ]);
                        // Continue with next product even if this one fails
                    }
                }

                // Update since_id to the last product ID from this batch (even if some failed)
                // This ensures we don't get stuck in an infinite loop
                if ($lastProductId) {
                    $sinceId = $lastProductId;
                    Log::info('Shopify sync: Batch processed', [
                        'last_product_id' => $sinceId,
                        'products_in_batch' => count($products),
                        'successfully_synced_in_batch' => count($products), // Simplified for now
                    ]);
                }

                // If we got fewer products than the limit, we've reached the end
                if (count($products) < 250) {
                    Log::info('Shopify sync: Reached end of products (less than 250)');
                    $hasMore = false;
                }
            }

            if ($iterationCount >= $maxIterations) {
                Log::warning('Shopify sync: Reached maximum iterations limit', [
                    'max_iterations' => $maxIterations,
                    'last_since_id' => $sinceId,
                ]);
            }
            
            Log::info('Shopify product sync batch completed', [
                'business_id' => $this->businessId,
                'total_synced' => $totalSynced,
            ]);

            // Update last sync time
            $settings = is_string($business->shopify_api_settings)
                ? json_decode($business->shopify_api_settings, true)
                : $business->shopify_api_settings;
            
            $settings['last_sync_at'] = now()->toDateTimeString();
            $business->shopify_api_settings = $settings;
            $business->save();

            Log::info('Shopify product sync completed', [
                'business_id' => $this->businessId,
            ]);

        } catch (\Exception $e) {
            Log::error('Shopify product sync failed', [
                'business_id' => $this->businessId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    /**
     * Sync a single product from Shopify
     */
    protected function syncProduct($shopifyProduct, $business, $location, $mappingService, $defaultUnitId)
    {
        $shopifyProductId = (string) ($shopifyProduct['id'] ?? '');
        $shopifySku = $shopifyProduct['variants'][0]['sku'] ?? '';
        
        // Check if product already exists by shopify_product_id
        $product = Product::where('business_id', $business->id)
            ->where('shopify_product_id', $shopifyProductId)
            ->first();

        // Also check by SKU as fallback (in case shopify_product_id wasn't saved)
        if (!$product && !empty($shopifySku)) {
            $product = Product::where('business_id', $business->id)
                ->where('sku', $shopifySku)
                ->first();
            
            // If found by SKU but doesn't have shopify_product_id, update it
            if ($product && empty($product->shopify_product_id)) {
                $product->shopify_product_id = $shopifyProductId;
                $product->save();
                Log::debug('Shopify product sync: Updated existing product with shopify_product_id', [
                    'product_id' => $product->id,
                    'shopify_product_id' => $shopifyProductId,
                    'sku' => $shopifySku,
                ]);
            }
        }

        $productData = $mappingService->mapFromShopify($shopifyProduct, $business->id, $location->id);

        if ($product) {
            // Update existing product
            // Only update image if product doesn't have one (don't overwrite existing images)
            if (!empty($productData['image']) && empty($product->image)) {
                // Product doesn't have an image, so use the downloaded one
                $product->update($productData);
            } else {
                // Don't update image field if product already has one or we didn't download a new one
                unset($productData['image']);
                $product->update($productData);
            }
            
            // Ensure existing product has a valid SKU
            if (empty(trim($product->sku)) || $product->sku === ' ' || $product->sku === '') {
                // Use Shopify product ID as SKU if SKU is empty
                $product->sku = 'SHOPIFY-' . $shopifyProduct['id'];
                $product->save();
            }
        } else {
            // Create new product
            $productData['created_by'] = $business->owner_id;
            $productData['unit_id'] = $defaultUnitId; // Use business-specific default unit
            $productData['type'] = count($shopifyProduct['variants'] ?? []) > 1 ? 'variable' : 'single';
            $productData['enable_stock'] = isset($shopifyProduct['variants'][0]['inventory_management']) ? 1 : 0;
            $productData['not_for_selling'] = 0; // Default to allow selling
            $productData['tax_type'] = 'exclusive';
            $productData['barcode_type'] = 'C128';
            $productData['alert_quantity'] = 0;

            // Ensure SKU is set - use Shopify product ID if SKU is empty
            if (empty($productData['sku']) || trim($productData['sku']) === '') {
                $productData['sku'] = 'SHOPIFY-' . $shopifyProduct['id'];
            }

            $product = Product::create($productData);
            
            // After creation, if SKU is still empty or just a space, generate one using ProductUtil
            if (empty(trim($product->sku)) || $product->sku === ' ' || $product->sku === '') {
                $productUtil = new ProductUtil();
                $sku = $productUtil->generateProductSku($product->id);
                $product->sku = $sku;
                $product->save();
            }
        }

        // Sync variants
        // For single products, we need a DUMMY product_variation
        // For variable products, we'll create product_variations based on options
        $isSingleProduct = count($shopifyProduct['variants'] ?? []) <= 1;
        
        if ($isSingleProduct) {
            // Single product - use or create DUMMY product_variation
            $productVariation = \App\ProductVariation::where('product_id', $product->id)
                ->where('name', 'DUMMY')
                ->first();
            
            if (!$productVariation) {
                $productVariation = \App\ProductVariation::create([
                    'name' => 'DUMMY',
                    'product_id' => $product->id,
                    'is_dummy' => 1,
                ]);
            }
        }

        foreach ($shopifyProduct['variants'] ?? [] as $index => $shopifyVariant) {
            $variationData = $mappingService->mapVariantFromShopify(
                $shopifyVariant,
                $product->id,
                $location->id
            );

            $variation = Variation::where('product_id', $product->id)
                ->where('shopify_variant_id', $shopifyVariant['id'])
                ->first();

            if ($variation) {
                $variation->update($variationData);
            } else {
                // For variable products, create product_variation for each option
                if (!$isSingleProduct && isset($shopifyProduct['options']) && !empty($shopifyProduct['options'])) {
                    // Use the first option for grouping variants
                    $optionName = $shopifyProduct['options'][0]['name'] ?? 'Default';
                    $optionValue = $shopifyVariant['option1'] ?? 'Default';
                    
                    // Find or create product_variation for this option
                    $productVariation = \App\ProductVariation::where('product_id', $product->id)
                        ->where('name', $optionName)
                        ->first();
                    
                    if (!$productVariation) {
                        $productVariation = \App\ProductVariation::create([
                            'name' => $optionName,
                            'product_id' => $product->id,
                            'is_dummy' => 0,
                        ]);
                    }
                }
                
                // Add product_variation_id to variation data
                $variationData['product_variation_id'] = $productVariation->id;
                
                // Create variation through product_variation relationship to ensure proper structure
                $variation = $productVariation->variations()->create($variationData);
            }

            // Update inventory
            if ($product->enable_stock) {
                $mappingService->updateInventoryFromShopify(
                    $shopifyVariant,
                    $variation->id,
                    $location->id
                );
            }
        }

        // Assign product to default location if not already assigned
        if ($product->product_locations->isEmpty()) {
            $product->product_locations()->sync([$location->id]);
        }

        // Update last synced timestamp
        $product->shopify_last_synced_at = now();
        $product->save();
    }
}

