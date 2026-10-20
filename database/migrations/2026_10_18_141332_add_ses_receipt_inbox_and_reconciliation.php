<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Plan/Module M14, Prototype UI68, Database canonical verified receipts. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_events', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->timestampsTz(6);
            $t->foreignId('integration_account_id')->constrained()->restrictOnDelete();
            $t->string('event_key', 200);
            $t->string('event_type', 100);
            $t->string('provider_resource_id', 200)->nullable();
            $t->text('payload_ciphertext');
            $t->char('payload_digest', 64);
            $t->timestampTz('received_at', 6);
            $t->timestampTz('verified_at', 6);
            $t->string('state', 24)->default('pending');
            $t->timestampTz('processed_at', 6)->nullable();
            $t->string('resolution_code', 100)->nullable();
            $t->unique(['integration_account_id', 'event_key']);
            $t->index(['state', 'received_at']);
            $t->index(['integration_account_id', 'provider_resource_id']);
        });
        Schema::table('communication_events', function (Blueprint $t): void {
            $t->char('provider_dedup_key', 64)->nullable();
            $t->unique(['communication_id', 'provider_dedup_key'], 'provider_receipt_identity');
            $t->foreignId('integration_event_id')->nullable()->constrained()->restrictOnDelete();
            $t->unique(['integration_event_id', 'communication_id', 'event_code'], 'receipt_event_unique');
        });
        Schema::table('communications', function (Blueprint $t): void {
            $t->foreignId('last_receipt_event_id')->nullable()->constrained('integration_events')->restrictOnDelete();
            $t->timestampTz('delivered_at', 6)->nullable();
            $t->timestampTz('bounced_at', 6)->nullable();
            $t->timestampTz('complained_at', 6)->nullable();
        });
        Schema::table('communication_quota_reservations', function (Blueprint $t): void {
            $t->foreignId('resolution_event_id')->nullable()->constrained('integration_events')->restrictOnDelete();
        });
        Schema::table('communication_preferences', function (Blueprint $t): void {
            $t->foreignId('source_integration_event_id')->nullable()->constrained('integration_events')->restrictOnDelete();
            $t->unique('source_integration_event_id');
        });
        DB::unprepared(<<<'SQL'
            ALTER TABLE communication_preferences ALTER COLUMN recorded_by DROP NOT NULL;
            ALTER TABLE communication_preferences ADD CONSTRAINT preference_recorder_source CHECK(recorded_by IS NOT NULL OR (source_integration_event_id IS NOT NULL AND state IN ('hard_bounced','under_review','blocked')));
            ALTER TABLE integration_events ADD CONSTRAINT receipt_inbox_state CHECK(state IN ('pending','processed','needs_resolution','failed') AND ((state='processed' AND processed_at IS NOT NULL) OR (state<>'processed' AND processed_at IS NULL)));
            ALTER TABLE communications DROP CONSTRAINT recipient_shape;
            ALTER TABLE communications ADD CONSTRAINT recipient_shape CHECK(channel IN ('email','whatsapp','whatsapp_voice','manual_call','letter') AND direction IN ('incoming','outgoing') AND delivery_status IN ('draft','queued','accepted','delivered','soft_bounced','hard_bounced','complained','failed','unknown','suppressed','cancelled') AND (delivery_status NOT IN ('queued','accepted','delivered','soft_bounced','hard_bounced','complained','failed','unknown') OR (integration_account_id IS NOT NULL AND sent_by IS NOT NULL)) AND (sent_at IS NULL OR delivery_status NOT IN ('draft','queued','cancelled','suppressed')));
            CREATE OR REPLACE FUNCTION receipt_inbox_history() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP='DELETE' OR OLD.state IN ('processed','failed') OR (to_jsonb(NEW)-ARRAY['state','processed_at','resolution_code','updated_at']) IS DISTINCT FROM (to_jsonb(OLD)-ARRAY['state','processed_at','resolution_code','updated_at']) THEN RAISE EXCEPTION 'Verified receipt evidence is retained and immutable' USING ERRCODE='23514'; END IF;
                RETURN NEW;
            END; $$;
            CREATE TRIGGER verified_receipt_history BEFORE UPDATE OR DELETE ON integration_events FOR EACH ROW EXECUTE FUNCTION receipt_inbox_history();
            CREATE OR REPLACE FUNCTION authenticated_receipt_context() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_TABLE_NAME='communication_events' THEN
                    IF NEW.integration_event_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM integration_events e JOIN communication_attempts a ON a.integration_account_id=e.integration_account_id WHERE e.id=NEW.integration_event_id AND a.id=NEW.attempt_id AND a.communication_id=NEW.communication_id) THEN RAISE EXCEPTION 'Receipt attempt/account mismatch' USING ERRCODE='23514'; END IF;
                ELSE
                    IF NEW.source_integration_event_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM communication_events ce JOIN communications c ON c.id=ce.communication_id WHERE ce.integration_event_id=NEW.source_integration_event_id AND c.customer_id=NEW.customer_id AND c.contact_id=NEW.contact_id AND c.integration_account_id=(SELECT integration_account_id FROM integration_events WHERE id=NEW.source_integration_event_id)) THEN RAISE EXCEPTION 'Provider suppression context mismatch' USING ERRCODE='23514'; END IF;
                END IF;
                RETURN NEW;
            END; $$;
            CREATE TRIGGER normalized_receipt_context BEFORE INSERT ON communication_events FOR EACH ROW EXECUTE FUNCTION authenticated_receipt_context();
            CREATE TRIGGER provider_preference_context BEFORE INSERT OR UPDATE ON communication_preferences FOR EACH ROW EXECUTE FUNCTION authenticated_receipt_context();
        SQL);
        $function = DB::selectOne("SELECT pg_get_functiondef('preview_history_guard()'::regprocedure) AS body")->body;
        $receiptBranch = <<<'SQL'
            IF TG_TABLE_NAME='communications' AND TG_OP='UPDATE' THEN
                IF NEW.last_receipt_event_id IS DISTINCT FROM OLD.last_receipt_event_id AND NEW.last_receipt_event_id IS NOT NULL THEN
                    IF NOT EXISTS(SELECT 1 FROM communication_events ce JOIN integration_events e ON e.id=ce.integration_event_id WHERE ce.communication_id=NEW.id AND e.id=NEW.last_receipt_event_id AND e.integration_account_id=NEW.integration_account_id) OR (to_jsonb(NEW)-ARRAY['delivery_status','provider_message_id','sent_at','last_receipt_event_id','delivered_at','bounced_at','complained_at','failure_code','next_attempt_at','updated_at']) IS DISTINCT FROM (to_jsonb(OLD)-ARRAY['delivery_status','provider_message_id','sent_at','last_receipt_event_id','delivered_at','bounced_at','complained_at','failure_code','next_attempt_at','updated_at']) THEN RAISE EXCEPTION 'Receipt cannot rewrite frozen context' USING ERRCODE='23514'; END IF;
                    RETURN NEW;
                END IF;
                IF (NEW.delivered_at,NEW.bounced_at,NEW.complained_at,NEW.last_receipt_event_id) IS DISTINCT FROM (OLD.delivered_at,OLD.bounced_at,OLD.complained_at,OLD.last_receipt_event_id) THEN RAISE EXCEPTION 'Receipt provenance required' USING ERRCODE='23514'; END IF;
            END IF;
        SQL;
        $function = preg_replace('/BEGIN/', 'BEGIN'."\n".$receiptBranch, $function, 1);
        DB::unprepared($function);
        $function = DB::selectOne("SELECT pg_get_functiondef('delivery_history_guard()'::regprocedure) AS body")->body;
        $branch = <<<'SQL'
            IF TG_TABLE_NAME='communication_quota_reservations' AND TG_OP='UPDATE' THEN
                IF OLD.state='unknown' AND NEW.state='consumed' AND NEW.resolution_event_id IS NOT NULL THEN
                    IF NOT EXISTS(SELECT 1 FROM communication_events ce JOIN integration_events e ON e.id=ce.integration_event_id JOIN communication_attempts a ON a.id=ce.attempt_id WHERE e.id=NEW.resolution_event_id AND a.id=NEW.attempt_id AND e.integration_account_id=a.integration_account_id) OR (to_jsonb(NEW)-ARRAY['state','accepted_at','resolution_event_id','updated_at']) IS DISTINCT FROM (to_jsonb(OLD)-ARRAY['state','accepted_at','resolution_event_id','updated_at']) THEN RAISE EXCEPTION 'Authenticated unknown quota reconciliation required' USING ERRCODE='23514'; END IF;
                    RETURN NEW;
                END IF;
            END IF;
        SQL;
        $function = preg_replace('/BEGIN/', 'BEGIN'."\n".$branch, $function, 1);
        $function = str_replace("ARRAY['state','accepted_at','expires_at','updated_at']", "ARRAY['state','accepted_at','expires_at','resolution_event_id','updated_at']", $function);
        DB::unprepared($function);
    }

    public function down(): void
    {
        throw new RuntimeException('Verified provider evidence requires a reviewed forward migration.');
    }
};
