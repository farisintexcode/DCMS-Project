<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('export_jobs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('assignment_batch_id')->nullable()->constrained('assignment_batches')->restrictOnDelete();
            $table->string('kind', 32);
            $table->string('report_family', 40);
            $table->jsonb('criteria');
            $table->timestampTz('snapshot_at');
            $table->text('snapshot_ciphertext');
            $table->char('snapshot_digest', 64);
            $table->string('status', 24)->default('queued');
            $table->bigInteger('row_count')->default(0);
            $table->integer('missing_image_count')->default(0);
            $table->foreignId('artifact_document_id')->nullable()->unique()->constrained('documents')->restrictOnDelete();
            $table->timestampTz('expires_at');
            $table->text('error_summary')->nullable();
            $table->timestampsTz();
            $table->index(['requested_by', 'created_at']);
            $table->index(['status', 'created_at']);
        });
        DB::statement("ALTER TABLE export_jobs ADD CONSTRAINT export_jobs_values CHECK (row_count >= 0 AND missing_image_count >= 0 AND kind IN ('csv','pdf') AND status IN ('queued','running','completed','failed','expired','cancelled') AND report_family IN ('task','rider_assignment','rider_performance','completed_task','verification'))");
        foreach (['csv', 'pdf'] as $format) {
            DB::table('reference_values')->insertOrIgnore(['public_id' => (string) Str::uuid7(), 'category' => 'document_type', 'code' => 'report_'.$format, 'label' => 'Generated report '.strtoupper($format), 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $definition = DB::selectOne("SELECT pg_get_functiondef('import_document_guard()'::regprocedure) AS definition")->definition;
        $definition = str_replace("IF NEW.owner_type NOT IN ('import','user','task','activity','communication')", "IF NEW.owner_type='export' AND NOT EXISTS(SELECT 1 FROM export_jobs WHERE id=NEW.owner_id AND status='running' AND requested_by=NEW.uploaded_by AND NEW.document_type_code='report_'||kind AND NEW.scan_status='clean' AND NEW.disk='s3' AND NEW.activity_id IS NULL AND ((kind='csv' AND NEW.mime_type='text/csv') OR (kind='pdf' AND NEW.mime_type='application/pdf'))) THEN RAISE EXCEPTION 'Report document context unavailable' USING ERRCODE='23514'; END IF; IF NEW.owner_type NOT IN ('import','user','task','activity','communication','export')", $definition);
        DB::unprepared($definition);
        DB::unprepared(<<<'SQL'
            CREATE UNIQUE INDEX report_document_unique ON documents(owner_id) WHERE owner_type='export';
            CREATE OR REPLACE FUNCTION export_job_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP='UPDATE' AND (NEW.requested_by,NEW.kind,NEW.report_family,NEW.criteria,NEW.snapshot_at,NEW.snapshot_ciphertext,NEW.snapshot_digest,NEW.row_count,NEW.expires_at) IS DISTINCT FROM (OLD.requested_by,OLD.kind,OLD.report_family,OLD.criteria,OLD.snapshot_at,OLD.snapshot_ciphertext,OLD.snapshot_digest,OLD.row_count,OLD.expires_at) THEN RAISE EXCEPTION 'Report snapshot is immutable' USING ERRCODE='23514'; END IF;
                IF TG_OP='UPDATE' AND OLD.status IN ('completed','cancelled','expired') AND NEW IS DISTINCT FROM OLD THEN RAISE EXCEPTION 'Terminal report is immutable' USING ERRCODE='23514'; END IF;
                IF NEW.status='completed' AND NOT EXISTS(SELECT 1 FROM documents WHERE id=NEW.artifact_document_id AND owner_type='export' AND owner_id=NEW.id AND scan_status='clean' AND deleted_at IS NULL) THEN RAISE EXCEPTION 'Report artifact required' USING ERRCODE='23514'; END IF;
                RETURN NEW;
            END; $$;
            CREATE TRIGGER export_job_guard BEFORE INSERT OR UPDATE ON export_jobs FOR EACH ROW EXECUTE FUNCTION export_job_guard();
        SQL);

    }

    public function down(): void
    {
        throw new RuntimeException('Report evidence requires a reviewed forward migration.');
    }
};
