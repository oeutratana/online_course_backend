<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The instructor role has been removed. Existing instructor accounts are
     * downgraded to students; course content stays assigned to its author and
     * is managed by admins from now on.
     */
    public function up(): void
    {
        DB::table('users')
            ->where('role', 'instructor')
            ->update(['role' => 'student', 'updated_at' => now()]);
    }

    public function down(): void
    {
        // The role is gone, so there is nothing meaningful to restore.
    }
};
