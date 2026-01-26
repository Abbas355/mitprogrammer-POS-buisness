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

class ProcessShopifyWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $businessId;
    protected $topic;
    protected $payload;
    protected $shopDomain;

    /**
     * Create a new job instance.
     */
    public function __construct($businessId, $topic, $payload, $shopDomain)
    {
        $this->businessId = $businessId;
        $this->topic = $topic;
        $this->payload = $payload;
        $this->shopDomain = $shopDomain;
    }

    /**
     * Execute the job.
     */
    public function handle(TransactionUtil $transactionUtil)
    {
        try {
            $data = json_decode($this->payload, true);

            Log::info('Processing Shopify webhook', [
                'business_id' => $this->businessId,
                'topic' => $this->topic,
            ]);

            switch ($this->topic) {
                case 'orders/create':
                case 'orders/updated':
                    $this->handleOrderWebhook($data, $transactionUtil);
                    break;

                case 'orders/paid':
                    $this->handleOrderPaidWebhook($data);
                    break;

                case 'orders/cancelled':
                    $this->handleOrderCancelledWebhook($data);
                    break;

                case 'inventory_levels/update':
                    $this->handleInventoryUpdateWebhook($data);
                    break;

                case 'products/create':
                case 'products/update':
                    $this->handleProductWebhook($data);
                    break;

                default:
                    Log::info('Shopify webhook: Unhandled topic', [
                        'topic' => $this->topic,
                    ]);
            }

        } catch (\Exception $e) {
            Log::error('Shopify webhook processing failed', [
                'business_id' => $this->businessId,
                'topic' => $this->topic,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    /**
     * Handle order create/update webhook
     */
    protected function handleOrderWebhook($data, $transactionUtil)
    {
        $order = $data['order'] ?? $data;
        
        if (empty($order['id'])) {
            return;
        }

        $business = Business::find($this->businessId);
        $location = BusinessLocation::where('business_id', $this->businessId)->first();

        if (!$business || !$location) {
            return;
        }

        // Check if order already exists
        $transaction = Transaction::where('business_id', $this->businessId)
            ->where('shopify_order_id', $order['id'])
            ->first();

        if ($transaction) {
            // Update existing transaction
            $this->updateTransactionFromOrder($transaction, $order);
        } else {
            // Create new transaction (similar to SyncShopifyOrders)
            $this->createTransactionFromOrder($order, $business, $location, $transactionUtil);
        }
    }

    /**
     * Handle order paid webhook
     */
    protected function handleOrderPaidWebhook($data)
    {
        $order = $data['order'] ?? $data;
        
        $transaction = Transaction::where('business_id', $this->businessId)
            ->where('shopify_order_id', $order['id'])
            ->first();

        if ($transaction) {
            $transaction->status = 'final';
            $transaction->save();
        }
    }

    /**
     * Handle order cancelled webhook
     */
    protected function handleOrderCancelledWebhook($data)
    {
        $order = $data['order'] ?? $data;
        
        $transaction = Transaction::where('business_id', $this->businessId)
            ->where('shopify_order_id', $order['id'])
            ->first();

        if ($transaction) {
            $transaction->status = 'cancelled';
            $transaction->save();
        }
    }

    /**
     * Handle inventory update webhook
     */
    protected function handleInventoryUpdateWebhook($data)
    {
        $inventoryLevel = $data['inventory_level'] ?? $data;
        $variantId = $inventoryLevel['variant_id'] ?? null;
        $quantity = $inventoryLevel['available'] ?? 0;

        if (!$variantId) {
            return;
        }

        $variation = Variation::where('shopify_variant_id', $variantId)
            ->whereHas('product', function($query) {
                $query->where('business_id', $this->businessId);
            })
            ->first();

        if ($variation) {
            $location = BusinessLocation::where('business_id', $this->businessId)->first();
            if ($location) {
                $mappingService = new ProductMappingService();
                $mappingService->updateInventoryFromShopify(
                    ['inventory_quantity' => $quantity],
                    $variation->id,
                    $location->id
                );
            }
        }
    }

    /**
     * Handle product create/update webhook
     */
    protected function handleProductWebhook($data)
    {
        $product = $data['product'] ?? $data;
        
        if (empty($product['id'])) {
            return;
        }

        // Dispatch product sync job
        SyncShopifyProducts::dispatch($this->businessId);
    }

    /**
     * Create transaction from order (similar to SyncShopifyOrders)
     */
    protected function createTransactionFromOrder($order, $business, $location, $transactionUtil)
    {
        $shopifyOrderId = (string) ($order['id'] ?? '');
        $shopifyOrderNumber = (string) ($order['order_number'] ?? $order['name'] ?? '');
        
        // Check if order already exists by shopify_order_id
        $existingTransaction = Transaction::where('business_id', $business->id)
            ->where('shopify_order_id', $shopifyOrderId)
            ->first();

        if ($existingTransaction) {
            // Order already synced, skip to prevent duplicates
            Log::debug('Shopify webhook: Order already exists by shopify_order_id, skipping', [
                'shopify_order_id' => $shopifyOrderId,
                'transaction_id' => $existingTransaction->id,
            ]);
            return;
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
                    Log::debug('Shopify webhook: Updated existing transaction with shopify_order_id', [
                        'transaction_id' => $existingByInvoiceNo->id,
                        'shopify_order_id' => $shopifyOrderId,
                        'invoice_no' => $shopifyOrderNumber,
                    ]);
                }
                
                Log::debug('Shopify webhook: Order already exists by invoice_no, skipping', [
                    'invoice_no' => $shopifyOrderNumber,
                    'transaction_id' => $existingByInvoiceNo->id,
                ]);
                return;
            }
        }

        // Get or create customer
        $customer = $this->getOrCreateCustomer($order, $business);

        // Determine status: 'final' if paid, otherwise use mapped status
        $financialStatus = $order['financial_status'] ?? 'pending';
        $status = ($financialStatus === 'paid') ? 'final' : $this->mapOrderStatus($financialStatus);

        // Prepare transaction data
        $transactionData = [
            'business_id' => $business->id,
            'location_id' => $location->id,
            'type' => 'sell',
            'status' => $status,
            'contact_id' => $customer->id,
            'invoice_no' => $shopifyOrderNumber,
            'transaction_date' => $order['created_at'],
            'final_total' => $order['total_price'] ?? 0,
            'total_before_tax' => $order['subtotal_price'] ?? 0,
            'tax_id' => null,
            'discount_type' => 'fixed',
            'discount_amount' => $order['total_discounts'] ?? 0,
            'shipping_charges' => $order['total_shipping_price_set']['shop_money']['amount'] ?? 0,
            'shopify_order_id' => $shopifyOrderId,
            'created_by' => $business->owner_id,
        ];

        // Create sell lines - with product sync if needed
        $sellLines = [];
        $apiService = new ShopifyApiService($business->id);
        $mappingService = new ProductMappingService();
        
        // Get default unit for product sync
        $defaultUnit = Unit::where('business_id', $business->id)
            ->whereNull('base_unit_id')
            ->first();
        
        if (!$defaultUnit) {
            Log::error('Shopify webhook: No default unit found', [
                'business_id' => $business->id,
            ]);
            throw new \Exception('Default unit not configured for business');
        }

        foreach ($order['line_items'] ?? [] as $lineItem) {
            $shopifyProductId = $lineItem['product_id'] ?? null;
            $shopifyVariantId = $lineItem['variant_id'] ?? null;
            
            if (!$shopifyProductId) {
                // Product was deleted from Shopify, create placeholder product
                Log::warning('Shopify webhook: Line item missing product_id (deleted product), creating placeholder', [
                    'line_item' => $lineItem,
                    'order_id' => $order['id'] ?? null,
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
                        Log::info('Shopify webhook: Created placeholder product for deleted item', [
                            'product_id' => $product->id,
                            'product_name' => $product->name,
                        ]);
                    }
                } catch (\Exception $e) {
                    Log::error('Shopify webhook: Failed to create placeholder product for deleted item', [
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
                Log::info('Shopify webhook: Product not found, syncing from Shopify', [
                    'product_id' => $shopifyProductId,
                    'variant_id' => $shopifyVariantId,
                    'order_id' => $order['id'] ?? null,
                ]);
                
                try {
                    // Fetch product from Shopify
                    $productResponse = $apiService->getProduct($shopifyProductId);
                    $shopifyProduct = $productResponse['product'] ?? null;
                    
                    if (!$shopifyProduct) {
                        Log::error('Shopify webhook: Failed to fetch product from Shopify', [
                            'product_id' => $shopifyProductId,
                        ]);
                        continue;
                    }
                    
                    // Sync the product
                    $this->syncProductFromShopify($shopifyProduct, $business, $location, $mappingService, $defaultUnit->id);
                    
                    // Find the product again after sync
                    $product = $this->findProductByShopifyId($shopifyProductId, $shopifyVariantId, $business->id);
                    
                    if (!$product) {
                        Log::error('Shopify webhook: Product still not found after sync', [
                            'product_id' => $shopifyProductId,
                        ]);
                        continue;
                    }
                    
                } catch (\Exception $e) {
                    Log::error('Shopify webhook: Failed to sync product', [
                        'product_id' => $shopifyProductId,
                        'error' => $e->getMessage(),
                    ]);
                    continue;
                }
            }

            // Find the correct variation
            $variation = null;
            if ($shopifyVariantId) {
                $variation = $product->variations()
                    ->where('shopify_variant_id', $shopifyVariantId)
                    ->first();
            }
            
            if (!$variation) {
                $variation = $product->variations()->first();
                
                if (!$variation) {
                    $variation = $this->createDefaultVariation($product, $shopifyVariantId, $lineItem, $location);
                }
            }

            if (!$variation) {
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
            throw new \Exception('No valid line items found in order');
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
        
        Log::debug('Shopify webhook: Order synced successfully', [
            'order_id' => $shopifyOrderId,
            'transaction_id' => $transaction->id,
            'status' => $transaction->status,
            'financial_status' => $financialStatus,
        ]);
    }

    /**
     * Get or create customer from Shopify order
     */
    protected function getOrCreateCustomer($order, $business)
    {
        $customerData = $order['customer'] ?? [];
        $email = $customerData['email'] ?? $order['email'] ?? null;
        
        // Build name from customer data or order data
        $firstName = $customerData['first_name'] ?? '';
        $lastName = $customerData['last_name'] ?? '';
        if ($firstName || $lastName) {
            $name = trim($firstName . ' ' . $lastName);
        } else {
            $name = $order['name'] ?? 'Shopify Customer';
        }
        
        // Get phone number from multiple possible locations
        $phone = $order['phone'] ?? $customerData['phone'] ?? $order['billing_address']['phone'] ?? $order['shipping_address']['phone'] ?? '';

        if ($email) {
            $customer = Contact::where('business_id', $business->id)
                ->where('email', $email)
                ->where('type', 'customer')
                ->first();
        } else {
            $customer = null;
        }

        // Extract address from Shopify order (prioritize shipping_address, fallback to billing_address)
        $addressData = $this->extractAddressFromOrder($order);

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
                Log::debug('Shopify webhook: Updated customer address', [
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
    protected function extractAddressFromOrder($order)
    {
        // Prioritize shipping_address, fallback to billing_address
        $address = $order['shipping_address'] ?? $order['billing_address'] ?? [];
        
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
     * Sync a single product from Shopify
     */
    protected function syncProductFromShopify($shopifyProduct, $business, $location, $mappingService, $defaultUnitId)
    {
        // Check if product already exists
        $product = Product::where('business_id', $business->id)
            ->where('shopify_product_id', $shopifyProduct['id'])
            ->first();

        $productData = $mappingService->mapFromShopify($shopifyProduct, $business->id, $location->id);

        if ($product) {
            if (!empty($productData['image']) && empty($product->image)) {
                $product->update($productData);
            } else {
                unset($productData['image']);
                $product->update($productData);
            }
            
            if (empty(trim($product->sku)) || $product->sku === ' ' || $product->sku === '') {
                $product->sku = 'SHOPIFY-' . $shopifyProduct['id'];
                $product->save();
            }
        } else {
            $productData['created_by'] = $business->owner_id;
            $productData['unit_id'] = $defaultUnitId;
            $productData['type'] = count($shopifyProduct['variants'] ?? []) > 1 ? 'variable' : 'single';
            $productData['enable_stock'] = isset($shopifyProduct['variants'][0]['inventory_management']) ? 1 : 0;
            $productData['not_for_selling'] = 0;
            $productData['tax_type'] = 'exclusive';
            $productData['barcode_type'] = 'C128';
            $productData['alert_quantity'] = 0;

            if (empty($productData['sku']) || trim($productData['sku']) === '') {
                $productData['sku'] = 'SHOPIFY-' . $shopifyProduct['id'];
            }

            $product = Product::create($productData);
            
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
                if (!$isSingleProduct && isset($shopifyProduct['options']) && !empty($shopifyProduct['options'])) {
                    $optionName = $shopifyProduct['options'][0]['name'] ?? 'Default';
                    
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
                    if (!isset($productVariation)) {
                        $productVariation = \App\ProductVariation::where('product_id', $product->id)
                            ->where('name', 'DUMMY')
                            ->first();
                    }
                }
                
                if (isset($productVariation)) {
                    $variationData['product_variation_id'] = $productVariation->id;
                    $variation = $productVariation->variations()->create($variationData);
                } else {
                    $variation = Variation::create($variationData);
                }
            }

            if ($product->enable_stock) {
                $mappingService->updateInventoryFromShopify(
                    $shopifyVariant,
                    $variation->id,
                    $location->id
                );
            }
        }

        if ($product->product_locations->isEmpty()) {
            $product->product_locations()->sync([$location->id]);
        }

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

            $variationData = [
                'product_id' => $product->id,
                'product_variation_id' => $productVariation->id,
                'name' => 'Default',
                'sub_sku' => $lineItem['sku'] ?? $product->sku,
                'default_sell_price' => (float) ($lineItem['price'] ?? 0),
                'shopify_variant_id' => $shopifyVariantId ? (string) $shopifyVariantId : null,
            ];

            $variation = $productVariation->variations()->create($variationData);
            
            return $variation;
            
        } catch (\Exception $e) {
            Log::error('Shopify webhook: Failed to create default variation', [
                'product_id' => $product->id,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Update transaction from order
     */
    protected function updateTransactionFromOrder($transaction, $order)
    {
        $transaction->final_total = $order['total_price'] ?? $transaction->final_total;
        $transaction->status = $this->mapOrderStatus($order['financial_status'] ?? 'pending');
        $transaction->save();
    }

    /**
     * Map Shopify order status
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
            
            Log::info('Shopify webhook: Created placeholder product for deleted item', [
                'product_id' => $product->id,
                'product_name' => $product->name,
                'sku' => $product->sku,
            ]);
            
            return $product;
            
        } catch (\Exception $e) {
            Log::error('Shopify webhook: Failed to create placeholder product', [
                'line_item' => $lineItem,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }
}

