<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('communications', function (Blueprint $table): void {
            $table->foreignId('requested_by')->nullable()->index()->constrained('users')->restrictOnDelete();
        });
        DB::table('reference_values')->insertOrIgnore(['public_id' => (string) Str::uuid7(), 'category' => 'document_type', 'code' => 'letter_pdf', 'label' => 'Generated letter PDF', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::unprepared(<<<'SQL'
            ALTER TABLE communications DROP CONSTRAINT recipient_shape;
            ALTER TABLE communications ADD CONSTRAINT recipient_shape CHECK(
                channel IN ('email','whatsapp','whatsapp_voice','manual_call','letter') AND direction IN ('incoming','outgoing') AND
                ((channel='letter' AND direction='outgoing' AND delivery_status IN ('draft','queued','generated','failed','cancelled') AND requested_by IS NOT NULL AND template_id IS NOT NULL AND subject_ciphertext IS NOT NULL AND body_ciphertext IS NOT NULL AND context_snapshot_ciphertext IS NOT NULL AND integration_account_id IS NULL AND sent_by IS NULL AND sent_at IS NULL AND provider_message_id IS NULL AND batch_id IS NULL AND contact_id IS NULL AND conversation_id IS NULL AND recipient_ciphertext IS NULL AND recipient_fingerprint IS NULL AND next_attempt_at IS NULL AND delivered_at IS NULL AND bounced_at IS NULL AND complained_at IS NULL)
                OR (channel<>'letter' AND requested_by IS NULL AND delivery_status IN ('draft','queued','accepted','delivered','soft_bounced','hard_bounced','complained','failed','unknown','suppressed','cancelled') AND (delivery_status NOT IN ('queued','accepted','delivered','soft_bounced','hard_bounced','complained','failed','unknown') OR (integration_account_id IS NOT NULL AND sent_by IS NOT NULL)) AND (sent_at IS NULL OR delivery_status NOT IN ('draft','queued','cancelled','suppressed')))));
            DROP TRIGGER recipient_preview_history ON communications;
            CREATE TRIGGER recipient_preview_history BEFORE UPDATE OR DELETE ON communications FOR EACH ROW WHEN (OLD.channel<>'letter') EXECUTE FUNCTION preview_history_guard();
            CREATE OR REPLACE FUNCTION letter_history_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP='DELETE' AND OLD.channel<>'letter' THEN RETURN OLD; END IF;
                IF TG_OP='INSERT' AND NEW.channel<>'letter' THEN RETURN NEW; END IF;
                IF TG_OP='UPDATE' AND OLD.channel<>'letter' THEN RETURN NEW; END IF;
                IF TG_OP='DELETE' THEN RAISE EXCEPTION 'Letter history is retained' USING ERRCODE='23514'; END IF;
                IF TG_OP='UPDATE' AND (NEW.channel IS DISTINCT FROM OLD.channel OR (to_jsonb(NEW)-ARRAY['delivery_status','failure_code','updated_at']) IS DISTINCT FROM (to_jsonb(OLD)-ARRAY['delivery_status','failure_code','updated_at']) OR NOT ((OLD.delivery_status='draft' AND NEW.delivery_status IN ('queued','cancelled')) OR (OLD.delivery_status='queued' AND NEW.delivery_status IN ('generated','failed','cancelled')) OR (OLD.delivery_status='failed' AND NEW.delivery_status IN ('queued','cancelled')))) THEN RAISE EXCEPTION 'Frozen letter content or transition invalid' USING ERRCODE='23514'; END IF;
                IF TG_OP='INSERT' AND (NEW.delivery_status<>'draft' OR NOT EXISTS(SELECT 1 FROM communication_templates WHERE id=NEW.template_id AND status='approved' AND channel='email')) THEN RAISE EXCEPTION 'Letter requires an approved template preview' USING ERRCODE='23514'; END IF;
                IF NEW.delivery_status='generated' AND NOT EXISTS(SELECT 1 FROM documents WHERE owner_type='communication' AND owner_id=NEW.id AND scan_status='clean' AND deleted_at IS NULL AND document_type_code='letter_pdf') THEN RAISE EXCEPTION 'Generated letter requires private artifact' USING ERRCODE='23514'; END IF;
                RETURN NEW;
            END; $$;
            CREATE TRIGGER letter_history BEFORE INSERT OR UPDATE OR DELETE ON communications FOR EACH ROW EXECUTE FUNCTION letter_history_guard();
            CREATE UNIQUE INDEX letter_document_unique ON documents(owner_id) WHERE owner_type='communication' AND document_type_code='letter_pdf';
            CREATE OR REPLACE FUNCTION letter_document_retained() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'Generated letter artifacts are retained' USING ERRCODE='23514';
            END; $$;
            CREATE TRIGGER letter_document_retained BEFORE DELETE ON documents FOR EACH ROW WHEN (OLD.owner_type='communication') EXECUTE FUNCTION letter_document_retained();
            CREATE OR REPLACE FUNCTION import_document_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.owner_type='import' AND NOT EXISTS(SELECT 1 FROM import_batches WHERE id=NEW.owner_id) THEN RAISE EXCEPTION 'Document owner missing' USING ERRCODE='23514'; END IF;
                IF NEW.owner_type='user' AND NOT EXISTS(SELECT 1 FROM users WHERE id=NEW.owner_id) THEN RAISE EXCEPTION 'Document owner missing' USING ERRCODE='23514'; END IF;
                IF NEW.owner_type='task' AND NOT EXISTS(SELECT 1 FROM tasks WHERE id=NEW.owner_id) THEN RAISE EXCEPTION 'Document owner missing' USING ERRCODE='23514'; END IF;
                IF NEW.owner_type='activity' AND NOT EXISTS(SELECT 1 FROM collection_activities WHERE id=NEW.owner_id) THEN RAISE EXCEPTION 'Document owner missing' USING ERRCODE='23514'; END IF;
                IF NEW.owner_type='communication' AND NOT EXISTS(SELECT 1 FROM communications WHERE id=NEW.owner_id AND channel='letter' AND delivery_status='queued' AND requested_by=NEW.uploaded_by AND NEW.document_type_code='letter_pdf' AND NEW.mime_type='application/pdf' AND NEW.scan_status='clean' AND NEW.disk='s3' AND NEW.activity_id IS NULL) THEN RAISE EXCEPTION 'Letter document context unavailable' USING ERRCODE='23514'; END IF;
                IF NEW.owner_type NOT IN ('import','user','task','activity','communication') THEN RAISE EXCEPTION 'Document owner module unavailable' USING ERRCODE='23514'; END IF;
                IF TG_OP='UPDATE' AND (OLD.owner_type='communication' OR (NEW.owner_type,NEW.owner_id,NEW.bucket,NEW.object_key,NEW.version_id,NEW.disk,NEW.sha256,NEW.size_bytes,NEW.uploaded_by,NEW.original_filename,NEW.mime_type) IS DISTINCT FROM (OLD.owner_type,OLD.owner_id,OLD.bucket,OLD.object_key,OLD.version_id,OLD.disk,OLD.sha256,OLD.size_bytes,OLD.uploaded_by,OLD.original_filename,OLD.mime_type)) THEN RAISE EXCEPTION 'Document source immutable' USING ERRCODE='23514'; END IF;
                IF TG_OP='UPDATE' AND EXISTS(SELECT 1 FROM task_submission_documents WHERE document_id=OLD.id) AND NEW IS DISTINCT FROM OLD THEN RAISE EXCEPTION 'Submitted proof immutable' USING ERRCODE='23514'; END IF;
                RETURN NEW;
            END; $$;
        SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new RuntimeException('Letter evidence requires a reviewed forward migration.');
    }
};
