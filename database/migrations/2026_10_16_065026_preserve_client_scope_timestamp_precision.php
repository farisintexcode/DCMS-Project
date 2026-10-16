<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Preserve effective instants; whole-second PostgreSQL rounding can delay a fresh scope grant. */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE user_client_scopes ALTER COLUMN valid_from TYPE timestamptz(6), ALTER COLUMN valid_to TYPE timestamptz(6)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE user_client_scopes ALTER COLUMN valid_from TYPE timestamptz(0), ALTER COLUMN valid_to TYPE timestamptz(0)');
    }
};
