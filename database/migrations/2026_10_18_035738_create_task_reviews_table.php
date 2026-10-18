<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** M07 / Database.md task_reviews; immutable independent decisions, exact submission context. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_reviews', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->timestampsTz(6);
            $t->foreignId('task_id')->constrained()->restrictOnDelete();
            $t->foreignId('reviewer_id')->constrained('users')->restrictOnDelete();
            $t->foreignId('submission_id')->unique()->constrained('task_submissions')->restrictOnDelete();
            $t->integer('submission_version');
            $t->string('decision', 24);
            $t->text('reason')->nullable();
            $t->timestampTz('reviewed_at', 6);
            $t->foreignId('supersedes_review_id')->nullable()->index()->constrained('task_reviews')->restrictOnDelete();
            $t->unique(['task_id', 'submission_version']);
            $t->index(['reviewer_id', 'reviewed_at']);
        });
        DB::unprepared(<<<'SQL'
            ALTER TABLE task_reviews ADD CONSTRAINT task_review_values CHECK (submission_version>0 AND decision IN ('approved','returned') AND (decision<>'returned' OR (reason IS NOT NULL AND length(trim(reason))>0)));
            CREATE OR REPLACE FUNCTION task_review_context_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NOT EXISTS(SELECT 1 FROM task_submissions s JOIN tasks t ON t.id=s.task_id
                    WHERE s.id=NEW.submission_id AND s.task_id=NEW.task_id AND s.submission_version=NEW.submission_version
                    AND s.submitted_by<>NEW.reviewer_id AND t.work_status='submitted' AND t.review_status='pending' AND t.lock_version=s.task_version
                    AND NOT EXISTS(SELECT 1 FROM task_submissions newer WHERE newer.task_id=t.id AND newer.submission_version>s.submission_version))
                THEN RAISE EXCEPTION 'Review requires independent current submitted work' USING ERRCODE='23514'; END IF;
                IF NEW.supersedes_review_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM task_reviews WHERE id=NEW.supersedes_review_id AND task_id=NEW.task_id AND submission_version<NEW.submission_version)
                THEN RAISE EXCEPTION 'Previous review context mismatch' USING ERRCODE='23514'; END IF;
                RETURN NEW;
            END; $$;
            CREATE TRIGGER task_review_context_guard BEFORE INSERT ON task_reviews FOR EACH ROW EXECUTE FUNCTION task_review_context_guard();
            CREATE TRIGGER task_review_immutable BEFORE UPDATE OR DELETE ON task_reviews FOR EACH ROW EXECUTE FUNCTION dcms_task_append_guard();
        SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Verification decisions are immutable business history; use a reviewed forward migration.');
    }
};
