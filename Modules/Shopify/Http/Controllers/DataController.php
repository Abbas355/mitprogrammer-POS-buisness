<?php

namespace Modules\Shopify\Http\Controllers;

use App\Utils\ModuleUtil;
use Illuminate\Http\Request;

class DataController
{
    protected $moduleUtil;

    public function __construct(ModuleUtil $moduleUtil = null)
    {
        // Resolve ModuleUtil from container if not provided (for compatibility with ModuleUtil::getModuleData)
        $this->moduleUtil = $moduleUtil ?? app(ModuleUtil::class);
    }

    /**
     * Add Shopify filter to product list
     */
    public function productFilters()
    {
        if (!$this->moduleUtil->isModuleInstalled('Shopify')) {
            return [];
        }

        $businessId = request()->session()->get('user.business_id');
        $business = \App\Business::find($businessId);
        
        if (!$business || !$business->shopify_api_settings) {
            return [];
        }

        return [
            'view_path' => 'shopify::product_filter',
            'view_data' => [],
        ];
    }

    /**
     * Add Shopify action buttons to product list
     */
    public function productActionButtons()
    {
        if (!$this->moduleUtil->isModuleInstalled('Shopify')) {
            return [];
        }

        $businessId = request()->session()->get('user.business_id');
        $business = \App\Business::find($businessId);
        
        if (!$business || !$business->shopify_api_settings) {
            return [];
        }

        return [
            'view_path' => 'shopify::product_actions',
            'view_data' => [],
        ];
    }

    /**
     * Add Shopify tab to product edit page
     */
    public function productTabs()
    {
        if (!$this->moduleUtil->isModuleInstalled('Shopify')) {
            return [];
        }

        $businessId = request()->session()->get('user.business_id');
        $business = \App\Business::find($businessId);
        
        if (!$business || !$business->shopify_api_settings) {
            return [];
        }

        return [
            'view_path' => 'shopify::product_tab',
            'view_data' => [],
        ];
    }

    /**
     * Add Shopify settings tab to business settings
     */
    public function businessSettingsTab()
    {
        if (!$this->moduleUtil->isModuleInstalled('Shopify')) {
            return [];
        }

        return [
            'view_path' => 'shopify::business_settings_tab',
            'view_data' => [],
        ];
    }

    /**
     * Add Shopify menu to admin sidebar
     * Note: This method is called by AdminSidebarMenu middleware via getModuleData
     * The menu object should be accessed via closure binding if needed
     */
    public function modifyAdminMenu()
    {
        if (!$this->moduleUtil->isModuleInstalled('Shopify')) {
            return;
        }

        // This method is called but the menu is already built
        // Menu items should be added via route registration or other means
        // For now, the Shopify settings are accessible via the ShopifyController routes
        return [];
    }
}

