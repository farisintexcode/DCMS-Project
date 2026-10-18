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
        Schema::table('collection_activities', function (Blueprint $t): void {
            $t->text('call_details_ciphertext')->nullable();
            $t->index(['customer_id', 'occurred_at', 'id'], 'activity_customer_timeline');
        });
        Schema::table('follow_ups', function (Blueprint $t): void {
            $t->integer('lock_version')->default(0);
            $t->foreignId('resolved_by')->nullable()->index()->constrained('users')->restrictOnDelete();
            $t->timestampTz('resolved_at', 6)->nullable();
            $t->text('resolution_ciphertext')->nullable();
        });
        DB::unprepared(<<<'SQL'
            ALTER TABLE follow_ups ADD CONSTRAINT follow_up_resolution CHECK (lock_version>=0 AND ((resolved_by IS NULL AND resolved_at IS NULL AND resolution_ciphertext IS NULL) OR (resolved_by IS NOT NULL AND resolved_at IS NOT NULL AND resolution_ciphertext IS NOT NULL AND status IN ('done','cancelled'))));
            CREATE OR REPLACE FUNCTION call_follow_up_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP='DELETE' THEN
                    IF EXISTS(SELECT 1 FROM collection_activities WHERE id=OLD.activity_id AND activity_type_code='call') THEN RAISE EXCEPTION 'Call reminder history is retained' USING ERRCODE='23514'; END IF;
                    RETURN OLD;
                END IF;
                IF EXISTS(SELECT 1 FROM collection_activities WHERE id=NEW.activity_id AND activity_type_code='call') OR (TG_OP='UPDATE' AND EXISTS(SELECT 1 FROM collection_activities WHERE id=OLD.activity_id AND activity_type_code='call')) THEN
                    IF TG_OP='INSERT' THEN
                        IF NEW.status<>'pending' OR NEW.resolved_by IS NOT NULL OR NEW.completed_at IS NOT NULL OR NEW.lock_version<>0 THEN RAISE EXCEPTION 'New call reminder must be pending' USING ERRCODE='23514'; END IF;
                    ELSE
                        IF OLD.status<>'pending' OR NEW.status NOT IN ('done','cancelled') OR NEW.resolved_by IS DISTINCT FROM OLD.assigned_to OR NEW.resolved_at IS NULL OR NEW.resolution_ciphertext IS NULL OR NEW.lock_version<>OLD.lock_version+1
                            OR (NEW.status='done' AND NEW.completed_at IS DISTINCT FROM NEW.resolved_at) OR (NEW.status='cancelled' AND NEW.completed_at IS NOT NULL)
                            OR (to_jsonb(NEW)-ARRAY['status','completed_at','resolved_by','resolved_at','resolution_ciphertext','lock_version','updated_at']) IS DISTINCT FROM (to_jsonb(OLD)-ARRAY['status','completed_at','resolved_by','resolved_at','resolution_ciphertext','lock_version','updated_at'])
                        THEN RAISE EXCEPTION 'Call reminder context and decisions are immutable' USING ERRCODE='23514'; END IF;
                    END IF;
                END IF;
                RETURN NEW;
            END; $$;
            CREATE TRIGGER call_follow_up_guard BEFORE INSERT OR UPDATE OR DELETE ON follow_ups FOR EACH ROW EXECUTE FUNCTION call_follow_up_guard();
        SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new RuntimeException('Call and reminder decision history is retained; use a reviewed forward migration.');
    }
};
