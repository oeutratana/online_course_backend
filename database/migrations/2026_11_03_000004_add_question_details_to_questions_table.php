<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('questions', function (Blueprint $table) {
            if (! Schema::hasColumn('questions', 'type')) {
                $table->string('type')->default('multiple_choice')->after('question');
            }

            if (! Schema::hasColumn('questions', 'points')) {
                $table->unsignedInteger('points')->default(1)->after('correct_option');
            }

            if (! Schema::hasColumn('questions', 'explanation')) {
                $table->text('explanation')->nullable()->after('points');
            }

            if (! Schema::hasColumn('questions', 'order')) {
                $table->unsignedInteger('order')->default(0)->after('explanation');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('questions', function (Blueprint $table) {
            foreach (['order', 'explanation', 'points', 'type'] as $column) {
                if (Schema::hasColumn('questions', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
