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
        Schema::table('transactions', function (Blueprint $table) {
            if (!Schema::hasColumn('transactions', 'shopify_order_id')) {
                // Check if woocommerce_order_id exists, if so add after it, otherwise add at end
                if (Schema::hasColumn('transactions', 'woocommerce_order_id')) {
                    $table->string('shopify_order_id')->nullable()->after('woocommerce_order_id');
                } else {
                    $table->string('shopify_order_id')->nullable();
                }
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
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn('shopify_order_id');
        });
    }
};
