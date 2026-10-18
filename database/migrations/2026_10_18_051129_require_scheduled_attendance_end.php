<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // A PostgreSQL CHECK accepts UNKNOWN: explicitly reject a null end for working days.
        DB::statement("ALTER TABLE attendance_schedules ADD CONSTRAINT attendance_schedule_end_required CHECK (day_type<>'scheduled' OR scheduled_end_at IS NOT NULL)");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE attendance_schedules DROP CONSTRAINT attendance_schedule_end_required');
    }
};
