<?php

namespace Modules\Shopify\Http\Controllers;

use App\Http\Controllers\Controller;
use App\System;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

class InstallController extends Controller
{
    /**
     * Install the Shopify module
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        try {
            DB::beginTransaction();

            // Get module version from config
            $module_version = config('shopify.module_version', '1.0.0');

            // Set the module version in system table to mark it as installed
            System::addProperty('shopify_version', $module_version);

            // Run migrations if needed
            Artisan::call('migrate', ['--force' => true]);

            DB::commit();

            $output = [
                'success' => 1,
                'msg' => __('lang_v1.module_installed_successfully', ['module' => 'Shopify']),
            ];

            return redirect()->action([\App\Http\Controllers\Install\ModulesController::class, 'index'])
                ->with('status', $output);
        } catch (\Exception $e) {
            DB::rollBack();
            
            $output = [
                'success' => 0,
                'msg' => __('messages.something_went_wrong') . ': ' . $e->getMessage(),
            ];

            return redirect()->action([\App\Http\Controllers\Install\ModulesController::class, 'index'])
                ->with('status', $output);
        }
    }

    /**
     * Update the Shopify module
     *
     * @return \Illuminate\Http\Response
     */
    public function update()
    {
        try {
            DB::beginTransaction();

            // Get module version from config
            $module_version = config('shopify.module_version', '1.0.0');

            // Update the module version in system table
            System::addProperty('shopify_version', $module_version);

            // Run migrations if needed
            Artisan::call('migrate', ['--force' => true]);

            DB::commit();

            $output = [
                'success' => 1,
                'msg' => __('lang_v1.module_updated_successfully', ['module' => 'Shopify']),
            ];

            return redirect()->action([\App\Http\Controllers\Install\ModulesController::class, 'index'])
                ->with('status', $output);
        } catch (\Exception $e) {
            DB::rollBack();
            
            $output = [
                'success' => 0,
                'msg' => __('messages.something_went_wrong') . ': ' . $e->getMessage(),
            ];

            return redirect()->action([\App\Http\Controllers\Install\ModulesController::class, 'index'])
                ->with('status', $output);
        }
    }

    /**
     * Uninstall the Shopify module
     *
     * @return \Illuminate\Http\Response
     */
    public function uninstall()
    {
        try {
            DB::beginTransaction();

            // Remove the module version from system table to mark it as uninstalled
            System::removeProperty('shopify_version');

            DB::commit();

            $output = [
                'success' => 1,
                'msg' => __('lang_v1.module_uninstalled_successfully', ['module' => 'Shopify']),
            ];

            return redirect()->action([\App\Http\Controllers\Install\ModulesController::class, 'index'])
                ->with('status', $output);
        } catch (\Exception $e) {
            DB::rollBack();
            
            $output = [
                'success' => 0,
                'msg' => __('messages.something_went_wrong') . ': ' . $e->getMessage(),
            ];

            return redirect()->action([\App\Http\Controllers\Install\ModulesController::class, 'index'])
                ->with('status', $output);
        }
    }
}

