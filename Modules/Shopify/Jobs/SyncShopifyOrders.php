<?php

namespace Modules\Shopify\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Shopify\Services\ShopifyApiService;
use App\Transaction;
use App\Contact;
use App\Product;
use App\Variation;
use App\Business;
use App\BusinessLocation;
use App\Utils\TransactionUtil;
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

            $page = 1;
            $hasMore = true;
            $syncedCount = 0;

            while ($hasMore) {
                $response = $apiService->getOrders(['page' => $page, 'limit' => 250]);
                $orders = $response['orders'] ?? [];

                if (empty($orders)) {
                    $hasMore = false;
                    break;
                }

                foreach ($orders as $shopifyOrder) {
                    DB::beginTransaction();
                    try {
                        $this->syncOrder($shopifyOrder, $business, $location, $transactionUtil);
                        $syncedCount++;
                        DB::commit();
                    } catch (\Exception $e) {
                        DB::rollBack();
                        Log::error('Shopify order sync: Failed to sync order', [
                            'order_id' => $shopifyOrder['id'] ?? null,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }

                if (count($orders) < 250) {
                    $hasMore = false;
                } else {
                    $page++;
                }
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
        // Check if order already exists
        $transaction = Transaction::where('business_id', $business->id)
            ->where('shopify_order_id', $shopifyOrder['id'])
            ->first();

        if ($transaction) {
            // Order already synced, skip
            return;
        }

        // Get or create customer
        $customer = $this->getOrCreateCustomer($shopifyOrder, $business);

        // Prepare transaction data
        $transactionData = [
            'business_id' => $business->id,
            'location_id' => $location->id,
            'type' => 'sell',
            'status' => $this->mapOrderStatus($shopifyOrder['financial_status'] ?? 'pending'),
            'contact_id' => $customer->id,
            'invoice_no' => $shopifyOrder['order_number'] ?? $shopifyOrder['name'],
            'transaction_date' => $shopifyOrder['created_at'],
            'final_total' => $shopifyOrder['total_price'] ?? 0,
            'total_before_tax' => $shopifyOrder['subtotal_price'] ?? 0,
            'tax_id' => null,
            'discount_type' => 'fixed',
            'discount_amount' => $shopifyOrder['total_discounts'] ?? 0,
            'shipping_charges' => $shopifyOrder['total_shipping_price_set']['shop_money']['amount'] ?? 0,
            'shopify_order_id' => (string) $shopifyOrder['id'],
            'created_by' => $business->owner_id,
        ];

        // Create sell lines
        $sellLines = [];
        foreach ($shopifyOrder['line_items'] ?? [] as $lineItem) {
            $product = $this->findProductByShopifyId($lineItem['product_id'] ?? null, $lineItem['variant_id'] ?? null, $business->id);
            
            if (!$product) {
                Log::warning('Shopify order sync: Product not found', [
                    'product_id' => $lineItem['product_id'] ?? null,
                    'variant_id' => $lineItem['variant_id'] ?? null,
                ]);
                continue;
            }

            $variation = $product->variations()
                ->where('shopify_variant_id', $lineItem['variant_id'])
                ->first();

            if (!$variation) {
                $variation = $product->variations()->first();
            }

            if (!$variation) {
                continue;
            }

            $sellLines[] = [
                'product_id' => $product->id,
                'variation_id' => $variation->id,
                'quantity' => $lineItem['quantity'],
                'unit_price' => $lineItem['price'],
                'unit_price_inc_tax' => $lineItem['price'],
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

        // Mark as final if paid
        if (($shopifyOrder['financial_status'] ?? '') === 'paid') {
            $transaction->status = 'final';
            $transaction->save();
        }
    }

    /**
     * Get or create customer from Shopify order
     */
    protected function getOrCreateCustomer($shopifyOrder, $business)
    {
        $customerData = $shopifyOrder['customer'] ?? [];
        $email = $customerData['email'] ?? $shopifyOrder['email'] ?? null;
        $name = $customerData['first_name'] . ' ' . $customerData['last_name'] ?? $shopifyOrder['name'] ?? 'Shopify Customer';

        if ($email) {
            $customer = Contact::where('business_id', $business->id)
                ->where('email', $email)
                ->where('type', 'customer')
                ->first();
        } else {
            $customer = null;
        }

        if (!$customer) {
            $customer = Contact::create([
                'business_id' => $business->id,
                'type' => 'customer',
                'name' => $name,
                'email' => $email,
                'mobile' => $shopifyOrder['phone'] ?? null,
                'created_by' => $business->owner_id,
            ]);
        }

        return $customer;
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
}

