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
        Schema::table('quizzes', function (Blueprint $table) {
            if (! Schema::hasColumn('quizzes', 'description')) {
                $table->text('description')->nullable()->after('title');
            }

            if (! Schema::hasColumn('quizzes', 'passing_score')) {
                $table->unsignedTinyInteger('passing_score')->default(60)->after('description');
            }

            if (! Schema::hasColumn('quizzes', 'max_attempts')) {
                // 0 means unlimited attempts.
                $table->unsignedInteger('max_attempts')->default(0)->after('passing_score');
            }

            if (! Schema::hasColumn('quizzes', 'is_published')) {
                $table->boolean('is_published')->default(true)->after('max_attempts');
            }

            if (! Schema::hasColumn('quizzes', 'is_required')) {
                $table->boolean('is_required')->default(true)->after('is_published');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('quizzes', function (Blueprint $table) {
            foreach (['is_required', 'is_published', 'max_attempts', 'passing_score', 'description'] as $column) {
                if (Schema::hasColumn('quizzes', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
