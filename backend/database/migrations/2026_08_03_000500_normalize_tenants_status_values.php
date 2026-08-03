<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Normalize any tenants.status value outside the upcoming TenantStatus
     * enum (active, paused) to 'active'. No schema change: the column is
     * already a string with a default of 'active'. This must run before
     * the enum cast is added to the Tenant model, so the cast never reads
     * an invalid legacy value.
     */
    public function up(): void
    {
        DB::table('tenants')
            ->whereNull('status')
            ->orWhereNotIn('status', ['active', 'paused'])
            ->update(['status' => 'active']);
    }

    /**
     * No-op: this migration only normalizes data, it does not change the
     * schema, so there is nothing structural to reverse.
     */
    public function down(): void
    {
        // Intentionally empty — data-only normalization, no schema change.
    }
};
