<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Owner-approved M06 policy; Database.md approved physical extensions. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('collection_activities', function (Blueprint $t): void {
            $t->string('visit_purpose_category', 50)->default('visit_purpose');
            $t->string('visit_purpose_code', 64)->nullable();
            $t->foreign(['visit_purpose_category', 'visit_purpose_code'])->references(['category', 'code'])->on('reference_values')->restrictOnDelete();
            $t->boolean('business_applicable')->default(false);
            $t->boolean('signboard_available')->nullable();
            $t->text('backdating_reason_ciphertext')->nullable();
        });
        Schema::table('documents', function (Blueprint $t): void {
            $t->foreignId('activity_id')->nullable()->index()->constrained('collection_activities')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE collection_activities ADD CONSTRAINT visit_purpose_category_check CHECK (visit_purpose_category='visit_purpose' AND (activity_type_code<>'visit' OR visit_purpose_code IS NOT NULL))");
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION rider_proof_link_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.document_type_code='proof_image' AND NOT EXISTS(SELECT 1 FROM collection_activities WHERE id=NEW.activity_id AND task_id=NEW.owner_id AND performed_by=NEW.uploaded_by AND activity_type_code='visit' AND NEW.owner_type='task') THEN RAISE EXCEPTION 'Proof requires matching task visit and uploader' USING ERRCODE='23514'; END IF;
                IF TG_OP='UPDATE' AND NEW.activity_id IS DISTINCT FROM OLD.activity_id THEN RAISE EXCEPTION 'Proof visit link immutable' USING ERRCODE='23514'; END IF;
                RETURN NEW;
            END; $$;
            CREATE TRIGGER rider_proof_link_guard BEFORE INSERT OR UPDATE ON documents FOR EACH ROW EXECUTE FUNCTION rider_proof_link_guard();
            CREATE OR REPLACE FUNCTION submission_manifest_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NOT EXISTS(SELECT 1 FROM task_submissions s JOIN tasks t ON t.id=s.task_id JOIN documents d ON d.id=NEW.document_id
                    WHERE s.id=NEW.submission_id AND t.work_status='in_progress' AND s.task_version=t.lock_version+1
                    AND d.scan_status='clean' AND d.deleted_at IS NULL AND d.uploaded_by=s.submitted_by
                    AND d.image_category_code IS NOT DISTINCT FROM NEW.category_code AND d.owner_type='task' AND d.owner_id=s.task_id
                    AND EXISTS(SELECT 1 FROM collection_activities a WHERE a.id=d.activity_id AND a.task_id=s.task_id AND a.performed_by=s.submitted_by AND a.activity_type_code='visit'))
                THEN RAISE EXCEPTION 'Invalid or already frozen submission proof' USING ERRCODE='23514'; END IF;
                RETURN NEW;
            END; $$;
        SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Approved visit evidence is retained; use a reviewed forward migration.');
    }
};
