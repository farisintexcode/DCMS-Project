<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Database.md audit_logs/outbox_events/processed_events/notifications. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('actor_id')->nullable()->index()->constrained('users')->restrictOnDelete();
            $table->string('module', 40);
            $table->string('action', 64);
            $table->string('entity_type', 80);
            $table->string('entity_id', 100)->nullable();
            $table->jsonb('previous_values_redacted')->nullable();
            $table->jsonb('new_values_redacted')->nullable();
            $table->ipAddress('ip_address')->nullable();
            $table->string('user_agent', 1024)->nullable();
            $table->timestampTz('occurred_at')->useCurrent();
            $table->uuid('correlation_id')->index();
            $table->text('reason')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['entity_type', 'entity_id', 'occurred_at']);
            $table->index(['actor_id', 'occurred_at']);
            $table->index(['module', 'action', 'occurred_at']);
        });
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION dcms_reject_audit_mutation() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN RAISE EXCEPTION 'Audit history is append-only'; END; $$;
            CREATE TRIGGER audit_logs_immutable BEFORE UPDATE OR DELETE ON audit_logs
            FOR EACH ROW EXECUTE FUNCTION dcms_reject_audit_mutation();
            SQL);
        Schema::create('outbox_events', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->uuid('event_key')->unique();
            $table->string('event_type', 100);
            $table->string('aggregate_type', 60);
            $table->bigInteger('aggregate_id');
            $table->jsonb('payload')->default('{}');
            $table->timestampTz('available_at')->useCurrent();
            $table->timestampTz('dispatched_at')->nullable();
            $table->integer('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });
        DB::statement('CREATE INDEX outbox_pending ON outbox_events (available_at, id) WHERE dispatched_at IS NULL');
        DB::statement('ALTER TABLE outbox_events ADD CONSTRAINT outbox_attempts CHECK (attempts >= 0)');
        Schema::create('processed_events', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->uuid('event_key');
            $table->string('consumer', 100);
            $table->timestampTz('processed_at')->useCurrent();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
            $table->unique(['event_key', 'consumer']);
        });
        Schema::create('notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->string('notifiable_type', 40)->default('user');
            $table->bigInteger('notifiable_id');
            $table->jsonb('data');
            $table->timestampTz('read_at')->nullable();
            $table->timestampsTz();
            $table->index(['notifiable_type', 'notifiable_id', 'read_at', 'created_at'], 'notifications_recipient');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('processed_events');
        Schema::dropIfExists('outbox_events');
        Schema::dropIfExists('audit_logs');
        DB::statement('DROP FUNCTION IF EXISTS dcms_reject_audit_mutation()');
    }
};
