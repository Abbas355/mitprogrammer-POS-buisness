<?php

namespace Modules\FbrIntegration\Http\Controllers;

use App\System;
use App\Utils\ModuleUtil;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Module;

class InstallController extends Controller
{
    protected $moduleUtil;

    public function __construct(ModuleUtil $moduleUtil)
    {
        $this->moduleUtil = $moduleUtil;
    }

    /**
     * Install the module
     */
    public function index()
    {
        if (!auth()->user()->can('manage_modules')) {
            abort(403, 'Unauthorized action.');
        }

        try {
            DB::beginTransaction();

            // Enable the module first (so routes are available)
            $module = Module::find('FbrIntegration');
            if ($module && !$module->isEnabled()) {
                $module->enable();
            }

            // Run migrations
            Artisan::call('migrate', [
                '--path' => 'Modules/FbrIntegration/Database/Migrations',
                '--force' => true,
            ]);

            // Get module version from config
            $module_version = config('fbrintegration.module_version', '1.0.0');

            // Set module version
            System::addProperty('fbrintegration_version', $module_version);

            DB::commit();

            return redirect()->action([\App\Http\Controllers\Install\ModulesController::class, 'index'])
                ->with('status', [
                    'success' => 1,
                    'msg' => __('lang_v1.module_installed_successfully', ['module' => 'FBR Integration']),
                ]);

        } catch (\Exception $e) {
            DB::rollBack();
            
            return redirect()->action([\App\Http\Controllers\Install\ModulesController::class, 'index'])
                ->with('status', [
                    'success' => 0,
                    'msg' => __('messages.something_went_wrong') . ': ' . $e->getMessage(),
                ]);
        }
    }

    /**
     * Update the module
     */
    public function update()
    {
        if (!auth()->user()->can('manage_modules')) {
            abort(403, 'Unauthorized action.');
        }

        try {
            DB::beginTransaction();

            // Run migrations
            Artisan::call('migrate', [
                '--path' => 'Modules/FbrIntegration/Database/Migrations',
                '--force' => true,
            ]);

            // Get module version from config
            $module_version = config('fbrintegration.module_version', '1.0.0');

            // Update module version
            System::addProperty('fbrintegration_version', $module_version);

            DB::commit();

            return redirect()->action([\App\Http\Controllers\Install\ModulesController::class, 'index'])
                ->with('status', [
                    'success' => 1,
                    'msg' => __('lang_v1.module_updated_successfully', ['module' => 'FBR Integration']),
                ]);

        } catch (\Exception $e) {
            DB::rollBack();
            
            return redirect()->action([\App\Http\Controllers\Install\ModulesController::class, 'index'])
                ->with('status', [
                    'success' => 0,
                    'msg' => __('messages.something_went_wrong') . ': ' . $e->getMessage(),
                ]);
        }
    }

    /**
     * Uninstall the module
     */
    public function uninstall()
    {
        if (!auth()->user()->can('manage_modules')) {
            abort(403, 'Unauthorized action.');
        }

        try {
            DB::beginTransaction();

            // Disable the module
            $module = Module::find('FbrIntegration');
            if ($module && $module->isEnabled()) {
                $module->disable();
            }

            // Remove module version
            System::removeProperty('fbrintegration_version');

            // Note: We don't drop columns to preserve data
            // If you want to drop columns, uncomment below:
            /*
            Artisan::call('migrate:rollback', [
                '--path' => 'Modules/FbrIntegration/Database/Migrations',
                '--force' => true,
            ]);
            */

            DB::commit();

            return redirect()->action([\App\Http\Controllers\Install\ModulesController::class, 'index'])
                ->with('status', [
                    'success' => 1,
                    'msg' => __('lang_v1.module_uninstalled_successfully', ['module' => 'FBR Integration']),
                ]);

        } catch (\Exception $e) {
            DB::rollBack();
            
            return redirect()->action([\App\Http\Controllers\Install\ModulesController::class, 'index'])
                ->with('status', [
                    'success' => 0,
                    'msg' => __('messages.something_went_wrong') . ': ' . $e->getMessage(),
                ]);
        }
    }
}
