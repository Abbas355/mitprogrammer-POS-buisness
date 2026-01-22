<?php

use Illuminate\Support\Facades\Route;
use Modules\FbrIntegration\Http\Controllers\FbrIntegrationController;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

Route::prefix('fbr-integration')->middleware(['web', 'auth', 'SetSessionData', 'AdminSidebarMenu'])->group(function() {
    Route::get('/settings', [FbrIntegrationController::class, 'index'])->name('fbr.settings');
    Route::post('/connect', [FbrIntegrationController::class, 'connect'])->name('fbr.connect');
    Route::post('/disconnect', [FbrIntegrationController::class, 'disconnect'])->name('fbr.disconnect');
    Route::post('/test-connection', [FbrIntegrationController::class, 'testConnection'])->name('fbr.test-connection');
    Route::post('/settings', [FbrIntegrationController::class, 'updateSettings'])->name('fbr.update-settings');
    Route::post('/sync-invoice/{transactionId}', [FbrIntegrationController::class, 'syncInvoice'])->name('fbr.sync-invoice');
});

// Install/Uninstall routes for module management
Route::middleware(['web', 'auth', 'SetSessionData', 'AdminSidebarMenu'])->group(function() {
    Route::get('/fbr-integration/install', [\Modules\FbrIntegration\Http\Controllers\InstallController::class, 'index'])->name('fbr.install');
    Route::get('/fbr-integration/update', [\Modules\FbrIntegration\Http\Controllers\InstallController::class, 'update'])->name('fbr.update');
    Route::get('/fbr-integration/uninstall', [\Modules\FbrIntegration\Http\Controllers\InstallController::class, 'uninstall'])->name('fbr.uninstall');
});
