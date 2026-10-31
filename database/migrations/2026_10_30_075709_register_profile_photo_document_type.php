<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('reference_values')->insertOrIgnore(['public_id' => (string) Str::uuid7(), 'category' => 'document_type', 'code' => 'avatar', 'label' => 'Profile picture', 'created_at' => now(), 'updated_at' => now()]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! DB::table('documents')->where('document_type_code', 'avatar')->exists()) {
            DB::table('reference_values')->where('category', 'document_type')->where('code', 'avatar')->delete();
        }
    }
};
