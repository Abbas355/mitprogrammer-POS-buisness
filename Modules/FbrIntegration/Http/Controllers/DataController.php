<?php

namespace Modules\FbrIntegration\Http\Controllers;

use App\Business;
use App\Transaction;
use App\Utils\ModuleUtil;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\FbrIntegration\Services\FbrApiService;

class DataController
{
    protected $moduleUtil;

    public function __construct(ModuleUtil $moduleUtil = null)
    {
        $this->moduleUtil = $moduleUtil ?? app(ModuleUtil::class);
    }

    /**
     * Hook into after_sales event
     * This is called when a sale transaction is completed
     *
     * @param array $arguments Contains 'transaction' => Transaction object
     * @return void
     */
    public function after_sales($arguments = null)
    {
        if (!$arguments || !isset($arguments['transaction'])) {
            return;
        }

        $transaction = $arguments['transaction'];

        // Only process final sell transactions
        if ($transaction->type !== 'sell' || $transaction->status !== 'final') {
            return;
        }

        try {
            $businessId = $transaction->business_id;
            $business = Business::find($businessId);

            if (!$business || !$business->fbr_api_settings) {
                return;
            }

            $settings = is_string($business->fbr_api_settings) 
                ? json_decode($business->fbr_api_settings, true) 
                : $business->fbr_api_settings;

            // Check if FBR is enabled and auto-sync is on
            if (empty($settings) || !isset($settings['enabled']) || !$settings['enabled']) {
                return;
            }

            if (isset($settings['auto_sync']) && !$settings['auto_sync']) {
                return;
            }

            // Check if already synced
            if ($transaction->fbr_sync_status === 'success' && !empty($transaction->fbr_invoice_number)) {
                return;
            }

            // Load relationships if not already loaded
            if (!$transaction->relationLoaded('sell_lines')) {
                $transaction->load([
                    'sell_lines.product',
                    'sell_lines.variations',
                    'sell_lines.line_tax',
                    'location',
                    'contact',
                    'business',
                ]);
            }

            // Initialize FBR service
            $fbrService = new FbrApiService($businessId);

            // Validate before posting if enabled
            if (isset($settings['validate_before_post']) && $settings['validate_before_post']) {
                $validationResult = $fbrService->validateInvoiceData($transaction);
                
                if (!$validationResult['success'] || 
                    ($validationResult['status'] !== 'Valid' && $validationResult['status_code'] !== '00')) {
                    Log::warning('FBR Invoice Validation Failed', [
                        'transaction_id' => $transaction->id,
                        'error' => $validationResult['error'] ?? 'Validation failed',
                    ]);

                    DB::table('transactions')
                        ->where('id', $transaction->id)
                        ->update([
                            'fbr_synced_at' => now(),
                            'fbr_sync_response' => json_encode($validationResult),
                            'fbr_sync_status' => 'failed',
                        ]);

                    return;
                }
            }

            // Post invoice to FBR
            $result = $fbrService->postInvoiceData($transaction);

            // Update transaction with FBR response
            if ($result['success'] && isset($result['invoice_number'])) {
                DB::table('transactions')
                    ->where('id', $transaction->id)
                    ->update([
                        'fbr_invoice_number' => $result['invoice_number'],
                        'fbr_synced_at' => now(),
                        'fbr_sync_response' => json_encode($result['data'] ?? []),
                        'fbr_sync_status' => 'success',
                    ]);

                Log::info('FBR Invoice Synced Successfully', [
                    'transaction_id' => $transaction->id,
                    'fbr_invoice_number' => $result['invoice_number'],
                ]);
            } else {
                DB::table('transactions')
                    ->where('id', $transaction->id)
                    ->update([
                        'fbr_synced_at' => now(),
                        'fbr_sync_response' => json_encode([
                            'error' => $result['error'] ?? 'Unknown error',
                            'status_code' => $result['status_code'] ?? null,
                        ]),
                        'fbr_sync_status' => 'failed',
                    ]);

                Log::error('FBR Invoice Sync Failed', [
                    'transaction_id' => $transaction->id,
                    'error' => $result['error'] ?? 'Unknown error',
                ]);
            }

        } catch (\Exception $e) {
            Log::error('FBR after_sales Hook Error', [
                'transaction_id' => $transaction->id ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Mark as failed
            try {
                DB::table('transactions')
                    ->where('id', $transaction->id)
                    ->update([
                        'fbr_synced_at' => now(),
                        'fbr_sync_response' => json_encode(['error' => $e->getMessage()]),
                        'fbr_sync_status' => 'failed',
                    ]);
            } catch (\Exception $updateException) {
                // Ignore update errors
            }
        }
    }

    /**
     * Add FBR settings tab to business settings
     */
    public function businessSettingsTab()
    {
        if (!$this->moduleUtil->isModuleInstalled('FbrIntegration')) {
            return [];
        }

        return [
            'view_path' => 'fbrintegration::business_settings_tab',
            'view_data' => [],
        ];
    }
}
