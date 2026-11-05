<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The payment integration has been migrated from Bakong (KHQR) to PayWay.
 *
 * Bakong-specific columns are removed. The PayWay transaction ID is stored
 * in the existing generic `payments.transaction_id` column (PayWay calls this
 * reference `tran_id`), so no new provider column is required. Historical
 * payments keep their amount/status/date information; only the obsolete
 * Bakong references are dropped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['bakong_md5', 'bakong_transaction_id']);
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('bakong_md5')->nullable()->after('qr_url');
            $table->string('bakong_transaction_id')->nullable()->after('bakong_md5');
        });
    }
};
