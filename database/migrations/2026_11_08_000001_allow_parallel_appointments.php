<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// A responsible may now follow up to five clients at the same time
// (EnsureResponsibleIsAvailable::MAX_PARALLEL), which an exclusion
// constraint can not express. The count runs with the responsible's row
// locked, so two bookings for the same person still wait for each other.
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE appointments DROP CONSTRAINT IF EXISTS appointments_no_overlap');
    }

    // Fails while one person has overlapping appointments, as it should.
    public function down(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE appointments ADD CONSTRAINT appointments_no_overlap
            EXCLUDE USING gist (
                responsible_user_id WITH =,
                tsrange(starts_at, ends_at, '[)') WITH &&
            )
            WHERE (status IN ('scheduled', 'confirmed', 'in_progress') AND deleted_at IS NULL)
            SQL);
    }
};
