<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('variations', function (Blueprint $table) {
            if (!Schema::hasColumn('variations', 'shopify_variant_id')) {
                // Add after sub_sku if it exists, otherwise add at end
                if (Schema::hasColumn('variations', 'sub_sku')) {
                    $table->string('shopify_variant_id')->nullable()->after('sub_sku');
                } else {
                    $table->string('shopify_variant_id')->nullable();
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
        Schema::table('variations', function (Blueprint $table) {
            if (Schema::hasColumn('variations', 'shopify_variant_id')) {
                $table->dropColumn('shopify_variant_id');
            }
        });
    }
};
