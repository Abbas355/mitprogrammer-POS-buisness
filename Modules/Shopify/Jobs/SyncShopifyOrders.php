<?php

namespace Modules\Shopify\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Shopify\Services\ShopifyApiService;
use Modules\Shopify\Services\ProductMappingService;
use App\Transaction;
use App\Contact;
use App\Product;
use App\Variation;
use App\Business;
use App\BusinessLocation;
use App\Unit;
use App\Utils\TransactionUtil;
use App\Utils\ProductUtil;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class SyncShopifyOrders implements ShouldQueue
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
    public function handle(TransactionUtil $transactionUtil)
    {
        try {
            $business = Business::find($this->businessId);
            
            if (!$business || !$business->shopify_api_settings) {
                Log::error('Shopify order sync: Business not configured', [
                    'business_id' => $this->businessId,
                ]);
                return;
            }

            $apiService = new ShopifyApiService($this->businessId);

            // Get default location
            $location = BusinessLocation::where('business_id', $this->businessId)->first();
            if (!$location) {
                Log::error('Shopify order sync: No business location found', [
                    'business_id' => $this->businessId,
                ]);
                return;
            }

            // Use cursor-based pagination with since_id (Shopify REST API doesn't support page parameter)
            $sinceId = null;
            $hasMore = true;
            $syncedCount = 0;
            $maxIterations = 1000; // Safety limit to prevent infinite loops
            $iterationCount = 0;

            Log::info('Shopify order sync started', [
                'business_id' => $this->businessId,
            ]);

            while ($hasMore && $iterationCount < $maxIterations) {
                $iterationCount++;
                
                $params = ['limit' => 250, 'status' => 'any'];
                if ($sinceId) {
                    $params['since_id'] = $sinceId;
                }
                
                Log::info('Shopify order sync: Fetching orders batch', [
                    'iteration' => $iterationCount,
                    'since_id' => $sinceId,
                ]);
                
                try {
                    $response = $apiService->getOrders($params);
                    $orders = $response['orders'] ?? [];

                    Log::info('Shopify order sync: Orders fetched', [
                        'count' => count($orders),
                    ]);

                    if (empty($orders)) {
                        Log::info('Shopify order sync: No more orders to sync');
                        $hasMore = false;
                        break;
                    }

                    // Track the last order ID for pagination (must be outside try/catch)
                    $lastOrderId = null;

                    foreach ($orders as $shopifyOrder) {
                        $lastOrderId = $shopifyOrder['id'] ?? null;
                        
                        DB::beginTransaction();
                        try {
                            $wasSynced = $this->syncOrder($shopifyOrder, $business, $location, $transactionUtil);
                            if ($wasSynced) {
                                $syncedCount++;
                                Log::debug('Shopify order sync: Order synced successfully', [
                                    'order_id' => $lastOrderId,
                                    'total_synced' => $syncedCount,
                                ]);
                            }
                            DB::commit();
                        } catch (\Exception $e) {
                            DB::rollBack();
                            Log::error('Shopify order sync: Failed to sync order', [
                                'order_id' => $lastOrderId,
                                'error' => $e->getMessage(),
                                'trace' => $e->getTraceAsString(),
                            ]);
                        }
                    }

                    // Update since_id to the last order ID from this batch (even if some failed)
                    // This ensures we don't get stuck in an infinite loop
                    if ($lastOrderId) {
                        $sinceId = $lastOrderId;
                        Log::info('Shopify order sync: Batch processed', [
                            'last_order_id' => $sinceId,
                            'orders_in_batch' => count($orders),
                            'successfully_synced_in_batch' => $syncedCount,
                        ]);
                    }

                    // If we got fewer orders than the limit, we've reached the end
                    if (count($orders) < 250) {
                        Log::info('Shopify order sync: Reached end of orders (less than 250)');
                        $hasMore = false;
                    }
                    
                } catch (\Exception $e) {
                    Log::error('Shopify order sync: Failed to fetch orders batch', [
                        'since_id' => $sinceId,
                        'iteration' => $iterationCount,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);
                    
                    // If it's a rate limit error, wait and retry
                    if (strpos($e->getMessage(), '429') !== false || strpos($e->getMessage(), 'rate limit') !== false) {
                        Log::warning('Shopify order sync: Rate limit hit, waiting 5 seconds');
                        sleep(5);
                        continue;
                    }
                    
                    // For other errors, break to avoid infinite loop
                    $hasMore = false;
                    break;
                }
            }

            if ($iterationCount >= $maxIterations) {
                Log::warning('Shopify order sync: Reached maximum iterations limit', [
                    'max_iterations' => $maxIterations,
                    'last_since_id' => $sinceId,
                ]);
            }

            Log::info('Shopify order sync completed', [
                'business_id' => $this->businessId,
                'synced_count' => $syncedCount,
            ]);

        } catch (\Exception $e) {
            Log::error('Shopify order sync failed', [
                'business_id' => $this->businessId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Sync a single order from Shopify
     */
    protected function syncOrder($shopifyOrder, $business, $location, $transactionUtil)
    {
        $shopifyOrderId = (string) ($shopifyOrder['id'] ?? '');
        $shopifyOrderNumber = (string) ($shopifyOrder['order_number'] ?? $shopifyOrder['name'] ?? '');
        
        // Check if order already exists by shopify_order_id
        $existingTransaction = Transaction::where('business_id', $business->id)
            ->where('shopify_order_id', $shopifyOrderId)
            ->first();

        if ($existingTransaction) {
            // Order already synced, skip to prevent duplicates
            Log::debug('Shopify order sync: Order already exists by shopify_order_id, skipping', [
                'shopify_order_id' => $shopifyOrderId,
                'transaction_id' => $existingTransaction->id,
            ]);
            return false; // Return false to indicate it was skipped
        }

        // Also check by invoice_no (Shopify order number) to catch duplicates even if shopify_order_id wasn't saved
        // Shopify order numbers are unique, so if we find a transaction with the same invoice_no, it's a duplicate
        if (!empty($shopifyOrderNumber)) {
            $existingByInvoiceNo = Transaction::where('business_id', $business->id)
                ->where('type', 'sell')
                ->where('invoice_no', $shopifyOrderNumber)
                ->first();

            if ($existingByInvoiceNo) {
                // Order already synced (possibly without shopify_order_id), skip to prevent duplicates
                // Also update the existing transaction with shopify_order_id if it's missing
                if (empty($existingByInvoiceNo->shopify_order_id)) {
                    $existingByInvoiceNo->shopify_order_id = $shopifyOrderId;
                    $existingByInvoiceNo->save();
                    Log::debug('Shopify order sync: Updated existing transaction with shopify_order_id', [
                        'transaction_id' => $existingByInvoiceNo->id,
                        'shopify_order_id' => $shopifyOrderId,
                        'invoice_no' => $shopifyOrderNumber,
                    ]);
                }
                
                Log::debug('Shopify order sync: Order already exists by invoice_no, skipping', [
                    'invoice_no' => $shopifyOrderNumber,
                    'transaction_id' => $existingByInvoiceNo->id,
                ]);
                return false; // Return false to indicate it was skipped
            }
        }

        // Get or create customer
        $customer = $this->getOrCreateCustomer($shopifyOrder, $business);

        // Determine status: 'final' if paid, otherwise use mapped status
        $financialStatus = $shopifyOrder['financial_status'] ?? 'pending';
        $status = ($financialStatus === 'paid') ? 'final' : $this->mapOrderStatus($financialStatus);

        // Prepare transaction data
        $transactionData = [
            'business_id' => $business->id,
            'location_id' => $location->id,
            'type' => 'sell',
            'status' => $status,
            'contact_id' => $customer->id,
            'invoice_no' => $shopifyOrderNumber,
            'transaction_date' => $shopifyOrder['created_at'],
            'final_total' => $shopifyOrder['total_price'] ?? 0,
            'total_before_tax' => $shopifyOrder['subtotal_price'] ?? 0,
            'tax_id' => null,
            'discount_type' => 'fixed',
            'discount_amount' => $shopifyOrder['total_discounts'] ?? 0,
            'shipping_charges' => $shopifyOrder['total_shipping_price_set']['shop_money']['amount'] ?? 0,
            'shopify_order_id' => $shopifyOrderId,
            'created_by' => $business->owner_id,
        ];

        // Create sell lines - with product sync if needed
        $sellLines = [];
        $apiService = new ShopifyApiService($business->id);
        $mappingService = new ProductMappingService();
        
        // Get default location and unit for product sync
        $location = BusinessLocation::where('business_id', $business->id)->first();
        $defaultUnit = Unit::where('business_id', $business->id)
            ->whereNull('base_unit_id')
            ->first();
        
        if (!$defaultUnit) {
            Log::error('Shopify order sync: No default unit found', [
                'business_id' => $business->id,
            ]);
            throw new \Exception('Default unit not configured for business');
        }

        foreach ($shopifyOrder['line_items'] ?? [] as $lineItem) {
            $shopifyProductId = $lineItem['product_id'] ?? null;
            $shopifyVariantId = $lineItem['variant_id'] ?? null;
            
            if (!$shopifyProductId) {
                // Product was deleted from Shopify, create placeholder product
                Log::warning('Shopify order sync: Line item missing product_id (deleted product), creating placeholder', [
                    'line_item' => $lineItem,
                    'order_id' => $shopifyOrder['id'] ?? null,
                ]);
                
                try {
                    $product = $this->createPlaceholderProductForDeletedItem($lineItem, $business, $location, $defaultUnit->id);
                    $variation = $this->createDefaultVariation($product, $shopifyVariantId, $lineItem, $location);
                    
                    if ($product && $variation) {
                        $sellLines[] = [
                            'product_id' => $product->id,
                            'variation_id' => $variation->id,
                            'quantity' => $lineItem['quantity'] ?? 1,
                            'unit_price' => $lineItem['price'] ?? 0,
                            'unit_price_inc_tax' => $lineItem['price'] ?? 0,
                            'item_tax' => 0,
                            'tax_id' => null,
                        ];
                        Log::info('Shopify order sync: Created placeholder product for deleted item', [
                            'product_id' => $product->id,
                            'product_name' => $product->name,
                        ]);
                    }
                } catch (\Exception $e) {
                    Log::error('Shopify order sync: Failed to create placeholder product for deleted item', [
                        'line_item' => $lineItem,
                        'error' => $e->getMessage(),
                    ]);
                }
                continue;
            }

            // Check if product exists
            $product = $this->findProductByShopifyId($shopifyProductId, $shopifyVariantId, $business->id);
            
            // If product doesn't exist, fetch and sync it from Shopify
            if (!$product) {
                Log::info('Shopify order sync: Product not found, syncing from Shopify', [
                    'product_id' => $shopifyProductId,
                    'variant_id' => $shopifyVariantId,
                    'order_id' => $shopifyOrder['id'] ?? null,
                ]);
                
                try {
                    // Fetch product from Shopify
                    $productResponse = $apiService->getProduct($shopifyProductId);
                    $shopifyProduct = $productResponse['product'] ?? null;
                    
                    if (!$shopifyProduct) {
                        Log::error('Shopify order sync: Failed to fetch product from Shopify', [
                            'product_id' => $shopifyProductId,
                        ]);
                        continue;
                    }
                    
                    // Sync the product (this will create/update product and variants)
                    $this->syncProductFromShopify($shopifyProduct, $business, $location, $mappingService, $defaultUnit->id);
                    
                    // Find the product again after sync
                    $product = $this->findProductByShopifyId($shopifyProductId, $shopifyVariantId, $business->id);
                    
                    if (!$product) {
                        Log::error('Shopify order sync: Product still not found after sync', [
                            'product_id' => $shopifyProductId,
                        ]);
                        continue;
                    }
                    
                    Log::info('Shopify order sync: Product synced successfully', [
                        'product_id' => $shopifyProductId,
                        'ultimatepos_product_id' => $product->id,
                    ]);
                    
                } catch (\Exception $e) {
                    Log::error('Shopify order sync: Failed to sync product', [
                        'product_id' => $shopifyProductId,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);
                    continue; // Skip this line item if product sync fails
                }
            }

            // Find the correct variation
            $variation = null;
            if ($shopifyVariantId) {
                $variation = $product->variations()
                    ->where('shopify_variant_id', $shopifyVariantId)
                    ->first();
            }
            
            // If variant not found, try to find or create it
            if (!$variation) {
                // Try to find any variation for this product
                $variation = $product->variations()->first();
                
                // If still no variation, create a default one
                if (!$variation) {
                    Log::warning('Shopify order sync: No variation found, creating default', [
                        'product_id' => $product->id,
                        'shopify_variant_id' => $shopifyVariantId,
                    ]);
                    
                    // Create a default variation
                    $variation = $this->createDefaultVariation($product, $shopifyVariantId, $lineItem, $location);
                }
            }

            if (!$variation) {
                Log::error('Shopify order sync: Failed to get or create variation', [
                    'product_id' => $product->id,
                    'shopify_variant_id' => $shopifyVariantId,
                ]);
                continue;
            }

            $sellLines[] = [
                'product_id' => $product->id,
                'variation_id' => $variation->id,
                'quantity' => $lineItem['quantity'] ?? 1,
                'unit_price' => $lineItem['price'] ?? 0,
                'unit_price_inc_tax' => $lineItem['price'] ?? 0,
                'item_tax' => 0,
                'tax_id' => null,
            ];
        }

        if (empty($sellLines)) {
            throw new \Exception('No valid line items found in order after product sync');
        }

        // Create transaction
        $invoiceTotal = [
            'total_before_tax' => $transactionData['total_before_tax'],
            'tax' => 0,
        ];

        $transaction = $transactionUtil->createSellTransaction(
            $business->id,
            $transactionData,
            $invoiceTotal,
            $business->owner_id,
            false
        );

        $transactionUtil->createOrUpdateSellLines(
            $transaction,
            $sellLines,
            $location->id,
            false,
            null,
            [],
            false
        );

        // Status is already set correctly above, but ensure it's saved if needed
        // (paid orders are already set to 'final' in transactionData)
        if ($transaction->status !== $status) {
            $transaction->status = $status;
            $transaction->save();
        }
        
        Log::debug('Shopify order sync: Order synced successfully', [
            'order_id' => $shopifyOrderId,
            'transaction_id' => $transaction->id,
            'status' => $transaction->status,
            'financial_status' => $financialStatus,
        ]);
        
        return true; // Return true to indicate it was successfully synced
    }

    /**
     * Get or create customer from Shopify order
     */
    protected function getOrCreateCustomer($shopifyOrder, $business)
    {
        $customerData = $shopifyOrder['customer'] ?? [];
        $email = $customerData['email'] ?? $shopifyOrder['email'] ?? null;
        
        // Build name from customer data or order data
        $firstName = $customerData['first_name'] ?? '';
        $lastName = $customerData['last_name'] ?? '';
        if ($firstName || $lastName) {
            $name = trim($firstName . ' ' . $lastName);
        } else {
            $name = $shopifyOrder['name'] ?? 'Shopify Customer';
        }
        
        // Get phone number from multiple possible locations
        $phone = $shopifyOrder['phone'] ?? $customerData['phone'] ?? $shopifyOrder['billing_address']['phone'] ?? $shopifyOrder['shipping_address']['phone'] ?? '';

        if ($email) {
            $customer = Contact::where('business_id', $business->id)
                ->where('email', $email)
                ->where('type', 'customer')
                ->first();
        } else {
            $customer = null;
        }

        // Extract address from Shopify order (prioritize shipping_address, fallback to billing_address)
        $addressData = $this->extractAddressFromOrder($shopifyOrder);

        if (!$customer) {
            $customer = Contact::create([
                'business_id' => $business->id,
                'type' => 'customer',
                'name' => $name,
                'email' => $email,
                'mobile' => $phone ?: '', // Empty string instead of null (mobile field is NOT NULL)
                'address_line_1' => $addressData['address_line_1'] ?? null,
                'address_line_2' => $addressData['address_line_2'] ?? null,
                'city' => $addressData['city'] ?? null,
                'state' => $addressData['state'] ?? null,
                'country' => $addressData['country'] ?? null,
                'zip_code' => $addressData['zip_code'] ?? null,
                'shipping_address' => $addressData['shipping_address'] ?? null,
                'created_by' => $business->owner_id,
            ]);
        } else {
            // Update existing customer with address if address fields are empty
            $needsUpdate = false;
            $updateData = [];
            
            if (empty($customer->address_line_1) && !empty($addressData['address_line_1'])) {
                $updateData['address_line_1'] = $addressData['address_line_1'];
                $needsUpdate = true;
            }
            if (empty($customer->address_line_2) && !empty($addressData['address_line_2'])) {
                $updateData['address_line_2'] = $addressData['address_line_2'];
                $needsUpdate = true;
            }
            if (empty($customer->city) && !empty($addressData['city'])) {
                $updateData['city'] = $addressData['city'];
                $needsUpdate = true;
            }
            if (empty($customer->state) && !empty($addressData['state'])) {
                $updateData['state'] = $addressData['state'];
                $needsUpdate = true;
            }
            if (empty($customer->country) && !empty($addressData['country'])) {
                $updateData['country'] = $addressData['country'];
                $needsUpdate = true;
            }
            if (empty($customer->zip_code) && !empty($addressData['zip_code'])) {
                $updateData['zip_code'] = $addressData['zip_code'];
                $needsUpdate = true;
            }
            if (empty($customer->shipping_address) && !empty($addressData['shipping_address'])) {
                $updateData['shipping_address'] = $addressData['shipping_address'];
                $needsUpdate = true;
            }
            
            if ($needsUpdate) {
                $customer->update($updateData);
                Log::debug('Shopify order sync: Updated customer address', [
                    'customer_id' => $customer->id,
                    'updated_fields' => array_keys($updateData),
                ]);
            }
        }

        return $customer;
    }

    /**
     * Extract address data from Shopify order
     */
    protected function extractAddressFromOrder($shopifyOrder)
    {
        // Prioritize shipping_address, fallback to billing_address
        $address = $shopifyOrder['shipping_address'] ?? $shopifyOrder['billing_address'] ?? [];
        
        $addressData = [
            'address_line_1' => $address['address1'] ?? $address['address_1'] ?? null,
            'address_line_2' => $address['address2'] ?? $address['address_2'] ?? null,
            'city' => $address['city'] ?? null,
            'state' => $address['province'] ?? $address['province_code'] ?? $address['state'] ?? null,
            'country' => $address['country'] ?? $address['country_code'] ?? null,
            'zip_code' => $address['zip'] ?? $address['postal_code'] ?? null,
        ];
        
        // Build full shipping address as text
        $shippingAddressParts = [];
        if (!empty($addressData['address_line_1'])) {
            $shippingAddressParts[] = $addressData['address_line_1'];
        }
        if (!empty($addressData['address_line_2'])) {
            $shippingAddressParts[] = $addressData['address_line_2'];
        }
        if (!empty($addressData['city'])) {
            $shippingAddressParts[] = $addressData['city'];
        }
        if (!empty($addressData['state'])) {
            $shippingAddressParts[] = $addressData['state'];
        }
        if (!empty($addressData['country'])) {
            $shippingAddressParts[] = $addressData['country'];
        }
        if (!empty($addressData['zip_code'])) {
            $shippingAddressParts[] = $addressData['zip_code'];
        }
        
        $addressData['shipping_address'] = !empty($shippingAddressParts) ? implode(', ', $shippingAddressParts) : null;
        
        return $addressData;
    }

    /**
     * Map Shopify order status to UltimatePOS status
     */
    protected function mapOrderStatus($shopifyStatus)
    {
        $statusMap = [
            'paid' => 'final',
            'pending' => 'draft',
            'authorized' => 'final',
            'partially_paid' => 'final',
            'refunded' => 'final',
            'voided' => 'final',
            'partially_refunded' => 'final',
        ];

        return $statusMap[$shopifyStatus] ?? 'draft';
    }

    /**
     * Find product by Shopify IDs
     */
    protected function findProductByShopifyId($productId, $variantId, $businessId)
    {
        if ($variantId) {
            $variation = Variation::where('shopify_variant_id', $variantId)
                ->whereHas('product', function($query) use ($businessId) {
                    $query->where('business_id', $businessId);
                })
                ->first();

            if ($variation) {
                return $variation->product;
            }
        }

        if ($productId) {
            return Product::where('business_id', $businessId)
                ->where('shopify_product_id', $productId)
                ->first();
        }

        return null;
    }

    /**
     * Sync a single product from Shopify (used when product is missing during order sync)
     */
    protected function syncProductFromShopify($shopifyProduct, $business, $location, $mappingService, $defaultUnitId)
    {
        // Check if product already exists
        $product = Product::where('business_id', $business->id)
            ->where('shopify_product_id', $shopifyProduct['id'])
            ->first();

        $productData = $mappingService->mapFromShopify($shopifyProduct, $business->id, $location->id);

        if ($product) {
            // Update existing product (but don't overwrite image if exists)
            if (!empty($productData['image']) && empty($product->image)) {
                $product->update($productData);
            } else {
                unset($productData['image']);
                $product->update($productData);
            }
            
            // Ensure existing product has a valid SKU
            if (empty(trim($product->sku)) || $product->sku === ' ' || $product->sku === '') {
                $product->sku = 'SHOPIFY-' . $shopifyProduct['id'];
                $product->save();
            }
        } else {
            // Create new product
            $productData['created_by'] = $business->owner_id;
            $productData['unit_id'] = $defaultUnitId;
            $productData['type'] = count($shopifyProduct['variants'] ?? []) > 1 ? 'variable' : 'single';
            $productData['enable_stock'] = isset($shopifyProduct['variants'][0]['inventory_management']) ? 1 : 0;
            $productData['not_for_selling'] = 0;
            $productData['tax_type'] = 'exclusive';
            $productData['barcode_type'] = 'C128';
            $productData['alert_quantity'] = 0;

            // Ensure SKU is set
            if (empty($productData['sku']) || trim($productData['sku']) === '') {
                $productData['sku'] = 'SHOPIFY-' . $shopifyProduct['id'];
            }

            $product = Product::create($productData);
            
            // Generate SKU if still empty
            if (empty(trim($product->sku)) || $product->sku === ' ' || $product->sku === '') {
                $productUtil = new ProductUtil();
                $sku = $productUtil->generateProductSku($product->id);
                $product->sku = $sku;
                $product->save();
            }
        }

        // Sync variants
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
                    $optionName = $shopifyProduct['options'][0]['name'] ?? 'Default';
                    $optionValue = $shopifyVariant['option1'] ?? 'Default';
                    
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
                } else {
                    // Use the DUMMY product_variation for single products
                    if (!isset($productVariation)) {
                        $productVariation = \App\ProductVariation::where('product_id', $product->id)
                            ->where('name', 'DUMMY')
                            ->first();
                    }
                }
                
                // Add product_variation_id to variation data
                if (isset($productVariation)) {
                    $variationData['product_variation_id'] = $productVariation->id;
                    $variation = $productVariation->variations()->create($variationData);
                } else {
                    // Fallback: create variation directly
                    $variation = Variation::create($variationData);
                }
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
        
        return $product;
    }

    /**
     * Create a default variation when none exists
     */
    protected function createDefaultVariation($product, $shopifyVariantId, $lineItem, $location)
    {
        try {
            // Get or create DUMMY product_variation
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

            // Create variation
            $variationData = [
                'product_id' => $product->id,
                'product_variation_id' => $productVariation->id,
                'name' => 'Default',
                'sub_sku' => $lineItem['sku'] ?? $product->sku,
                'default_sell_price' => (float) ($lineItem['price'] ?? 0),
                'shopify_variant_id' => $shopifyVariantId ? (string) $shopifyVariantId : null,
            ];

            $variation = $productVariation->variations()->create($variationData);
            
            Log::info('Shopify order sync: Created default variation', [
                'product_id' => $product->id,
                'variation_id' => $variation->id,
            ]);
            
            return $variation;
            
        } catch (\Exception $e) {
            Log::error('Shopify order sync: Failed to create default variation', [
                'product_id' => $product->id,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Create a placeholder product for deleted Shopify products
     */
    protected function createPlaceholderProductForDeletedItem($lineItem, $business, $location, $defaultUnitId)
    {
        try {
            $productName = $lineItem['title'] ?? $lineItem['name'] ?? 'Deleted Product';
            $productSku = $lineItem['sku'] ?? 'DELETED-' . ($lineItem['id'] ?? uniqid());
            
            // Check if placeholder product already exists (by SKU or name)
            $existingProduct = Product::where('business_id', $business->id)
                ->where(function($query) use ($productSku, $productName) {
                    $query->where('sku', $productSku)
                          ->orWhere('name', $productName);
                })
                ->first();
            
            if ($existingProduct) {
                return $existingProduct;
            }
            
            // Create placeholder product
            $productData = [
                'name' => $productName . ' (Deleted from Shopify)',
                'business_id' => $business->id,
                'type' => 'single',
                'unit_id' => $defaultUnitId,
                'sku' => $productSku,
                'barcode_type' => 'C128',
                'tax_type' => 'exclusive',
                'enable_stock' => 0,
                'not_for_selling' => 0,
                'created_by' => $business->owner_id,
            ];
            
            $product = Product::create($productData);
            
            // Assign to location
            $product->product_locations()->sync([$location->id]);
            
            Log::info('Shopify order sync: Created placeholder product for deleted item', [
                'product_id' => $product->id,
                'product_name' => $product->name,
                'sku' => $product->sku,
            ]);
            
            return $product;
            
        } catch (\Exception $e) {
            Log::error('Shopify order sync: Failed to create placeholder product', [
                'line_item' => $lineItem,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }
}

