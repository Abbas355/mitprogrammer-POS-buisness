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
            if (!Schema::hasColumn('business', 'fbr_api_settings')) {
                // Try to add after shopify_api_settings if it exists, otherwise after common_settings
                if (Schema::hasColumn('business', 'shopify_api_settings')) {
                    $table->text('fbr_api_settings')->nullable()->after('shopify_api_settings');
                } elseif (Schema::hasColumn('business', 'common_settings')) {
                    $table->text('fbr_api_settings')->nullable()->after('common_settings');
                } else {
                    $table->text('fbr_api_settings')->nullable();
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
            if (Schema::hasColumn('business', 'fbr_api_settings')) {
                $table->dropColumn('fbr_api_settings');
            }
        });
    }
};
