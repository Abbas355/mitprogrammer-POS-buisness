<?php

namespace Modules\Shopify\Http\Controllers;

use App\Http\Controllers\Controller;
use Modules\Shopify\Services\ShopifyApiService;
use Modules\Shopify\Jobs\SyncShopifyProducts;
use Modules\Shopify\Jobs\SyncShopifyOrders;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Business;
use App\Transaction;
use Illuminate\Support\Facades\DB;

class ShopifySyncController extends Controller
{
    /**
     * Trigger product sync
     */
    public function syncProducts(Request $request)
    {
        try {
            $businessId = request()->session()->get('user.business_id');
            
            // Dispatch sync job
            SyncShopifyProducts::dispatch($businessId);

            return response()->json([
                'success' => true,
                'msg' => 'Product sync started',
            ]);

        } catch (\Exception $e) {
            Log::error('Shopify product sync failed', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'msg' => 'Failed to start sync: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Trigger order sync
     */
    public function syncOrders(Request $request)
    {
        try {
            $businessId = request()->session()->get('user.business_id');
            
            // Dispatch sync job
            SyncShopifyOrders::dispatch($businessId);

            return response()->json([
                'success' => true,
                'msg' => 'Order sync started',
            ]);

        } catch (\Exception $e) {
            Log::error('Shopify order sync failed', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'msg' => 'Failed to start sync: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Sync single product
     */
    public function syncProduct($productId, Request $request)
    {
        try {
            $businessId = request()->session()->get('user.business_id');
            
            // Dispatch single product sync job
            \Modules\Shopify\Jobs\ExportProductToShopify::dispatch($businessId, $productId);

            return response()->json([
                'success' => true,
                'msg' => 'Product sync started',
            ]);

        } catch (\Exception $e) {
            Log::error('Shopify single product sync failed', [
                'product_id' => $productId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'msg' => 'Failed to sync product: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get sync status
     */
    public function syncStatus(Request $request)
    {
        try {
            $businessId = request()->session()->get('user.business_id');
            $business = Business::find($businessId);

            $status = [
                'connected' => false,
                'last_sync_at' => null,
                'sync_in_progress' => false,
            ];

            if ($business && $business->shopify_api_settings) {
                $settings = is_string($business->shopify_api_settings)
                    ? json_decode($business->shopify_api_settings, true)
                    : $business->shopify_api_settings;

                $status['connected'] = !empty($settings['shop_domain']);
                $status['last_sync_at'] = $settings['last_sync_at'] ?? null;
                $status['sync_enabled'] = $settings['sync_enabled'] ?? false;
            }

            return response()->json([
                'success' => true,
                'status' => $status,
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to get sync status', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'msg' => 'Failed to get status: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Clean up duplicate Shopify orders
     * Method 1: Keeps the oldest transaction for each shopify_order_id and deletes the rest
     * Method 2: For Shopify orders (numeric invoice_no), matches by invoice_no only since Shopify order numbers are unique
     * Also backfills shopify_order_id for kept transactions if missing
     */
    public function cleanupDuplicates(Request $request)
    {
        try {
            $businessId = request()->session()->get('user.business_id');
            $deletedCount = 0;
            $duplicateGroups = 0;
            
            // Method 1: Find duplicates by shopify_order_id (for orders synced with shopify_order_id)
            $duplicatesByShopifyId = Transaction::where('business_id', $businessId)
                ->whereNotNull('shopify_order_id')
                ->where('shopify_order_id', '!=', '')
                ->select('shopify_order_id', DB::raw('COUNT(*) as count'), DB::raw('MIN(id) as keep_id'))
                ->groupBy('shopify_order_id')
                ->having('count', '>', 1)
                ->get();

            Log::info('Shopify cleanup: Checking duplicates by shopify_order_id', [
                'found_groups' => $duplicatesByShopifyId->count(),
            ]);

            foreach ($duplicatesByShopifyId as $duplicate) {
                $duplicateGroups++;
                $totalDuplicates = ($duplicate->count - 1);
                
                // Delete all transactions with this shopify_order_id except the oldest one
                $deleted = Transaction::where('business_id', $businessId)
                    ->where('shopify_order_id', $duplicate->shopify_order_id)
                    ->where('id', '!=', $duplicate->keep_id)
                    ->delete();
                
                $deletedCount += $deleted;
                
                Log::info('Shopify cleanup: Removed duplicate orders by shopify_order_id', [
                    'shopify_order_id' => $duplicate->shopify_order_id,
                    'kept_transaction_id' => $duplicate->keep_id,
                    'deleted_count' => $deleted,
                ]);
            }

            // Method 2: For Shopify orders, invoice_no (order_number) should be unique
            // Shopify order numbers are numeric (like "1569", "1568") and unique
            // If we see multiple transactions with the same numeric invoice_no, they're duplicates
            $allTransactions = Transaction::where('business_id', $businessId)
                ->where('type', 'sell')
                ->whereNotNull('invoice_no')
                ->where('invoice_no', '!=', '')
                ->orderBy('invoice_no')
                ->orderBy('id')
                ->get(['id', 'invoice_no', 'transaction_date', 'contact_id', 'final_total', 'shopify_order_id']);

            Log::info('Shopify cleanup: Checking all transactions for duplicates by invoice_no', [
                'total_transactions' => $allTransactions->count(),
            ]);

            // Group by invoice_no only (for Shopify orders, invoice_no is the unique identifier)
            $groupedByInvoice = [];
            foreach ($allTransactions as $transaction) {
                $invoiceNo = trim($transaction->invoice_no);
                
                // Check if this looks like a Shopify order number
                // Shopify order numbers are numeric (3+ digits, like "1569", "1568")
                // Regular UltimatePOS invoice numbers usually have prefixes like "INV-", "SO-", etc.
                $isShopifyOrderNumber = is_numeric($invoiceNo) && strlen($invoiceNo) >= 3;
                
                if ($isShopifyOrderNumber) {
                    if (!isset($groupedByInvoice[$invoiceNo])) {
                        $groupedByInvoice[$invoiceNo] = [];
                    }
                    $groupedByInvoice[$invoiceNo][] = $transaction;
                }
            }

            // Find and remove duplicates
            foreach ($groupedByInvoice as $invoiceNo => $transactions) {
                if (count($transactions) <= 1) {
                    continue;
                }

                $duplicateGroups++;
                
                // Keep the oldest transaction (lowest ID)
                usort($transactions, function($a, $b) {
                    return $a->id <=> $b->id;
                });
                
                $keepId = $transactions[0]->id;
                $keptTransaction = $transactions[0];
                $toDelete = array_slice($transactions, 1);
                
                // If the kept transaction doesn't have shopify_order_id but others do, copy it
                // This ensures the badge will show for the kept transaction
                if (empty($keptTransaction->shopify_order_id)) {
                    foreach ($transactions as $t) {
                        if (!empty($t->shopify_order_id)) {
                            Transaction::where('id', $keepId)
                                ->update(['shopify_order_id' => $t->shopify_order_id]);
                            Log::info('Shopify cleanup: Backfilled shopify_order_id for kept transaction', [
                                'transaction_id' => $keepId,
                                'shopify_order_id' => $t->shopify_order_id,
                            ]);
                            break;
                        }
                    }
                }
                
                $deleteIds = array_map(function($t) {
                    return $t->id;
                }, $toDelete);
                
                $deleted = Transaction::whereIn('id', $deleteIds)->delete();
                $deletedCount += $deleted;
                
                Log::info('Shopify cleanup: Removed duplicate orders by invoice_no', [
                    'invoice_no' => $invoiceNo,
                    'kept_transaction_id' => $keepId,
                    'deleted_count' => $deleted,
                    'total_in_group' => count($transactions),
                ]);
            }

            $message = "Cleanup completed. ";
            if ($duplicateGroups > 0) {
                $message .= "Found {$duplicateGroups} duplicate order group(s), deleted {$deletedCount} duplicate transaction(s).";
            } else {
                $message .= "No duplicate orders found.";
            }

            return response()->json([
                'success' => true,
                'msg' => $message,
                'duplicate_groups' => $duplicateGroups,
                'deleted_count' => $deletedCount,
            ]);

        } catch (\Exception $e) {
            Log::error('Shopify duplicate cleanup failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'msg' => 'Failed to cleanup duplicates: ' . $e->getMessage(),
            ], 500);
        }
    }
}

