<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Database-level guard against double-booking a staff member. The in-transaction
 * check in BookingService can't hold under true concurrency (an open slot has no
 * row to lock, and Postgres FOR UPDATE takes no gap lock), so we enforce it with
 * a GiST exclusion constraint: no two non-cancelled appointments for the same
 * staff may have overlapping [starts_at, ends_at) ranges.
 *
 * Postgres-only; a no-op on other drivers (e.g. sqlite in tests).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');
        DB::statement(<<<'SQL'
            ALTER TABLE appointments
            ADD CONSTRAINT appointments_no_overlap
            EXCLUDE USING gist (staff_id WITH =, tsrange(starts_at, ends_at) WITH &&)
            WHERE (status <> 'cancelled')
        SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE appointments DROP CONSTRAINT IF EXISTS appointments_no_overlap');
    }
};
