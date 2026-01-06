<?php

namespace Modules\Shopify\Console\Commands;

use Illuminate\Console\Command;
use Modules\Shopify\Jobs\SyncShopifyProducts;
use Modules\Shopify\Jobs\SyncShopifyOrders;
use App\Business;
use Illuminate\Support\Facades\Log;

class SyncShopifyData extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'shopify:sync {--type=all : Type of sync (all, products, orders)} {--business-id= : Specific business ID}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync data with Shopify stores';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $type = $this->option('type');
        $businessId = $this->option('business-id');

        if ($businessId) {
            // Sync specific business
            $this->syncBusiness($businessId, $type);
        } else {
            // Sync all connected businesses
            $businesses = Business::whereNotNull('shopify_api_settings')->get();

            if ($businesses->isEmpty()) {
                $this->info('No businesses with Shopify connection found.');
                return 0;
            }

            $this->info("Found {$businesses->count()} business(es) with Shopify connection.");

            foreach ($businesses as $business) {
                $this->syncBusiness($business->id, $type);
            }
        }

        $this->info('Sync completed.');
        return 0;
    }

    /**
     * Sync a specific business
     */
    protected function syncBusiness($businessId, $type)
    {
        $business = Business::find($businessId);

        if (!$business || !$business->shopify_api_settings) {
            $this->warn("Business {$businessId} does not have Shopify connection configured.");
            return;
        }

        $this->info("Syncing business: {$business->name} (ID: {$businessId})");

        try {
            if ($type === 'all' || $type === 'products') {
                $this->info('  - Syncing products...');
                SyncShopifyProducts::dispatch($businessId);
            }

            if ($type === 'all' || $type === 'orders') {
                $this->info('  - Syncing orders...');
                SyncShopifyOrders::dispatch($businessId);
            }

            $this->info("  ✓ Sync jobs dispatched for business {$businessId}");

        } catch (\Exception $e) {
            $this->error("  ✗ Failed to sync business {$businessId}: " . $e->getMessage());
            Log::error('Shopify scheduled sync failed', [
                'business_id' => $businessId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}

