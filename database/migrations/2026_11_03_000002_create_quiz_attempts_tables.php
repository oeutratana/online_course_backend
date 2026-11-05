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
        if (! Schema::hasTable('quiz_attempts')) {
            Schema::create('quiz_attempts', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('quiz_id')->constrained()->cascadeOnDelete();
                $table->unsignedInteger('attempt_number')->default(1);
                $table->unsignedInteger('total_questions')->default(0);
                $table->unsignedInteger('correct_answers')->default(0);
                $table->decimal('score', 5, 2)->default(0);
                $table->boolean('passed')->default(false);
                $table->timestamp('submitted_at')->nullable();
                $table->timestamps();

                // Attempt numbers are unique per student/quiz so retakes are
                // deterministic and concurrent submissions cannot overwrite.
                $table->unique(['user_id', 'quiz_id', 'attempt_number']);
                $table->index(['quiz_id', 'passed']);
            });
        }

        if (! Schema::hasTable('quiz_attempt_answers')) {
            Schema::create('quiz_attempt_answers', function (Blueprint $table) {
                $table->id();
                $table->foreignId('quiz_attempt_id')->constrained()->cascadeOnDelete();
                $table->foreignId('question_id')->constrained()->cascadeOnDelete();
                $table->string('selected_option')->nullable();
                $table->boolean('is_correct')->default(false);
                $table->timestamps();

                $table->unique(['quiz_attempt_id', 'question_id']);
                $table->index('question_id');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('quiz_attempt_answers');
        Schema::dropIfExists('quiz_attempts');
    }
};
