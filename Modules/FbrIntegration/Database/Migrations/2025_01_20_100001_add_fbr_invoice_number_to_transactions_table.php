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
            if (!Schema::hasColumn('transactions', 'fbr_invoice_number')) {
                $table->string('fbr_invoice_number', 50)->nullable()->after('invoice_no');
                $table->timestamp('fbr_synced_at')->nullable()->after('fbr_invoice_number');
                $table->text('fbr_sync_response')->nullable()->after('fbr_synced_at');
                $table->enum('fbr_sync_status', ['pending', 'success', 'failed'])->default('pending')->after('fbr_sync_response');
                
                // Add index for faster lookups
                $table->index('fbr_invoice_number');
                $table->index('fbr_sync_status');
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
            if (Schema::hasColumn('transactions', 'fbr_invoice_number')) {
                $table->dropIndex(['fbr_invoice_number']);
                $table->dropIndex(['fbr_sync_status']);
                $table->dropColumn(['fbr_invoice_number', 'fbr_synced_at', 'fbr_sync_response', 'fbr_sync_status']);
            }
        });
    }
};
