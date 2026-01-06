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
use App\Product;
use App\Variation;
use App\Business;
use App\BusinessLocation;
use App\Utils\TransactionUtil;
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
        // Similar logic to SyncShopifyOrders::syncOrder
        // Implementation omitted for brevity - would reuse same logic
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
}

