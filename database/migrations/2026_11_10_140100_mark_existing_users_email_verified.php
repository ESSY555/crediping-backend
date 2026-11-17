<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')
            ->whereNull('email_verified_at')
            ->whereNull('email_otp_hash')
            ->update(['email_verified_at' => now()]);
    }

    public function down(): void
    {
        // Existing accounts were grandfathered; do not un-verify them.
    }
};
