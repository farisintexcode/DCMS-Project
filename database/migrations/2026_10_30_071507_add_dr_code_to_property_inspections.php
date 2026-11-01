<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('property_inspections', function (Blueprint $table): void {
            $table->string('dr_category', 50)->default('dr_code');
            $table->string('dr_code', 64)->nullable();
            $table->foreign(['dr_category', 'dr_code'])->references(['category', 'code'])->on('reference_values')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE property_inspections ADD CONSTRAINT inspection_dr_category CHECK (dr_category='dr_code')");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('property_inspections')->whereNotNull('dr_code')->exists()) {
            throw new RuntimeException('DR observation history is retained; use a reviewed forward migration.');
        }
        DB::statement('ALTER TABLE property_inspections DROP CONSTRAINT inspection_dr_category');
        Schema::table('property_inspections', function (Blueprint $table): void {
            $table->dropForeign(['dr_category', 'dr_code']);
            $table->dropColumn(['dr_category', 'dr_code']);
        });
    }
};
