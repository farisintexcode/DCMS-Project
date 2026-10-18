<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** M05: confirmed selections cannot expand; future import targets now have their real foreign keys. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_rows', function (Blueprint $t): void {
            $t->foreign('target_task_id')->references('id')->on('tasks')->restrictOnDelete();
            $t->foreign('target_account_id')->references('id')->on('debt_accounts')->restrictOnDelete();
        });
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION dcms_bulk_item_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE operation bulk_operations;
            BEGIN
                SELECT * INTO operation FROM bulk_operations WHERE id=COALESCE(NEW.bulk_operation_id,OLD.bulk_operation_id);
                IF TG_OP='INSERT' AND operation.status<>'previewed' THEN RAISE EXCEPTION 'Confirmed selection immutable' USING ERRCODE='23514'; END IF;
                IF TG_OP='DELETE' THEN RAISE EXCEPTION 'Operation selection history retained' USING ERRCODE='23514'; END IF;
                IF (SELECT assignment_batch_id FROM tasks WHERE id=NEW.task_id)<>operation.assignment_batch_id THEN RAISE EXCEPTION 'Bulk task batch mismatch' USING ERRCODE='23514'; END IF;
                IF TG_OP='UPDATE' AND ((NEW.task_id,NEW.bulk_operation_id,NEW.expected_version) IS DISTINCT FROM (OLD.task_id,OLD.bulk_operation_id,OLD.expected_version) OR OLD.state='applied') THEN RAISE EXCEPTION 'Operation selection/result immutable' USING ERRCODE='23514'; END IF;
                RETURN NEW;
            END; $$;
            DROP TRIGGER bulk_item_guard ON bulk_operation_items;
            CREATE TRIGGER bulk_item_guard BEFORE INSERT OR UPDATE OR DELETE ON bulk_operation_items FOR EACH ROW EXECUTE FUNCTION dcms_bulk_item_guard();
        SQL);
    }

    public function down(): void
    {
        Schema::table('import_rows', function (Blueprint $t): void {
            $t->dropForeign(['target_task_id']);
            $t->dropForeign(['target_account_id']);
        });
        DB::unprepared(<<<'SQL'
            DROP TRIGGER bulk_item_guard ON bulk_operation_items;
            CREATE OR REPLACE FUNCTION dcms_bulk_item_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF (SELECT assignment_batch_id FROM tasks WHERE id=NEW.task_id)<>(SELECT assignment_batch_id FROM bulk_operations WHERE id=NEW.bulk_operation_id) THEN RAISE EXCEPTION 'Bulk task batch mismatch' USING ERRCODE='23514'; END IF;
                IF TG_OP='UPDATE' AND (NEW.task_id,NEW.bulk_operation_id,NEW.expected_version) IS DISTINCT FROM (OLD.task_id,OLD.bulk_operation_id,OLD.expected_version) THEN RAISE EXCEPTION 'Bulk selection immutable' USING ERRCODE='23514'; END IF;
                RETURN NEW;
            END; $$;
            CREATE TRIGGER bulk_item_guard BEFORE INSERT OR UPDATE ON bulk_operation_items FOR EACH ROW EXECUTE FUNCTION dcms_bulk_item_guard();
        SQL);
    }
};
