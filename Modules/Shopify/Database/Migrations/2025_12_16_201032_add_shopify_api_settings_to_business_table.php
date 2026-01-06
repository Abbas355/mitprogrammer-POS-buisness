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
        Schema::table('business', function (Blueprint $table) {
            if (!Schema::hasColumn('business', 'shopify_api_settings')) {
                // Try to add after common_settings if it exists, otherwise just add at end
                if (Schema::hasColumn('business', 'common_settings')) {
                    $table->text('shopify_api_settings')->nullable()->after('common_settings');
                } else {
                    $table->text('shopify_api_settings')->nullable();
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
        Schema::table('business', function (Blueprint $table) {
            $table->dropColumn('shopify_api_settings');
        });
    }
};
