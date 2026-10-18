<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** M06 / Database.md activities, inspections, follow-ups, submissions and immutable proof. */
return new class extends Migration
{
    private array $checks = [];

    public function up(): void
    {
        Schema::create('collection_activities', function (Blueprint $t): void {
            $this->identity($t);
            $t->foreignId('task_id')->nullable()->index()->constrained()->restrictOnDelete();
            $t->foreignId('customer_id')->index()->constrained('debtors')->restrictOnDelete();
            $t->foreignId('performed_by')->index()->constrained('users')->restrictOnDelete();
            $t->foreignId('contact_id')->nullable()->index()->constrained()->restrictOnDelete();
            $t->unsignedBigInteger('communication_id')->nullable();
            foreach (['activity_type' => 'activity_type', 'outcome' => 'activity_outcome', 'availability' => 'availability', 'visit_status' => 'visit_status', 'contact_result' => 'contact_result'] as $name => $category) {
                $this->lookup($t, $name, $category, $name !== 'activity_type');
            }
            $t->timestampTz('occurred_at', 6);
            $t->timestampTz('ended_at', 6)->nullable();
            foreach (['notes', 'tps_contact', 'person_contacted', 'site_address', 'visit_remarks', 'collection_remarks', 'customer_remarks'] as $field) {
                $t->text($field.'_ciphertext')->nullable();
            }
            $t->string('tps_source', 160)->nullable();
            $t->string('source_agent_name', 160)->nullable();
            $t->uuid('idempotency_key')->unique();
            $t->index(['task_id', 'occurred_at']);
            $t->index(['performed_by', 'occurred_at']);
            $t->index(['activity_type_code', 'occurred_at']);
        });
        Schema::create('property_inspections', function (Blueprint $t): void {
            $this->identity($t);
            $t->foreignId('task_id')->index()->constrained()->restrictOnDelete();
            $t->foreignId('activity_id')->nullable()->index()->constrained('collection_activities')->restrictOnDelete();
            $t->foreignId('property_id')->nullable()->index()->constrained()->restrictOnDelete();
            $t->timestampTz('observed_at', 6);
            foreach (['occupancy' => 'occupancy', 'usage' => 'property_usage', 'property_type' => 'property_type', 'black_area' => 'black_area', 'business_type' => 'business_type', 'business_nature' => 'business_nature', 'business_status' => 'business_status'] as $name => $category) {
                $this->lookup($t, $name, $category);
            }
            foreach (['occupancy_other', 'usage_other', 'shop_company_ciphertext', 'customer_reason_ciphertext', 'business_other_ciphertext'] as $field) {
                $t->text($field)->nullable();
            }
            $t->string('current_class', 64)->nullable();
            $t->string('meter_number', 100)->nullable();
            $t->string('nature_of_business', 200)->nullable();
            $t->boolean('meter_connected')->nullable();
            $t->boolean('business_closed')->nullable();
            $t->jsonb('source_values')->default('{}');
            $t->index(['task_id', 'observed_at']);
            $t->index(['property_id', 'observed_at']);
        });
        Schema::create('follow_ups', function (Blueprint $t): void {
            $this->identity($t);
            $t->foreignId('task_id')->nullable()->index()->constrained()->restrictOnDelete();
            $t->foreignId('activity_id')->nullable()->index()->constrained('collection_activities')->restrictOnDelete();
            $t->foreignId('customer_id')->index()->constrained('debtors')->restrictOnDelete();
            $t->foreignId('assigned_to')->constrained('users')->restrictOnDelete();
            $t->unsignedBigInteger('communication_id')->nullable();
            $t->timestampTz('due_at', 6);
            $this->lookup($t, 'type', 'follow_up_type', false);
            $t->string('status', 24)->default('pending');
            $t->text('notes_ciphertext')->nullable();
            $t->timestampTz('completed_at', 6)->nullable();
            $t->index(['assigned_to', 'status', 'due_at']);
            $t->index(['customer_id', 'status', 'due_at']);
            $t->index(['task_id', 'due_at']);
        });
        Schema::create('task_submissions', function (Blueprint $t): void {
            $this->identity($t);
            $t->foreignId('task_id')->constrained()->restrictOnDelete();
            $t->foreignId('submitted_by')->constrained('users')->restrictOnDelete();
            $t->timestampTz('submitted_at', 6);
            $t->integer('submission_version');
            $t->integer('task_version');
            $t->text('completion_snapshot_ciphertext');
            $t->uuid('idempotency_key')->unique();
            $t->unique(['task_id', 'submission_version']);
            $t->index(['submitted_by', 'submitted_at']);
        });
        Schema::create('task_submission_documents', function (Blueprint $t): void {
            $this->identity($t);
            $t->foreignId('submission_id')->constrained('task_submissions')->restrictOnDelete();
            $t->foreignId('document_id')->index()->constrained('documents')->restrictOnDelete();
            $t->string('category_code', 64)->nullable();
            $t->unique(['submission_id', 'document_id']);
        });
        foreach ($this->checks as [$table,$name,$category]) {
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_{$name}_category_check CHECK ({$name}_category='{$category}')");
        }
        DB::statement('ALTER TABLE task_status_histories DROP CONSTRAINT task_status_histories_values');
        DB::statement("ALTER TABLE task_status_histories ADD CONSTRAINT task_status_histories_values CHECK (to_work_status IN ('unstarted','in_progress','submitted','completed','cancelled') AND to_review_status IN ('none','pending','approved','returned'))");
        Schema::table('task_status_histories', function (Blueprint $t): void {
            $t->foreign('submission_id')->references('id')->on('task_submissions')->restrictOnDelete();
            $t->index('submission_id');
        });
        DB::unprepared(<<<'SQL'
            ALTER TABLE collection_activities ADD CONSTRAINT activity_times CHECK ((ended_at IS NULL OR ended_at>=occurred_at) AND communication_id IS NULL);
            ALTER TABLE follow_ups ADD CONSTRAINT follow_up_status CHECK (status IN ('pending','done','cancelled') AND communication_id IS NULL);
            ALTER TABLE task_submissions ADD CONSTRAINT positive_submission_version CHECK (submission_version>0 AND task_version>0);
            CREATE OR REPLACE FUNCTION rider_context_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_TABLE_NAME='collection_activities' THEN
                    IF NEW.task_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM tasks WHERE id=NEW.task_id AND customer_id=NEW.customer_id) THEN RAISE EXCEPTION 'Activity task context mismatch' USING ERRCODE='23514'; END IF;
                    IF NEW.contact_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM contacts WHERE id=NEW.contact_id AND debtor_id=NEW.customer_id) THEN RAISE EXCEPTION 'Contact context mismatch' USING ERRCODE='23514'; END IF;
                    IF NEW.activity_type_code='visit' AND NEW.task_id IS NULL THEN RAISE EXCEPTION 'Visit requires task' USING ERRCODE='23514'; END IF;
                ELSIF TG_TABLE_NAME='property_inspections' THEN
                    IF NEW.activity_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM collection_activities WHERE id=NEW.activity_id AND task_id=NEW.task_id AND activity_type_code='visit') THEN RAISE EXCEPTION 'Inspection activity mismatch' USING ERRCODE='23514'; END IF;
                    IF NEW.property_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM tasks t JOIN debt_accounts a ON a.id=t.debt_account_id WHERE t.id=NEW.task_id AND a.property_id=NEW.property_id) THEN RAISE EXCEPTION 'Inspection property mismatch' USING ERRCODE='23514'; END IF;
                ELSIF TG_TABLE_NAME='follow_ups' THEN
                    IF NEW.task_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM tasks WHERE id=NEW.task_id AND customer_id=NEW.customer_id) THEN RAISE EXCEPTION 'Follow-up context mismatch' USING ERRCODE='23514'; END IF;
                    IF NEW.activity_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM collection_activities WHERE id=NEW.activity_id AND task_id IS NOT DISTINCT FROM NEW.task_id AND customer_id=NEW.customer_id) THEN RAISE EXCEPTION 'Follow-up activity mismatch' USING ERRCODE='23514'; END IF;
                END IF;
                RETURN NEW;
            END; $$;
            CREATE TRIGGER rider_activity_context BEFORE INSERT ON collection_activities FOR EACH ROW EXECUTE FUNCTION rider_context_guard();
            CREATE TRIGGER rider_inspection_context BEFORE INSERT ON property_inspections FOR EACH ROW EXECUTE FUNCTION rider_context_guard();
            CREATE TRIGGER rider_follow_up_context BEFORE INSERT OR UPDATE ON follow_ups FOR EACH ROW EXECUTE FUNCTION rider_context_guard();
            CREATE TRIGGER activity_immutable BEFORE UPDATE OR DELETE ON collection_activities FOR EACH ROW EXECUTE FUNCTION dcms_task_append_guard();
            CREATE TRIGGER inspection_immutable BEFORE UPDATE OR DELETE ON property_inspections FOR EACH ROW EXECUTE FUNCTION dcms_task_append_guard();
            CREATE TRIGGER submission_immutable BEFORE UPDATE OR DELETE ON task_submissions FOR EACH ROW EXECUTE FUNCTION dcms_task_append_guard();
            CREATE TRIGGER submission_document_immutable BEFORE UPDATE OR DELETE ON task_submission_documents FOR EACH ROW EXECUTE FUNCTION dcms_task_append_guard();
            CREATE OR REPLACE FUNCTION import_document_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.owner_type='import' AND NOT EXISTS(SELECT 1 FROM import_batches WHERE id=NEW.owner_id) THEN RAISE EXCEPTION 'Document owner missing' USING ERRCODE='23514'; END IF;
                IF NEW.owner_type='user' AND NOT EXISTS(SELECT 1 FROM users WHERE id=NEW.owner_id) THEN RAISE EXCEPTION 'Document owner missing' USING ERRCODE='23514'; END IF;
                IF NEW.owner_type='task' AND NOT EXISTS(SELECT 1 FROM tasks WHERE id=NEW.owner_id) THEN RAISE EXCEPTION 'Document owner missing' USING ERRCODE='23514'; END IF;
                IF NEW.owner_type='activity' AND NOT EXISTS(SELECT 1 FROM collection_activities WHERE id=NEW.owner_id) THEN RAISE EXCEPTION 'Document owner missing' USING ERRCODE='23514'; END IF;
                IF NEW.owner_type NOT IN ('import','user','task','activity') THEN RAISE EXCEPTION 'Document owner module unavailable' USING ERRCODE='23514'; END IF;
                IF TG_OP='UPDATE' AND (NEW.owner_type,NEW.owner_id,NEW.bucket,NEW.object_key,NEW.version_id,NEW.disk,NEW.sha256,NEW.size_bytes,NEW.uploaded_by,NEW.original_filename,NEW.mime_type) IS DISTINCT FROM (OLD.owner_type,OLD.owner_id,OLD.bucket,OLD.object_key,OLD.version_id,OLD.disk,OLD.sha256,OLD.size_bytes,OLD.uploaded_by,OLD.original_filename,OLD.mime_type) THEN RAISE EXCEPTION 'Document source immutable' USING ERRCODE='23514'; END IF;
                IF TG_OP='UPDATE' AND EXISTS(SELECT 1 FROM task_submission_documents WHERE document_id=OLD.id) AND NEW IS DISTINCT FROM OLD THEN RAISE EXCEPTION 'Submitted proof immutable' USING ERRCODE='23514'; END IF;
                RETURN NEW;
            END; $$;
            CREATE OR REPLACE FUNCTION submission_manifest_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NOT EXISTS(SELECT 1 FROM task_submissions s JOIN documents d ON d.id=NEW.document_id WHERE s.id=NEW.submission_id AND d.scan_status='clean' AND d.deleted_at IS NULL AND d.image_category_code IS NOT DISTINCT FROM NEW.category_code AND ((d.owner_type='task' AND d.owner_id=s.task_id) OR (d.owner_type='activity' AND EXISTS(SELECT 1 FROM collection_activities a WHERE a.id=d.owner_id AND a.task_id=s.task_id)))) THEN RAISE EXCEPTION 'Invalid submission proof' USING ERRCODE='23514'; END IF;
                RETURN NEW;
            END; $$;
            CREATE TRIGGER submission_manifest_guard BEFORE INSERT ON task_submission_documents FOR EACH ROW EXECUTE FUNCTION submission_manifest_guard();
        SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('M06 stores immutable evidence. Use a reviewed forward migration.');
    }

    private function identity(Blueprint $t): void
    {
        $t->id();
        $t->uuid('public_id')->unique();
        $t->timestampsTz(6);
    }

    private function lookup(Blueprint $t, string $name, string $category, bool $nullable = true): void
    {
        $t->string($name.'_category', 50)->default($category);
        $column = $t->string($name.'_code', 64);
        if ($nullable) {
            $column->nullable();
        }
        $t->foreign([$name.'_category', $name.'_code'])->references(['category', 'code'])->on('reference_values')->restrictOnDelete();
        $this->checks[] = [$t->getTable(), $name, $category];
    }
};
