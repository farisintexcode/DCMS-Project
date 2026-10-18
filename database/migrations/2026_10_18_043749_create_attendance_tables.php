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
        Schema::create('attendance_policies', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->timestampsTz(6);
            $t->string('code', 64);
            $t->integer('version');
            $t->string('timezone', 64);
            $t->jsonb('rules');
            $t->boolean('is_active')->default(false);
            $t->foreignId('approved_by')->nullable()->index()->constrained('users')->restrictOnDelete();
            $t->timestampTz('approved_at', 6)->nullable();
            $t->unique(['code', 'version']);
        });
        Schema::create('attendance_schedules', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->timestampsTz(6);
            $t->foreignId('rider_id')->constrained('staff')->restrictOnDelete();
            $t->foreignId('policy_id')->index()->constrained('attendance_policies')->restrictOnDelete();
            $t->date('service_date');
            $t->string('day_type', 24);
            $t->timestampTz('scheduled_start_at', 6)->nullable();
            $t->timestampTz('scheduled_end_at', 6)->nullable();
            $t->foreignId('authorized_by')->index()->constrained('users')->restrictOnDelete();
            $t->text('remarks')->nullable();
            $t->integer('lock_version')->default(0);
            $t->unique(['rider_id', 'service_date']);
            $t->index(['service_date', 'day_type']);
        });
        Schema::create('attendances', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->timestampsTz(6);
            $t->foreignId('rider_id')->constrained('staff')->restrictOnDelete();
            $t->foreignId('schedule_id')->nullable()->unique()->constrained('attendance_schedules')->restrictOnDelete();
            $t->date('service_date');
            $t->timestampTz('raw_clock_in_at', 6)->useCurrent();
            $t->timestampTz('raw_clock_out_at', 6)->nullable();
            $t->timestampTz('effective_clock_in_at', 6);
            $t->timestampTz('effective_clock_out_at', 6)->nullable();
            $t->string('status', 24)->default('needs_review');
            $t->integer('worked_minutes')->nullable();
            $t->boolean('is_late')->default(false);
            $t->integer('late_minutes')->default(0);
            $t->text('remarks')->nullable();
            $t->uuid('clock_in_request_id')->unique();
            $t->uuid('clock_out_request_id')->nullable()->unique();
            $t->integer('lock_version')->default(0);
            $t->index(['rider_id', 'service_date']);
            $t->index(['service_date', 'status']);
        });
        Schema::create('attendance_adjustments', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->timestampsTz(6);
            $t->foreignId('attendance_id')->nullable()->index()->constrained('attendances')->restrictOnDelete();
            $t->foreignId('schedule_id')->constrained('attendance_schedules')->restrictOnDelete();
            $t->foreignId('requested_by')->index()->constrained('users')->restrictOnDelete();
            $t->timestampTz('requested_at', 6)->useCurrent();
            $t->jsonb('before_values');
            $t->jsonb('proposed_values');
            $t->text('reason');
            $t->string('status', 24)->default('pending');
            $t->foreignId('reviewed_by')->nullable()->index()->constrained('users')->restrictOnDelete();
            $t->timestampTz('reviewed_at', 6)->nullable();
            $t->text('review_remarks')->nullable();
            $t->index(['schedule_id', 'requested_at']);
            $t->index(['status', 'requested_at']);
        });
        DB::unprepared(<<<'SQL'
            ALTER TABLE attendance_policies ADD CONSTRAINT attendance_policy_values CHECK (version>0 AND (NOT is_active OR (approved_by IS NOT NULL AND approved_at IS NOT NULL)));
            ALTER TABLE attendance_schedules ADD CONSTRAINT attendance_schedule_values CHECK (lock_version>=0 AND day_type IN ('scheduled','leave','day_off','public_holiday','cancelled') AND ((day_type='scheduled' AND scheduled_start_at IS NOT NULL AND scheduled_end_at>scheduled_start_at) OR (day_type<>'scheduled' AND scheduled_start_at IS NULL AND scheduled_end_at IS NULL)));
            ALTER TABLE attendances ADD CONSTRAINT attendance_values CHECK (lock_version>=0 AND status IN ('present','late','half_day','incomplete','needs_review') AND (raw_clock_out_at IS NULL OR raw_clock_out_at>raw_clock_in_at) AND (effective_clock_out_at IS NULL OR effective_clock_out_at>effective_clock_in_at) AND (worked_minutes IS NULL OR worked_minutes>=0) AND late_minutes>=0 AND ((raw_clock_out_at IS NULL)=(clock_out_request_id IS NULL)));
            CREATE UNIQUE INDEX attendance_one_open ON attendances(rider_id) WHERE effective_clock_out_at IS NULL;
            CREATE UNIQUE INDEX attendance_unscheduled_day ON attendances(rider_id,service_date) WHERE schedule_id IS NULL;
            CREATE UNIQUE INDEX attendance_one_pending ON attendance_adjustments(schedule_id) WHERE status='pending';
            ALTER TABLE attendance_adjustments ADD CONSTRAINT attendance_adjustment_values CHECK (length(trim(reason))>0 AND status IN ('pending','approved','rejected') AND (reviewed_by IS NULL OR reviewed_by<>requested_by) AND ((status='pending' AND reviewed_by IS NULL AND reviewed_at IS NULL) OR (status<>'pending' AND reviewed_by IS NOT NULL AND reviewed_at IS NOT NULL)));
            CREATE OR REPLACE FUNCTION attendance_history_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP='DELETE' THEN RAISE EXCEPTION 'Attendance history is retained' USING ERRCODE='23514'; END IF;
                IF TG_TABLE_NAME='attendance_adjustments' THEN
                    IF TG_OP='UPDATE' AND (OLD.status<>'pending' OR NEW.status='pending' OR (to_jsonb(NEW)-ARRAY['status','reviewed_by','reviewed_at','review_remarks','updated_at']) IS DISTINCT FROM (to_jsonb(OLD)-ARRAY['status','reviewed_by','reviewed_at','review_remarks','updated_at'])) THEN RAISE EXCEPTION 'Correction history is immutable' USING ERRCODE='23514'; END IF;
                    IF NEW.attendance_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM attendances a JOIN attendance_schedules s ON s.id=NEW.schedule_id WHERE a.id=NEW.attendance_id AND a.rider_id=s.rider_id AND a.service_date=s.service_date AND (a.schedule_id=s.id OR a.schedule_id IS NULL)) THEN RAISE EXCEPTION 'Correction context mismatch' USING ERRCODE='23514'; END IF;
                END IF;
                IF TG_TABLE_NAME='attendance_schedules' THEN
                    IF TG_OP='UPDATE' AND (NEW.lock_version<>OLD.lock_version+1 OR (NEW.id,NEW.rider_id,NEW.policy_id,NEW.service_date,NEW.authorized_by,NEW.public_id,NEW.created_at) IS DISTINCT FROM (OLD.id,OLD.rider_id,OLD.policy_id,OLD.service_date,OLD.authorized_by,OLD.public_id,OLD.created_at) OR NOT EXISTS(SELECT 1 FROM attendance_adjustments WHERE schedule_id=OLD.id AND status='approved' AND (before_values->>'schedule_version')::integer=OLD.lock_version)) THEN RAISE EXCEPTION 'Calendar requires approved correction' USING ERRCODE='23514'; END IF;
                    IF NOT EXISTS(SELECT 1 FROM attendance_policies WHERE id=NEW.policy_id AND is_active) THEN RAISE EXCEPTION 'Approved policy required' USING ERRCODE='23514'; END IF;
                    IF NEW.day_type='scheduled' AND EXISTS(SELECT 1 FROM attendance_schedules s WHERE s.rider_id=NEW.rider_id AND s.id<>COALESCE(NEW.id,0) AND s.day_type='scheduled' AND s.scheduled_start_at<NEW.scheduled_end_at AND s.scheduled_end_at>NEW.scheduled_start_at) THEN RAISE EXCEPTION 'Overlapping shift' USING ERRCODE='23514'; END IF;
                END IF;
                IF TG_TABLE_NAME='attendances' THEN
                    IF TG_OP='UPDATE' THEN
                        IF (NEW.id,NEW.rider_id,NEW.service_date,NEW.raw_clock_in_at,NEW.clock_in_request_id,NEW.public_id,NEW.remarks,NEW.created_at) IS DISTINCT FROM (OLD.id,OLD.rider_id,OLD.service_date,OLD.raw_clock_in_at,OLD.clock_in_request_id,OLD.public_id,OLD.remarks,OLD.created_at) OR (OLD.raw_clock_out_at IS NOT NULL AND (NEW.raw_clock_out_at,NEW.clock_out_request_id) IS DISTINCT FROM (OLD.raw_clock_out_at,OLD.clock_out_request_id)) OR (OLD.effective_clock_out_at IS NOT NULL AND OLD.raw_clock_out_at IS NULL AND NEW.raw_clock_out_at IS NOT NULL) THEN RAISE EXCEPTION 'Raw punches are immutable' USING ERRCODE='23514'; END IF;
                        IF NEW.lock_version<>OLD.lock_version+1 THEN RAISE EXCEPTION 'Attendance revision must advance' USING ERRCODE='23514'; END IF;
                        IF (NEW.schedule_id,NEW.effective_clock_in_at,NEW.effective_clock_out_at,NEW.status,NEW.worked_minutes,NEW.is_late,NEW.late_minutes) IS DISTINCT FROM (OLD.schedule_id,OLD.effective_clock_in_at,OLD.effective_clock_out_at,OLD.status,OLD.worked_minutes,OLD.is_late,OLD.late_minutes)
                        AND NOT (OLD.raw_clock_out_at IS NULL AND OLD.effective_clock_out_at IS NULL AND NEW.raw_clock_out_at IS NOT NULL AND NEW.effective_clock_out_at=NEW.raw_clock_out_at AND NEW.effective_clock_in_at=OLD.effective_clock_in_at AND NEW.schedule_id IS NOT DISTINCT FROM OLD.schedule_id)
                        AND NOT EXISTS(SELECT 1 FROM attendance_adjustments WHERE attendance_id=OLD.id AND status='approved' AND (before_values->>'attendance_version')::integer=OLD.lock_version) THEN RAISE EXCEPTION 'Effective times require approved correction' USING ERRCODE='23514'; END IF;
                    END IF;
                    IF NEW.schedule_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM attendance_schedules WHERE id=NEW.schedule_id AND rider_id=NEW.rider_id AND service_date=NEW.service_date AND day_type='scheduled') THEN RAISE EXCEPTION 'Attendance schedule mismatch' USING ERRCODE='23514'; END IF;
                END IF;
                RETURN NEW;
            END; $$;
            CREATE TRIGGER attendance_schedule_guard BEFORE INSERT OR UPDATE OR DELETE ON attendance_schedules FOR EACH ROW EXECUTE FUNCTION attendance_history_guard();
            CREATE TRIGGER attendance_raw_guard BEFORE INSERT OR UPDATE OR DELETE ON attendances FOR EACH ROW EXECUTE FUNCTION attendance_history_guard();
            CREATE TRIGGER attendance_adjustment_guard BEFORE INSERT OR UPDATE OR DELETE ON attendance_adjustments FOR EACH ROW EXECUTE FUNCTION attendance_history_guard();
            CREATE TRIGGER attendance_policy_immutable BEFORE UPDATE OR DELETE ON attendance_policies FOR EACH ROW EXECUTE FUNCTION dcms_task_append_guard();
        SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new RuntimeException('Attendance history is retained; use a reviewed forward migration.');
    }
};
