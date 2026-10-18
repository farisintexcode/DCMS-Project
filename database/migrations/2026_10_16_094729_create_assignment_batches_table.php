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
        Schema::create('assignment_batches', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->string('external_reference', 100)->nullable();
            $table->string('name', 200);
            $table->string('type_category', 50)->default('assignment_type');
            $table->string('type_code', 64)->index();
            $table->date('start_date');
            $table->date('due_date');
            $table->text('description')->nullable();
            $table->string('dca_code', 50)->nullable();
            $table->string('dca_name', 200)->nullable();
            $table->string('la_name', 200)->nullable();
            $table->string('state_category', 50)->default('state');
            $table->string('state_code', 64)->nullable()->index();
            $table->string('status', 24)->default('active');
            $table->foreignId('created_by')->index()->constrained('users')->restrictOnDelete();
            $table->timestampTz('archived_at', 6)->nullable();
            $table->integer('lock_version')->default(0);
            $table->timestampTz('created_at', 6)->useCurrent();
            $table->timestampTz('updated_at', 6)->useCurrent();
            $table->index(['client_id', 'status', 'due_date']);
            $table->foreign(['type_category', 'type_code'])->references(['category', 'code'])->on('reference_values')->restrictOnDelete();
            $table->foreign(['state_category', 'state_code'])->references(['category', 'code'])->on('reference_values')->restrictOnDelete();
        });
        DB::statement('CREATE UNIQUE INDEX assignment_batches_client_reference_unique ON assignment_batches (client_id, external_reference) WHERE external_reference IS NOT NULL');
        DB::statement("ALTER TABLE assignment_batches ADD CONSTRAINT assignment_batch_values CHECK (due_date >= start_date AND status IN ('active','archived') AND type_category = 'assignment_type' AND state_category = 'state' AND lock_version >= 0 AND length(trim(name)) > 0)");
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION dcms_batch_client_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.client_id IS DISTINCT FROM OLD.client_id THEN
                    RAISE EXCEPTION 'Assignment client cannot change' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END; $$;
            CREATE TRIGGER assignment_batch_client_guard BEFORE UPDATE ON assignment_batches
            FOR EACH ROW EXECUTE FUNCTION dcms_batch_client_guard();
        SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('assignment_batches');
        DB::statement('DROP FUNCTION IF EXISTS dcms_batch_client_guard()');
    }
};
