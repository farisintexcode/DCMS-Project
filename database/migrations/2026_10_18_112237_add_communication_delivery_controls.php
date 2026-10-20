<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Plan/M14 delivery controls; UI67–68; canonical attempts/events/reservations. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('communication_batches', function (Blueprint $t): void {
            $t->foreignId('integration_account_id')->nullable()->index()->constrained()->restrictOnDelete();
        });
        Schema::table('communications', function (Blueprint $t): void {
            $t->timestampTz('next_attempt_at', 6)->nullable()->index();
        });
        Schema::create('communication_attempts', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->timestampsTz(6);
            $t->foreignId('communication_id')->constrained()->restrictOnDelete();
            $t->foreignId('integration_account_id')->index()->constrained()->restrictOnDelete();
            $t->integer('attempt_number');
            $t->uuid('request_key')->unique();
            $t->timestampTz('started_at', 6);
            $t->timestampTz('finished_at', 6)->nullable();
            $t->string('state', 32)->default('pending');
            $t->string('provider_reference', 200)->nullable();
            $t->string('error_code', 100)->nullable();
            $t->timestampTz('retry_after', 6)->nullable();
            $t->unique(['communication_id', 'attempt_number']);
            $t->index(['state', 'started_at']);
        });
        Schema::create('communication_events', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->timestampsTz(6);
            $t->foreignId('communication_id')->constrained()->restrictOnDelete();
            $t->foreignId('attempt_id')->nullable()->index()->constrained('communication_attempts')->restrictOnDelete();
            $t->string('event_code', 40);
            $t->timestampTz('occurred_at', 6);
            $t->timestampTz('received_at', 6);
            $t->jsonb('details')->default('{}');
            $t->index(['communication_id', 'occurred_at', 'id']);
        });
        Schema::create('communication_quota_reservations', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->timestampsTz(6);
            $t->foreignId('attempt_id')->constrained('communication_attempts')->restrictOnDelete();
            $t->string('bucket_key', 160);
            $t->foreignId('integration_account_id')->nullable()->index()->constrained()->restrictOnDelete();
            $t->timestampTz('reserved_at', 6);
            $t->timestampTz('accepted_at', 6)->nullable();
            $t->timestampTz('expires_at', 6)->nullable();
            $t->string('state', 24)->default('reserved');
            $t->unique(['attempt_id', 'bucket_key']);
            $t->index(['bucket_key', 'state', 'reserved_at']);
        });
        DB::unprepared(<<<'SQL'
            ALTER TABLE communication_batches DROP CONSTRAINT preview_batch_shape;
            ALTER TABLE communication_batches ADD CONSTRAINT preview_batch_shape CHECK(status IN ('draft','previewed','queued','sending','paused','completed','completed_with_errors','cancelled') AND lock_version>=0 AND (status='draft' OR preview_digest IS NOT NULL) AND ((confirmed_at IS NULL AND confirmed_by IS NULL AND integration_account_id IS NULL AND status IN ('draft','previewed','cancelled')) OR (confirmed_at IS NOT NULL AND confirmed_by IS NOT NULL AND integration_account_id IS NOT NULL)));
            ALTER TABLE communications DROP CONSTRAINT recipient_shape;
            ALTER TABLE communications ADD CONSTRAINT recipient_shape CHECK(channel IN ('email','whatsapp','whatsapp_voice','manual_call','letter') AND direction IN ('incoming','outgoing') AND delivery_status IN ('draft','queued','accepted','failed','unknown','suppressed','cancelled') AND (delivery_status NOT IN ('queued','accepted','failed','unknown') OR (integration_account_id IS NOT NULL AND sent_by IS NOT NULL)) AND (sent_at IS NULL OR delivery_status='accepted'));
            ALTER TABLE communication_attempts ADD CONSTRAINT attempt_shape CHECK(attempt_number>0 AND state IN ('pending','accepted','retryable_failure','permanent_failure','unknown') AND ((state='pending' AND finished_at IS NULL) OR (state<>'pending' AND finished_at IS NOT NULL)));
            ALTER TABLE communication_quota_reservations ADD CONSTRAINT reservation_shape CHECK(state IN ('reserved','consumed','released','unknown') AND (state<>'consumed' OR accepted_at IS NOT NULL) AND (state<>'released' OR expires_at IS NOT NULL));
            CREATE OR REPLACE FUNCTION preview_history_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE total integer; eligible integer;
            BEGIN
                IF TG_OP='DELETE' THEN RAISE EXCEPTION 'Communication history is retained' USING ERRCODE='23514'; END IF;
                IF TG_TABLE_NAME='communication_batches' THEN
                    IF NEW.lock_version<>OLD.lock_version+1 THEN RAISE EXCEPTION 'Batch version conflict' USING ERRCODE='23514'; END IF;
                    IF OLD.status='draft' AND NEW.status='previewed' THEN
                        SELECT count(*),count(*) FILTER(WHERE delivery_status='draft') INTO total,eligible FROM communications WHERE batch_id=OLD.id;
                        IF NEW.preview_digest IS NULL OR total<>coalesce((NEW.counts->>'selected')::integer,-1) OR eligible<>coalesce((NEW.counts->>'eligible')::integer,-1) OR (to_jsonb(NEW)-ARRAY['status','counts','preview_digest','lock_version','updated_at']) IS DISTINCT FROM (to_jsonb(OLD)-ARRAY['status','counts','preview_digest','lock_version','updated_at']) THEN RAISE EXCEPTION 'Preview counts/context mismatch' USING ERRCODE='23514'; END IF;
                    ELSE
                        IF (to_jsonb(NEW)-ARRAY['status','lock_version','confirmed_by','confirmed_at','integration_account_id','updated_at']) IS DISTINCT FROM (to_jsonb(OLD)-ARRAY['status','lock_version','confirmed_by','confirmed_at','integration_account_id','updated_at']) THEN RAISE EXCEPTION 'Frozen batch context is immutable' USING ERRCODE='23514'; END IF;
                        IF OLD.status='previewed' AND NEW.status='queued' THEN
                            SELECT count(*) INTO eligible FROM communications WHERE batch_id=OLD.id AND delivery_status='queued' AND integration_account_id=NEW.integration_account_id AND sent_by=NEW.confirmed_by;
                            IF eligible=0 OR eligible<>(OLD.counts->>'eligible')::integer OR EXISTS(SELECT 1 FROM communications WHERE batch_id=OLD.id AND delivery_status='draft') THEN RAISE EXCEPTION 'Confirmation recipient context mismatch' USING ERRCODE='23514'; END IF;
                        ELSIF OLD.confirmed_at IS NULL AND (NEW.confirmed_by,NEW.confirmed_at,NEW.integration_account_id) IS DISTINCT FROM (OLD.confirmed_by,OLD.confirmed_at,OLD.integration_account_id) THEN RAISE EXCEPTION 'Unexpected confirmation context' USING ERRCODE='23514'; END IF;
                        IF OLD.confirmed_at IS NOT NULL AND (NEW.confirmed_by,NEW.confirmed_at,NEW.integration_account_id) IS DISTINCT FROM (OLD.confirmed_by,OLD.confirmed_at,OLD.integration_account_id) THEN RAISE EXCEPTION 'Confirmation is immutable' USING ERRCODE='23514'; END IF;
                        IF NOT ((OLD.status='previewed' AND NEW.status IN ('queued','cancelled')) OR (OLD.status IN ('queued','sending','paused') AND NEW.status IN ('queued','sending','paused','completed','completed_with_errors','cancelled'))) THEN RAISE EXCEPTION 'Invalid batch transition' USING ERRCODE='23514'; END IF;
                    END IF;
                ELSE
                    IF (to_jsonb(NEW)-ARRAY['delivery_status','failure_code','provider_message_id','integration_account_id','sent_by','sent_at','next_attempt_at','updated_at']) IS DISTINCT FROM (to_jsonb(OLD)-ARRAY['delivery_status','failure_code','provider_message_id','integration_account_id','sent_by','sent_at','next_attempt_at','updated_at']) THEN RAISE EXCEPTION 'Frozen recipient content is immutable' USING ERRCODE='23514'; END IF;
                    IF OLD.delivery_status='draft' AND NEW.delivery_status='cancelled' AND (NEW.integration_account_id IS NOT NULL OR NEW.sent_by IS NOT NULL OR NEW.sent_at IS NOT NULL OR NEW.provider_message_id IS NOT NULL) THEN RAISE EXCEPTION 'Unsent cancellation cannot fabricate provider context' USING ERRCODE='23514'; END IF;
                    IF OLD.integration_account_id IS NOT NULL AND (NEW.integration_account_id,NEW.sent_by) IS DISTINCT FROM (OLD.integration_account_id,OLD.sent_by) THEN RAISE EXCEPTION 'Delivery context is immutable' USING ERRCODE='23514'; END IF;
                    IF NOT ((OLD.delivery_status='draft' AND NEW.delivery_status IN ('queued','cancelled')) OR (OLD.delivery_status='queued' AND NEW.delivery_status IN ('queued','accepted','failed','unknown','suppressed','cancelled'))) THEN RAISE EXCEPTION 'Invalid recipient transition' USING ERRCODE='23514'; END IF;
                END IF;
                RETURN NEW;
            END; $$;
            CREATE OR REPLACE FUNCTION delivery_history_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP='DELETE' THEN RAISE EXCEPTION 'Delivery history is retained' USING ERRCODE='23514'; END IF;
                IF TG_TABLE_NAME='communication_events' THEN
                    IF TG_OP='UPDATE' THEN RAISE EXCEPTION 'Events are immutable' USING ERRCODE='23514'; END IF;
                    IF NEW.attempt_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM communication_attempts WHERE id=NEW.attempt_id AND communication_id=NEW.communication_id) THEN RAISE EXCEPTION 'Event attempt mismatch' USING ERRCODE='23514'; END IF;
                ELSIF TG_TABLE_NAME='communication_attempts' THEN
                    IF NOT EXISTS(SELECT 1 FROM communications WHERE id=NEW.communication_id AND integration_account_id=NEW.integration_account_id) THEN RAISE EXCEPTION 'Attempt context mismatch' USING ERRCODE='23514'; END IF;
                    IF TG_OP='UPDATE' AND (OLD.state<>'pending' OR NEW.state='pending' OR (to_jsonb(NEW)-ARRAY['finished_at','state','provider_reference','error_code','retry_after','updated_at']) IS DISTINCT FROM (to_jsonb(OLD)-ARRAY['finished_at','state','provider_reference','error_code','retry_after','updated_at'])) THEN RAISE EXCEPTION 'Attempt history is immutable' USING ERRCODE='23514'; END IF;
                ELSE
                    IF NEW.integration_account_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM communication_attempts WHERE id=NEW.attempt_id AND integration_account_id=NEW.integration_account_id) THEN RAISE EXCEPTION 'Quota account mismatch' USING ERRCODE='23514'; END IF;
                    IF TG_OP='UPDATE' AND (OLD.state<>'reserved' OR NEW.state='reserved' OR (to_jsonb(NEW)-ARRAY['state','accepted_at','expires_at','updated_at']) IS DISTINCT FROM (to_jsonb(OLD)-ARRAY['state','accepted_at','expires_at','updated_at'])) THEN RAISE EXCEPTION 'Quota history is immutable' USING ERRCODE='23514'; END IF;
                END IF;
                RETURN NEW;
            END; $$;
            CREATE TRIGGER attempt_history BEFORE INSERT OR UPDATE OR DELETE ON communication_attempts FOR EACH ROW EXECUTE FUNCTION delivery_history_guard();
            CREATE TRIGGER event_history BEFORE INSERT OR UPDATE OR DELETE ON communication_events FOR EACH ROW EXECUTE FUNCTION delivery_history_guard();
            CREATE TRIGGER quota_history BEFORE INSERT OR UPDATE OR DELETE ON communication_quota_reservations FOR EACH ROW EXECUTE FUNCTION delivery_history_guard();
        SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Delivery evidence requires a reviewed forward migration.');
    }
};
