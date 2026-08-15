<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('qr_url')->nullable()->after('transaction_id');
            $table->string('bakong_md5')->nullable()->after('qr_url');
            $table->string('bakong_transaction_id')->nullable()->after('bakong_md5');
            $table->json('provider_payload')->nullable()->after('bakong_transaction_id');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn([
                'qr_url',
                'bakong_md5',
                'bakong_transaction_id',
                'provider_payload',
            ]);
        });
    }
};
