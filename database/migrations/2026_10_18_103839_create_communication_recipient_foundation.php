<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** M14/UI66–68 and approved eligibility policy; canonical entities only. */
    public function up(): void
    {
        Schema::create('communication_preferences', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->timestampsTz(6);
            $t->foreignId('client_id')->index()->constrained()->restrictOnDelete();
            $t->foreignId('customer_id')->index()->constrained('debtors')->restrictOnDelete();
            $t->foreignId('contact_id')->nullable()->index()->constrained('contacts')->restrictOnDelete();
            $t->foreignId('debt_account_id')->nullable()->index()->constrained()->restrictOnDelete();
            $t->foreignId('task_id')->nullable()->index()->constrained()->restrictOnDelete();
            $t->string('channel', 24)->default('email');
            $t->string('purpose', 64)->default('service_collection');
            $t->string('state', 24)->default('unknown');
            $t->string('basis', 80)->nullable();
            $t->text('evidence_ciphertext');
            $t->text('reason_ciphertext');
            $t->timestampTz('effective_at', 6);
            $t->timestampTz('expires_at', 6)->nullable();
            $t->foreignId('recorded_by')->index()->constrained('users')->restrictOnDelete();
            $t->string('review_status', 24)->default('pending');
            $t->foreignId('reviewed_by')->nullable()->index()->constrained('users')->restrictOnDelete();
            $t->timestampTz('reviewed_at', 6)->nullable();
            $t->text('review_reason_ciphertext')->nullable();
            $t->foreignId('supersedes_id')->nullable()->index()->constrained('communication_preferences')->restrictOnDelete();
            $t->boolean('is_current')->default(false);
            $t->integer('lock_version')->default(0);
            $t->uuid('request_key')->unique();
            $t->index(['customer_id', 'channel', 'purpose', 'review_status']);
        });
        Schema::create('communication_batches', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->timestampsTz(6);
            $t->foreignId('client_id')->index()->constrained()->restrictOnDelete();
            $t->foreignId('template_id')->index()->constrained('communication_templates')->restrictOnDelete();
            $t->foreignId('requested_by')->index()->constrained('users')->restrictOnDelete();
            $t->jsonb('selection_criteria');
            $t->char('preview_digest', 64)->nullable();
            $t->string('status', 32)->default('draft');
            $t->jsonb('counts')->default('{}');
            $t->foreignId('confirmed_by')->nullable()->index()->constrained('users')->restrictOnDelete();
            $t->timestampTz('confirmed_at', 6)->nullable();
            $t->uuid('idempotency_key')->unique();
            $t->integer('lock_version')->default(0);
            $t->index(['client_id', 'status', 'created_at']);
            $t->index(['requested_by', 'created_at']);
        });
        Schema::create('conversations', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->timestampsTz(6);
            $t->foreignId('customer_id')->constrained('debtors')->restrictOnDelete();
            $t->foreignId('contact_id')->index()->constrained()->restrictOnDelete();
            $t->foreignId('integration_account_id')->index()->constrained()->restrictOnDelete();
            $t->string('channel', 24)->default('whatsapp');
            $t->string('external_thread_reference', 200)->nullable();
            $t->foreignId('assigned_to')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestampTz('service_window_expires_at', 6)->nullable();
            $t->timestampTz('last_message_at', 6)->nullable();
            $t->unique(['customer_id', 'contact_id', 'integration_account_id', 'channel'], 'conversation_context_unique');
            $t->index(['assigned_to', 'last_message_at']);
        });
        Schema::create('communications', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->timestampsTz(6);
            $t->foreignId('customer_id')->constrained('debtors')->restrictOnDelete();
            $t->foreignId('task_id')->nullable()->index()->constrained()->restrictOnDelete();
            $t->foreignId('contact_id')->nullable()->index()->constrained()->restrictOnDelete();
            $t->foreignId('conversation_id')->nullable()->index()->constrained()->restrictOnDelete();
            $t->foreignId('batch_id')->nullable()->constrained('communication_batches')->restrictOnDelete();
            $t->foreignId('template_id')->nullable()->index()->constrained('communication_templates')->restrictOnDelete();
            $t->foreignId('integration_account_id')->nullable()->index()->constrained()->restrictOnDelete();
            $t->string('channel', 24);
            $t->string('direction', 16);
            $t->text('recipient_ciphertext')->nullable();
            $t->char('recipient_fingerprint', 64)->nullable()->index();
            $t->text('subject_ciphertext')->nullable();
            $t->text('body_ciphertext')->nullable();
            $t->text('context_snapshot_ciphertext')->nullable();
            $t->foreignId('sent_by')->nullable()->index()->constrained('users')->restrictOnDelete();
            $t->timestampTz('sent_at', 6)->nullable();
            $t->string('delivery_status', 24)->default('draft');
            $t->string('provider_message_id', 200)->nullable();
            $t->string('failure_code', 100)->nullable();
            $t->uuid('idempotency_key')->unique();
            $t->index(['customer_id', 'created_at']);
            $t->index(['batch_id', 'delivery_status']);
            $t->unique(['integration_account_id', 'provider_message_id']);
        });
        DB::unprepared(<<<'SQL'
            ALTER TABLE communication_preferences ADD CONSTRAINT email_preference_shape CHECK(channel='email' AND purpose='service_collection' AND state IN ('allowed','blocked','unknown','withdrawn','invalid_address','hard_bounced','under_review') AND review_status IN ('pending','approved','rejected') AND lock_version>=0 AND (expires_at IS NULL OR expires_at>effective_at) AND (NOT is_current OR review_status='approved') AND ((review_status='pending' AND reviewed_by IS NULL AND reviewed_at IS NULL AND review_reason_ciphertext IS NULL) OR (review_status<>'pending' AND reviewed_by IS NOT NULL AND reviewed_at IS NOT NULL AND review_reason_ciphertext IS NOT NULL)) AND (state<>'allowed' OR (contact_id IS NOT NULL AND debt_account_id IS NOT NULL AND basis IS NOT NULL)));
            CREATE UNIQUE INDEX email_preference_current ON communication_preferences(customer_id,coalesce(contact_id,0),coalesce(debt_account_id,0),channel,purpose) WHERE is_current;
            ALTER TABLE communication_batches ADD CONSTRAINT preview_batch_shape CHECK(status IN ('draft','previewed','cancelled') AND confirmed_by IS NULL AND confirmed_at IS NULL AND lock_version>=0 AND (status='draft' OR preview_digest IS NOT NULL));
            ALTER TABLE communications ADD CONSTRAINT recipient_shape CHECK(channel IN ('email','whatsapp','whatsapp_voice','manual_call','letter') AND direction IN ('incoming','outgoing') AND delivery_status IN ('draft','suppressed','cancelled') AND sent_at IS NULL AND sent_by IS NULL AND integration_account_id IS NULL AND provider_message_id IS NULL);
            CREATE OR REPLACE FUNCTION communication_recipient_context() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE client bigint;
            BEGIN
                SELECT client_id INTO client FROM debtors WHERE id=NEW.customer_id;
                IF NEW.contact_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM contacts WHERE id=NEW.contact_id AND debtor_id=NEW.customer_id) THEN RAISE EXCEPTION 'Communication contact context mismatch' USING ERRCODE='23514'; END IF;
                IF TG_TABLE_NAME='communication_preferences' THEN
                    IF NEW.supersedes_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM communication_preferences p WHERE p.id=NEW.supersedes_id AND p.customer_id=NEW.customer_id AND p.contact_id IS NOT DISTINCT FROM NEW.contact_id AND p.debt_account_id IS NOT DISTINCT FROM NEW.debt_account_id AND p.channel=NEW.channel AND p.purpose=NEW.purpose) THEN RAISE EXCEPTION 'Preference lineage mismatch' USING ERRCODE='23514'; END IF;
                    IF NEW.client_id IS DISTINCT FROM client THEN RAISE EXCEPTION 'Preference client mismatch' USING ERRCODE='23514'; END IF;
                    IF NEW.debt_account_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM debt_accounts a JOIN account_parties p ON p.debt_account_id=a.id WHERE a.id=NEW.debt_account_id AND a.client_id=client AND p.debtor_id=NEW.customer_id) THEN RAISE EXCEPTION 'Preference account mismatch' USING ERRCODE='23514'; END IF;
                    IF NEW.task_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM tasks WHERE id=NEW.task_id AND customer_id=NEW.customer_id AND debt_account_id=NEW.debt_account_id) THEN RAISE EXCEPTION 'Preference task mismatch' USING ERRCODE='23514'; END IF;
                ELSIF TG_TABLE_NAME='communications' THEN
                    IF NEW.task_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM tasks WHERE id=NEW.task_id AND customer_id=NEW.customer_id) THEN RAISE EXCEPTION 'Message task mismatch' USING ERRCODE='23514'; END IF;
                    IF NEW.batch_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM communication_batches b WHERE b.id=NEW.batch_id AND b.client_id=client AND b.template_id=NEW.template_id AND (TG_OP='UPDATE' OR b.status='draft')) THEN RAISE EXCEPTION 'Message batch mismatch or frozen batch' USING ERRCODE='23514'; END IF;
                    IF NEW.conversation_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM conversations WHERE id=NEW.conversation_id AND customer_id=NEW.customer_id AND contact_id=NEW.contact_id) THEN RAISE EXCEPTION 'Conversation mismatch' USING ERRCODE='23514'; END IF;
                END IF;
                RETURN NEW;
            END; $$;
            CREATE TRIGGER preference_context BEFORE INSERT OR UPDATE ON communication_preferences FOR EACH ROW EXECUTE FUNCTION communication_recipient_context();
            CREATE TRIGGER envelope_context BEFORE INSERT OR UPDATE ON communications FOR EACH ROW EXECUTE FUNCTION communication_recipient_context();
            CREATE TRIGGER conversation_context BEFORE INSERT OR UPDATE ON conversations FOR EACH ROW EXECUTE FUNCTION communication_recipient_context();
            CREATE OR REPLACE FUNCTION email_preference_history() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP='DELETE' THEN RAISE EXCEPTION 'Eligibility history is retained' USING ERRCODE='23514'; END IF;
                IF NEW.lock_version<>OLD.lock_version+1 THEN RAISE EXCEPTION 'Preference version conflict' USING ERRCODE='23514'; END IF;
                IF OLD.review_status='pending' THEN
                    IF NEW.review_status NOT IN ('approved','rejected') OR (to_jsonb(NEW)-ARRAY['review_status','reviewed_by','reviewed_at','review_reason_ciphertext','is_current','lock_version','updated_at']) IS DISTINCT FROM (to_jsonb(OLD)-ARRAY['review_status','reviewed_by','reviewed_at','review_reason_ciphertext','is_current','lock_version','updated_at']) THEN RAISE EXCEPTION 'Review exact submitted eligibility evidence' USING ERRCODE='23514'; END IF;
                ELSIF NOT (OLD.review_status='approved' AND OLD.is_current AND NOT NEW.is_current AND (to_jsonb(NEW)-ARRAY['is_current','lock_version','updated_at']) IS NOT DISTINCT FROM (to_jsonb(OLD)-ARRAY['is_current','lock_version','updated_at'])) THEN RAISE EXCEPTION 'Reviewed eligibility is immutable' USING ERRCODE='23514'; END IF;
                RETURN NEW;
            END; $$;
            CREATE TRIGGER preference_history BEFORE UPDATE OR DELETE ON communication_preferences FOR EACH ROW EXECUTE FUNCTION email_preference_history();
            CREATE OR REPLACE FUNCTION preview_history_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE total integer; eligible integer;
            BEGIN
                IF TG_OP='DELETE' THEN RAISE EXCEPTION 'Recipient preview history is retained' USING ERRCODE='23514'; END IF;
                IF TG_TABLE_NAME='communication_batches' THEN
                    IF OLD.status='draft' AND NEW.status='previewed' THEN
                        SELECT count(*),count(*) FILTER(WHERE delivery_status='draft') INTO total,eligible FROM communications WHERE batch_id=OLD.id;
                        IF NEW.preview_digest IS NULL OR total<>coalesce((NEW.counts->>'selected')::integer,-1) OR eligible<>coalesce((NEW.counts->>'eligible')::integer,-1) OR (to_jsonb(NEW)-ARRAY['status','counts','preview_digest','lock_version','updated_at']) IS DISTINCT FROM (to_jsonb(OLD)-ARRAY['status','counts','preview_digest','lock_version','updated_at']) THEN RAISE EXCEPTION 'Preview counts/context mismatch' USING ERRCODE='23514'; END IF;
                    ELSIF NOT(OLD.status='previewed' AND NEW.status='cancelled' AND (to_jsonb(NEW)-ARRAY['status','lock_version','updated_at']) IS NOT DISTINCT FROM (to_jsonb(OLD)-ARRAY['status','lock_version','updated_at'])) THEN RAISE EXCEPTION 'Preview is immutable; create a new preview' USING ERRCODE='23514'; END IF;
                    IF NEW.lock_version<>OLD.lock_version+1 THEN RAISE EXCEPTION 'Preview version conflict' USING ERRCODE='23514'; END IF;
                ELSE
                    IF OLD.delivery_status<>'draft' OR NEW.delivery_status<>'cancelled' OR (to_jsonb(NEW)-ARRAY['delivery_status','updated_at']) IS DISTINCT FROM (to_jsonb(OLD)-ARRAY['delivery_status','updated_at']) THEN RAISE EXCEPTION 'Frozen recipient cannot be rewritten' USING ERRCODE='23514'; END IF;
                END IF;
                RETURN NEW;
            END; $$;
            CREATE TRIGGER batch_preview_history BEFORE UPDATE OR DELETE ON communication_batches FOR EACH ROW EXECUTE FUNCTION preview_history_guard();
            CREATE TRIGGER recipient_preview_history BEFORE UPDATE OR DELETE ON communications FOR EACH ROW EXECUTE FUNCTION preview_history_guard();
        SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Communication evidence and preview history require a reviewed forward migration.');
    }
};
