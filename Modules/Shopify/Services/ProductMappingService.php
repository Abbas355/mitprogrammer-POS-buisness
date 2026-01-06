<?php

namespace Modules\Shopify\Services;

use App\Product;
use App\Variation;
use App\BusinessLocation;
use Illuminate\Support\Facades\Log;

class ProductMappingService
{
    /**
     * Map UltimatePOS product to Shopify format
     */
    public function mapToShopify(Product $product, $locationId = null)
    {
        $shopifyProduct = [
            'title' => $product->name,
            'body_html' => $product->product_description ?? '',
            'vendor' => $product->brand->name ?? '',
            'product_type' => $product->category->name ?? '',
            'tags' => $this->getProductTags($product),
            'variants' => [],
            'images' => [],
        ];

        // Handle variants
        $variations = $product->variations;
        
        if ($variations->count() === 0) {
            // Single product - create one variant
            $variation = $this->mapVariationToShopify($product, null, $locationId);
            $shopifyProduct['variants'][] = $variation;
        } else {
            // Variable product - create variants
            $options = [];
            $optionNames = [];

            foreach ($variations as $variation) {
                $shopifyVariant = $this->mapVariationToShopify($product, $variation, $locationId);
                $shopifyProduct['variants'][] = $shopifyVariant;

                // Extract option values
                if ($variation->name !== 'Default') {
                    $optionParts = explode('-', $variation->name);
                    foreach ($optionParts as $index => $value) {
                        if (!isset($optionNames[$index])) {
                            $optionNames[$index] = 'Option ' . ($index + 1);
                        }
                        if (!isset($options[$index])) {
                            $options[$index] = [];
                        }
                        if (!in_array(trim($value), $options[$index])) {
                            $options[$index][] = trim($value);
                        }
                    }
                }
            }

            // Set options
            if (!empty($options)) {
                $shopifyProduct['options'] = [];
                foreach ($options as $index => $values) {
                    $shopifyProduct['options'][] = [
                        'name' => $optionNames[$index] ?? 'Option ' . ($index + 1),
                        'values' => $values,
                    ];
                }
            }
        }

        // Handle images
        if ($product->image) {
            $shopifyProduct['images'][] = [
                'src' => asset('uploads/img/' . $product->image),
            ];
        }

        return $shopifyProduct;
    }

    /**
     * Map variation to Shopify variant
     */
    protected function mapVariationToShopify(Product $product, $variation = null, $locationId = null)
    {
        $variant = $variation ?? $product->variations->first();

        if (!$variant) {
            throw new \Exception('No variation found for product');
        }

        $shopifyVariant = [
            'sku' => $variant->sub_sku ?? $product->sku,
            'price' => (string) $this->calculateShopifyPrice($variant->default_sell_price ?? 0),
            'compare_at_price' => null,
            'inventory_management' => $product->enable_stock ? 'shopify' : null,
            'inventory_quantity' => 0,
            'requires_shipping' => true,
            'taxable' => $product->tax ? true : false,
        ];

        // Get inventory quantity
        if ($product->enable_stock && $locationId) {
            $locationDetails = \App\VariationLocationDetails::where('variation_id', $variant->id)
                ->where('location_id', $locationId)
                ->first();

            if ($locationDetails) {
                $shopifyVariant['inventory_quantity'] = (int) $locationDetails->qty_available;
            }
        }

        // Set variant title if it's not default
        if ($variation && $variation->name !== 'Default') {
            $shopifyVariant['title'] = $variation->name;
        }

        return $shopifyVariant;
    }

    /**
     * Download image from URL and save to product images directory
     */
    protected function downloadAndSaveImage($imageUrl, $productId = null)
    {
        try {
            if (empty($imageUrl) || !filter_var($imageUrl, FILTER_VALIDATE_URL)) {
                return null;
            }

            // Download image
            $imageContent = @file_get_contents($imageUrl);
            if ($imageContent === false) {
                Log::warning('Shopify sync: Failed to download image', ['url' => $imageUrl]);
                return null;
            }

            // Get file extension from URL or content
            $pathInfo = pathinfo(parse_url($imageUrl, PHP_URL_PATH));
            $extension = $pathInfo['extension'] ?? 'jpg';
            
            // Clean extension (remove query params if any)
            $extension = explode('?', $extension)[0];
            if (!in_array(strtolower($extension), ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
                $extension = 'jpg'; // Default to jpg if extension is not recognized
            }

            // Generate unique filename
            $filename = 'shopify_' . time() . '_' . ($productId ?? uniqid()) . '.' . $extension;
            
            // Ensure directory exists
            $uploadPath = public_path('uploads/' . config('constants.product_img_path'));
            if (!file_exists($uploadPath)) {
                mkdir($uploadPath, 0755, true);
            }

            // Save image
            $filePath = $uploadPath . '/' . $filename;
            file_put_contents($filePath, $imageContent);

            Log::debug('Shopify sync: Image downloaded and saved', [
                'url' => $imageUrl,
                'filename' => $filename,
            ]);

            return $filename;

        } catch (\Exception $e) {
            Log::error('Shopify sync: Error downloading image', [
                'url' => $imageUrl,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Map Shopify product to UltimatePOS format
     */
    public function mapFromShopify($shopifyProduct, $businessId, $locationId = null)
    {
        $productData = [
            'name' => $shopifyProduct['title'],
            'business_id' => $businessId,
            'type' => count($shopifyProduct['variants'] ?? []) > 1 ? 'variable' : 'single',
            'product_description' => $shopifyProduct['body_html'] ?? '',
            'sku' => $shopifyProduct['variants'][0]['sku'] ?? '',
            'enable_stock' => isset($shopifyProduct['variants'][0]['inventory_management']) ? 1 : 0,
            'shopify_product_id' => (string) $shopifyProduct['id'],
        ];

        // Download and save product image (use first image from Shopify)
        if (!empty($shopifyProduct['images']) && count($shopifyProduct['images']) > 0) {
            $firstImage = $shopifyProduct['images'][0];
            $imageUrl = $firstImage['src'] ?? null;
            
            if ($imageUrl) {
                $imageFilename = $this->downloadAndSaveImage($imageUrl, $shopifyProduct['id']);
                if ($imageFilename) {
                    $productData['image'] = $imageFilename;
                }
            }
        }

        // Get business owner_id for created_by (needed for Brand and Category creation)
        $business = \App\Business::find($businessId);
        $ownerId = $business->owner_id ?? null;

        // Map brand if vendor exists
        if (!empty($shopifyProduct['vendor'])) {
            // Try to find existing brand first
            $brand = \App\Brands::where('business_id', $businessId)
                ->where('name', $shopifyProduct['vendor'])
                ->first();
            
            if (!$brand && $ownerId) {
                // Create new brand with owner_id as created_by
                $brand = \App\Brands::create([
                    'business_id' => $businessId,
                    'name' => $shopifyProduct['vendor'],
                    'description' => '',
                    'created_by' => $ownerId,
                ]);
            }
            
            if ($brand) {
                $productData['brand_id'] = $brand->id;
            }
        }

        // Map category if product_type exists
        if (!empty($shopifyProduct['product_type'])) {
            // Try to find existing category first
            $category = \App\Category::where('business_id', $businessId)
                ->where('name', $shopifyProduct['product_type'])
                ->where('category_type', 'product')
                ->where('parent_id', 0)
                ->first();
            
            if (!$category && $ownerId) {
                // Create new category with owner_id as created_by
                $category = \App\Category::create([
                    'business_id' => $businessId,
                    'name' => $shopifyProduct['product_type'],
                    'short_code' => strtoupper(substr($shopifyProduct['product_type'], 0, 3)),
                    'category_type' => 'product',
                    'parent_id' => 0,
                    'created_by' => $ownerId,
                ]);
            }
            
            if ($category) {
                $productData['category_id'] = $category->id;
            }
        }

        return $productData;
    }

    /**
     * Map Shopify variant to UltimatePOS variation
     */
    public function mapVariantFromShopify($shopifyVariant, $productId, $locationId = null)
    {
        $variationData = [
            'product_id' => $productId,
            'name' => $shopifyVariant['title'] ?? 'Default',
            'sub_sku' => $shopifyVariant['sku'] ?? '',
            'default_sell_price' => $this->calculateUltimatePOSPrice($shopifyVariant['price'] ?? 0),
            'shopify_variant_id' => (string) $shopifyVariant['id'],
        ];

        return $variationData;
    }

    /**
     * Calculate Shopify price (convert to Shopify format)
     */
    protected function calculateShopifyPrice($price)
    {
        // Shopify prices are in decimal format (e.g., 19.99)
        return number_format((float) $price, 2, '.', '');
    }

    /**
     * Calculate UltimatePOS price (convert from Shopify format)
     */
    protected function calculateUltimatePOSPrice($price)
    {
        // Convert from Shopify decimal format
        return (float) $price;
    }

    /**
     * Get product tags from UltimatePOS product
     */
    protected function getProductTags(Product $product)
    {
        $tags = [];

        if ($product->category) {
            $tags[] = $product->category->name;
        }

        if ($product->brand) {
            $tags[] = $product->brand->name;
        }

        return implode(', ', $tags);
    }

    /**
     * Update inventory from Shopify
     */
    public function updateInventoryFromShopify($shopifyVariant, $variationId, $locationId)
    {
        $quantity = $shopifyVariant['inventory_quantity'] ?? 0;

        $locationDetails = \App\VariationLocationDetails::where('variation_id', $variationId)
            ->where('location_id', $locationId)
            ->first();

        if ($locationDetails) {
            $locationDetails->qty_available = $quantity;
            $locationDetails->save();
        } else {
            // Create new location details
            \App\VariationLocationDetails::create([
                'variation_id' => $variationId,
                'location_id' => $locationId,
                'qty_available' => $quantity,
            ]);
        }

        return true;
    }
}

