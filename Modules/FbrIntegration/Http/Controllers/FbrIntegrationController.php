<?php

namespace Modules\FbrIntegration\Http\Controllers;

use App\Business;
use App\Transaction;
use App\Utils\ModuleUtil;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\FbrIntegration\Services\FbrApiService;

class FbrIntegrationController extends Controller
{
    protected $moduleUtil;

    public function __construct(ModuleUtil $moduleUtil)
    {
        $this->moduleUtil = $moduleUtil;
    }

    /**
     * Display FBR integration settings page
     */
    public function index()
    {
        if (!auth()->user()->can('business_settings.access')) {
            abort(403, 'Unauthorized action.');
        }

        $businessId = request()->session()->get('user.business_id');
        $business = Business::find($businessId);

        $settings = [];
        if ($business && $business->fbr_api_settings) {
            $settings = is_string($business->fbr_api_settings) 
                ? json_decode($business->fbr_api_settings, true) 
                : $business->fbr_api_settings;
        }

        // Mask security token for display
        if (isset($settings['security_token']) && !empty($settings['security_token'])) {
            $token = $settings['security_token'];
            try {
                $decrypted = Crypt::decryptString($token);
                $settings['security_token_display'] = substr($decrypted, 0, 4) . '****' . substr($decrypted, -4);
            } catch (\Exception $e) {
                $settings['security_token_display'] = substr($token, 0, 4) . '****' . substr($token, -4);
            }
        }

        return view('fbrintegration::settings', compact('settings', 'business'));
    }

    /**
     * Connect/Update FBR integration
     */
    public function connect(Request $request)
    {
        if (!auth()->user()->can('business_settings.access')) {
            abort(403, 'Unauthorized action.');
        }

        try {
            $businessId = request()->session()->get('user.business_id');
            $business = Business::find($businessId);

            if (!$business) {
                return response()->json([
                    'success' => false,
                    'msg' => 'Business not found',
                ], 404);
            }

            $request->validate([
                'security_token' => 'required|string',
                'environment' => 'required|in:sandbox,production',
                'seller_ntn' => 'required|string|min:7|max:13',
                'scenario_id' => 'nullable|string',
            ]);

            // Encrypt security token
            $securityToken = Crypt::encryptString($request->security_token);

            $settings = [
                'enabled' => true,
                'security_token' => $securityToken,
                'environment' => $request->environment,
                'seller_ntn' => $request->seller_ntn,
                'scenario_id' => $request->scenario_id ?? 'SN001',
                'auto_sync' => $request->has('auto_sync') ? (bool) $request->auto_sync : true,
                'validate_before_post' => $request->has('validate_before_post') ? (bool) $request->validate_before_post : false,
                'connected_at' => now()->toDateTimeString(),
            ];

            $business->fbr_api_settings = json_encode($settings);
            $business->save();

            return response()->json([
                'success' => true,
                'msg' => __('fbrintegration::fbr.connection_success'),
            ]);

        } catch (\Exception $e) {
            Log::error('FBR Connect Error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'msg' => __('fbrintegration::fbr.connection_error') . ': ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Disconnect FBR integration
     */
    public function disconnect(Request $request)
    {
        if (!auth()->user()->can('business_settings.access')) {
            abort(403, 'Unauthorized action.');
        }

        try {
            $businessId = request()->session()->get('user.business_id');
            $business = Business::find($businessId);

            if (!$business) {
                return response()->json([
                    'success' => false,
                    'msg' => 'Business not found',
                ], 404);
            }

            $settings = [];
            if ($business->fbr_api_settings) {
                $settings = is_string($business->fbr_api_settings) 
                    ? json_decode($business->fbr_api_settings, true) 
                    : $business->fbr_api_settings;
            }

            $settings['enabled'] = false;
            $settings['disconnected_at'] = now()->toDateTimeString();

            $business->fbr_api_settings = json_encode($settings);
            $business->save();

            return response()->json([
                'success' => true,
                'msg' => __('fbrintegration::fbr.disconnection_success'),
            ]);

        } catch (\Exception $e) {
            Log::error('FBR Disconnect Error', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'msg' => __('fbrintegration::fbr.disconnection_error') . ': ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Test FBR connection
     */
    public function testConnection(Request $request)
    {
        if (!auth()->user()->can('business_settings.access')) {
            abort(403, 'Unauthorized action.');
        }

        try {
            $businessId = request()->session()->get('user.business_id');
            
            $fbrService = new FbrApiService($businessId);
            $result = $fbrService->testConnection();

            return response()->json($result);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Test failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update FBR settings
     */
    public function updateSettings(Request $request)
    {
        if (!auth()->user()->can('business_settings.access')) {
            abort(403, 'Unauthorized action.');
        }

        try {
            $businessId = request()->session()->get('user.business_id');
            $business = Business::find($businessId);

            if (!$business || !$business->fbr_api_settings) {
                return response()->json([
                    'success' => false,
                    'msg' => 'FBR not connected',
                ], 400);
            }

            $settings = is_string($business->fbr_api_settings)
                ? json_decode($business->fbr_api_settings, true)
                : $business->fbr_api_settings;

            // Update settings
            if ($request->has('auto_sync')) {
                $settings['auto_sync'] = (bool) $request->auto_sync;
            }

            if ($request->has('validate_before_post')) {
                $settings['validate_before_post'] = (bool) $request->validate_before_post;
            }

            if ($request->has('scenario_id')) {
                $settings['scenario_id'] = $request->scenario_id;
            }

            $business->fbr_api_settings = json_encode($settings);
            $business->save();

            return response()->json([
                'success' => true,
                'msg' => __('fbrintegration::fbr.settings_updated'),
            ]);

        } catch (\Exception $e) {
            Log::error('FBR Settings Update Error', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'msg' => 'Failed to update settings: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Manually sync invoice to FBR
     */
    public function syncInvoice($transactionId)
    {
        if (!auth()->user()->can('sell.view')) {
            abort(403, 'Unauthorized action.');
        }

        try {
            $businessId = request()->session()->get('user.business_id');
            $transaction = Transaction::where('business_id', $businessId)
                ->where('id', $transactionId)
                ->where('type', 'sell')
                ->with(['sell_lines.product', 'sell_lines.variations', 'sell_lines.line_tax', 'location', 'contact', 'business'])
                ->first();

            if (!$transaction) {
                return response()->json([
                    'success' => false,
                    'msg' => 'Transaction not found',
                ], 404);
            }

            if ($transaction->status !== 'final') {
                return response()->json([
                    'success' => false,
                    'msg' => 'Only final invoices can be synced to FBR',
                ], 400);
            }

            $fbrService = new FbrApiService($businessId);
            $result = $fbrService->postInvoiceData($transaction);

            // Update transaction with FBR data
            if ($result['success']) {
                DB::table('transactions')
                    ->where('id', $transactionId)
                    ->update([
                        'fbr_invoice_number' => $result['invoice_number'] ?? null,
                        'fbr_synced_at' => now(),
                        'fbr_sync_response' => json_encode($result['data'] ?? []),
                        'fbr_sync_status' => 'success',
                    ]);

                return response()->json([
                    'success' => true,
                    'msg' => __('fbrintegration::fbr.invoice_synced_success'),
                    'invoice_number' => $result['invoice_number'],
                ]);
            } else {
                DB::table('transactions')
                    ->where('id', $transactionId)
                    ->update([
                        'fbr_synced_at' => now(),
                        'fbr_sync_response' => json_encode(['error' => $result['error'] ?? 'Unknown error']),
                        'fbr_sync_status' => 'failed',
                    ]);

                return response()->json([
                    'success' => false,
                    'msg' => __('fbrintegration::fbr.invoice_sync_failed') . ': ' . ($result['error'] ?? 'Unknown error'),
                ], 400);
            }

        } catch (\Exception $e) {
            Log::error('FBR Sync Invoice Error', [
                'transaction_id' => $transactionId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'msg' => 'Sync failed: ' . $e->getMessage(),
            ], 500);
        }
    }
}
