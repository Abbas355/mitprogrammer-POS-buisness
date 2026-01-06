<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('products', function (Blueprint $table) {
            if (!Schema::hasColumn('products', 'shopify_product_id')) {
                // Check if woocommerce_product_id exists, if so add after it, otherwise add at end
                if (Schema::hasColumn('products', 'woocommerce_product_id')) {
                    $table->string('shopify_product_id')->nullable()->after('woocommerce_product_id');
                } else {
                    $table->string('shopify_product_id')->nullable();
                }
            }
            
            if (!Schema::hasColumn('products', 'shopify_disable_sync')) {
                if (Schema::hasColumn('products', 'shopify_product_id')) {
                    $table->boolean('shopify_disable_sync')->default(0)->after('shopify_product_id');
                } else {
                    $table->boolean('shopify_disable_sync')->default(0);
                }
            }
            
            if (!Schema::hasColumn('products', 'shopify_last_synced_at')) {
                $table->timestamp('shopify_last_synced_at')->nullable()->after('shopify_disable_sync');
            }
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['shopify_product_id', 'shopify_disable_sync', 'shopify_last_synced_at']);
        });
    }
};
