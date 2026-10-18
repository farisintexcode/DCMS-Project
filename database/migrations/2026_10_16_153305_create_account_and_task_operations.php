<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Plan.md task contracts / M05 / UI16–19,55–56 / Database.md canonical accounts/tasks/allocation. */
return new class extends Migration
{
    private function identity(Blueprint $t): void
    {
        $t->id();
        $t->uuid('public_id')->unique();
        $t->timestampsTz(6);
    }

    public function up(): void
    {
        Schema::create('properties', function (Blueprint $t): void {
            $this->identity($t);
            $t->foreignId('client_id')->constrained()->restrictOnDelete();
            $t->string('external_key', 100)->nullable();
            $t->text('address_lines_ciphertext')->nullable();
            foreach (['building' => 200, 'building_external_id' => 100, 'road_name' => 200, 'taman' => 200, 'postcode' => 20, 'suburb' => 160] as $field => $size) {
                $t->string($field, $size)->nullable();
            }
            $t->string('state_category', 50)->default('state');
            $t->string('state_code', 64)->nullable();
            $t->foreign(['state_category', 'state_code'])->references(['category', 'code'])->on('reference_values')->restrictOnDelete();
            $t->index(['client_id', 'postcode']);
        });
        Schema::create('debt_accounts', function (Blueprint $t): void {
            $this->identity($t);
            $t->foreignId('client_id')->constrained()->restrictOnDelete();
            $t->foreignId('property_id')->nullable()->index()->constrained()->restrictOnDelete();
            $t->string('account_number', 100);
            $t->string('billing_reference', 160)->nullable();
            $t->char('currency', 3)->default('MYR');
            $t->string('status', 24)->default('active');
            $t->unique(['client_id', 'account_number']);
            $t->index(['client_id', 'status']);
        });
        Schema::create('account_parties', function (Blueprint $t): void {
            $this->identity($t);
            $t->foreignId('debt_account_id')->constrained()->restrictOnDelete();
            $t->foreignId('debtor_id')->index()->constrained()->restrictOnDelete();
            $t->string('role_category', 50)->default('party_role');
            $t->string('role_code', 32);
            $t->string('source_slot', 32)->nullable();
            $t->timestampTz('valid_from', 6)->useCurrent();
            $t->timestampTz('valid_to', 6)->nullable();
            $t->unsignedBigInteger('source_task_id')->nullable();
            $t->index(['debt_account_id', 'role_code', 'valid_to']);
            $t->foreign(['role_category', 'role_code'])->references(['category', 'code'])->on('reference_values')->restrictOnDelete();
        });
        Schema::create('tasks', function (Blueprint $t): void {
            $this->identity($t);
            $t->foreignId('assignment_batch_id')->constrained()->restrictOnDelete();
            $t->foreignId('debt_account_id')->constrained()->restrictOnDelete();
            $t->foreignId('customer_id')->constrained('debtors')->restrictOnDelete();
            $t->foreignId('created_by')->index()->constrained('users')->restrictOnDelete();
            $t->foreignId('source_import_row_id')->nullable()->constrained('import_rows')->restrictOnDelete();
            $t->string('source_iwk_id', 100)->nullable();
            $t->string('type_category', 50)->default('assignment_type');
            $t->string('type_code', 64);
            $t->string('priority_category', 50)->default('priority');
            $t->string('priority_code', 64)->default('normal');
            $t->string('work_status', 24)->default('unstarted');
            $t->string('review_status', 24)->default('none');
            $t->string('origin_code', 24)->default('manual');
            $t->boolean('payment_update_flag')->default(false);
            $t->text('source_snapshot_ciphertext');
            $t->integer('snapshot_schema_version')->default(1);
            $t->boolean('excluded')->default(false);
            foreach (['special_instruction_ciphertext', 'task_remarks_ciphertext', 'admin_remarks_ciphertext', 'uncomplete_reason'] as $field) {
                $t->text($field)->nullable();
            }
            foreach (['range_due_code' => 100, 'cost_state' => 80, 'uo' => 50, 'bill_number' => 160] as $field => $size) {
                $t->string($field, $size)->nullable();
            }
            $t->foreignId('last_updated_by')->nullable()->index()->constrained('users')->restrictOnDelete();
            foreach (['scheduled_visit_at', 'due_at', 'submitted_at', 'completed_at', 'cancelled_at'] as $field) {
                $t->timestampTz($field, 6)->nullable();
            }
            $t->integer('lock_version')->default(0);
            $t->foreign(['type_category', 'type_code'])->references(['category', 'code'])->on('reference_values')->restrictOnDelete();
            $t->foreign(['priority_category', 'priority_code'])->references(['category', 'code'])->on('reference_values')->restrictOnDelete();
            $t->index(['assignment_batch_id', 'work_status', 'id']);
            $t->index(['assignment_batch_id', 'review_status']);
            $t->index(['debt_account_id', 'created_at']);
            $t->index(['customer_id', 'work_status', 'due_at']);
            $t->index(['priority_code', 'due_at']);
            $t->index('scheduled_visit_at');
        });
        Schema::table('account_parties', fn (Blueprint $t) => $t->foreign('source_task_id')->references('id')->on('tasks')->restrictOnDelete());
        Schema::create('bulk_operations', function (Blueprint $t): void {
            $this->identity($t);
            $t->foreignId('requested_by')->index()->constrained('users')->restrictOnDelete();
            $t->foreignId('assignment_batch_id')->constrained()->restrictOnDelete();
            $t->string('operation_code', 32);
            $t->foreignId('target_collector_id')->nullable()->index()->constrained('staff')->restrictOnDelete();
            $t->string('selection_mode', 24);
            $t->string('from_iwk_id', 100)->nullable();
            $t->string('to_iwk_id', 100)->nullable();
            $t->text('reason');
            $t->char('preview_digest', 64);
            $t->uuid('idempotency_key')->unique();
            $t->string('status', 24)->default('previewed');
            $t->jsonb('result_counts')->default('{}');
            $t->index(['assignment_batch_id', 'created_at']);
            $t->index(['status', 'created_at']);
        });
        Schema::create('bulk_operation_items', function (Blueprint $t): void {
            $this->identity($t);
            $t->foreignId('bulk_operation_id')->index()->constrained()->restrictOnDelete();
            $t->foreignId('task_id')->constrained()->restrictOnDelete();
            $t->integer('expected_version');
            $t->string('state', 24)->default('pending');
            $t->string('result_code', 80)->nullable();
            $t->unique(['bulk_operation_id', 'task_id']);
            $t->index(['bulk_operation_id', 'state']);
        });
        Schema::create('task_allocations', function (Blueprint $t): void {
            $this->identity($t);
            $t->foreignId('task_id')->constrained()->restrictOnDelete();
            $t->foreignId('collector_id')->index()->constrained('staff')->restrictOnDelete();
            $t->foreignId('assigned_by')->index()->constrained('users')->restrictOnDelete();
            $t->string('operation_code', 32);
            $t->timestampTz('assigned_at', 6)->useCurrent();
            $t->timestampTz('released_at', 6)->nullable();
            $t->text('release_reason')->nullable();
            $t->foreignId('bulk_operation_id')->nullable()->index()->constrained()->restrictOnDelete();
            $t->index(['collector_id', 'released_at', 'task_id']);
        });
        Schema::create('task_status_histories', function (Blueprint $t): void {
            $this->identity($t);
            $t->foreignId('task_id')->constrained()->restrictOnDelete();
            $t->foreignId('actor_id')->index()->constrained('users')->restrictOnDelete();
            $t->string('event_code', 40);
            $t->string('from_work_status', 24)->nullable();
            $t->string('to_work_status', 24);
            $t->string('from_review_status', 24)->nullable();
            $t->string('to_review_status', 24);
            $t->unsignedBigInteger('submission_id')->nullable();
            $t->foreignId('allocation_id')->nullable()->index()->constrained('task_allocations')->restrictOnDelete();
            $t->text('reason')->nullable();
            $t->timestampTz('occurred_at', 6)->useCurrent();
            $t->index(['task_id', 'occurred_at', 'id']);
        });
        DB::statement('CREATE UNIQUE INDEX property_external_key_unique ON properties (client_id,external_key) WHERE external_key IS NOT NULL');
        DB::statement("CREATE UNIQUE INDEX current_account_party_unique ON account_parties (debt_account_id,debtor_id,role_code,COALESCE(source_slot,'')) WHERE valid_to IS NULL");
        DB::statement('CREATE UNIQUE INDEX task_source_unique ON tasks (assignment_batch_id,source_iwk_id) WHERE source_iwk_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX task_import_unique ON tasks (source_import_row_id) WHERE source_import_row_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX task_current_allocation_unique ON task_allocations (task_id) WHERE released_at IS NULL');
        foreach ([
            'properties' => "state_category='state'",
            'debt_accounts' => "status IN ('active','closed','disputed') AND currency ~ '^[A-Z]{3}$' AND length(trim(account_number))>0",
            'account_parties' => "role_category='party_role' AND (valid_to IS NULL OR valid_to>=valid_from)",
            'tasks' => "type_category='assignment_type' AND priority_category='priority' AND lock_version>=0 AND snapshot_schema_version>0 AND origin_code IN ('manual','import') AND (origin_code<>'manual' OR (source_iwk_id IS NULL AND source_import_row_id IS NULL)) AND (origin_code<>'import' OR source_import_row_id IS NOT NULL) AND work_status IN ('unstarted','in_progress','submitted','completed','cancelled') AND review_status IN ('none','pending','approved','returned') AND ((work_status='submitted')=(review_status='pending')) AND (work_status<>'completed' OR review_status='approved') AND (review_status<>'approved' OR work_status='completed') AND (review_status<>'returned' OR work_status='in_progress') AND (due_at IS NULL OR scheduled_visit_at IS NULL OR due_at>=scheduled_visit_at)",
            'task_allocations' => "operation_code IN ('new','replace','reassign') AND (released_at IS NULL OR released_at>=assigned_at)",
            'bulk_operations' => "operation_code IN ('new','replace','reassign','unassign','cancel') AND selection_mode IN ('single','selected','range') AND ((selection_mode='range' AND from_iwk_id IS NOT NULL AND to_iwk_id IS NOT NULL) OR (selection_mode<>'range' AND from_iwk_id IS NULL AND to_iwk_id IS NULL)) AND status IN ('previewed','queued','running','completed','failed','cancelled') AND (operation_code IN ('unassign','cancel') OR target_collector_id IS NOT NULL) AND length(trim(reason))>0",
            'bulk_operation_items' => "expected_version>=0 AND state IN ('pending','applied','excluded','failed')",
            'task_status_histories' => "submission_id IS NULL AND to_work_status IN ('unstarted','in_progress','submitted','completed','cancelled') AND to_review_status IN ('none','pending','approved','returned')",
        ] as $table => $check) {
            DB::statement("ALTER TABLE $table ADD CONSTRAINT {$table}_values CHECK ($check)");
        }
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION dcms_account_scope_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.property_id IS NOT NULL AND NEW.client_id<>(SELECT client_id FROM properties WHERE id=NEW.property_id) THEN RAISE EXCEPTION 'Account/property scope mismatch' USING ERRCODE='23514'; END IF;
                IF TG_OP='UPDATE' AND NEW.client_id<>OLD.client_id THEN RAISE EXCEPTION 'Account client immutable' USING ERRCODE='23514'; END IF;
                RETURN NEW;
            END; $$;
            CREATE TRIGGER account_scope_guard BEFORE INSERT OR UPDATE ON debt_accounts FOR EACH ROW EXECUTE FUNCTION dcms_account_scope_guard();
            CREATE OR REPLACE FUNCTION dcms_party_scope_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF (SELECT client_id FROM debt_accounts WHERE id=NEW.debt_account_id)<>(SELECT client_id FROM debtors WHERE id=NEW.debtor_id) THEN RAISE EXCEPTION 'Party client mismatch' USING ERRCODE='23514'; END IF;
                IF TG_OP='UPDATE' AND (to_jsonb(NEW)-'valid_to'-'updated_at') IS DISTINCT FROM (to_jsonb(OLD)-'valid_to'-'updated_at') THEN RAISE EXCEPTION 'Party observation immutable' USING ERRCODE='23514'; END IF;
                RETURN NEW;
            END; $$;
            CREATE TRIGGER party_scope_guard BEFORE INSERT OR UPDATE ON account_parties FOR EACH ROW EXECUTE FUNCTION dcms_party_scope_guard();
            CREATE OR REPLACE FUNCTION dcms_task_scope_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE client bigint;
            BEGIN
                SELECT client_id INTO client FROM assignment_batches WHERE id=NEW.assignment_batch_id;
                IF client<>(SELECT client_id FROM debt_accounts WHERE id=NEW.debt_account_id) OR client<>(SELECT client_id FROM debtors WHERE id=NEW.customer_id) THEN RAISE EXCEPTION 'Task scope mismatch' USING ERRCODE='23514'; END IF;
                IF TG_OP='INSERT' AND NOT EXISTS (SELECT 1 FROM account_parties WHERE debt_account_id=NEW.debt_account_id AND debtor_id=NEW.customer_id AND valid_from<=statement_timestamp() AND (valid_to IS NULL OR valid_to>statement_timestamp())) THEN RAISE EXCEPTION 'Customer not on account' USING ERRCODE='23514'; END IF;
                IF TG_OP='UPDATE' AND (NEW.source_snapshot_ciphertext,NEW.origin_code,NEW.source_import_row_id,NEW.source_iwk_id,NEW.customer_id,NEW.debt_account_id,NEW.assignment_batch_id,NEW.created_by,NEW.type_code) IS DISTINCT FROM (OLD.source_snapshot_ciphertext,OLD.origin_code,OLD.source_import_row_id,OLD.source_iwk_id,OLD.customer_id,OLD.debt_account_id,OLD.assignment_batch_id,OLD.created_by,OLD.type_code) THEN RAISE EXCEPTION 'Task identity/source immutable' USING ERRCODE='23514'; END IF;
                RETURN NEW;
            END; $$;
            CREATE TRIGGER task_scope_guard BEFORE INSERT OR UPDATE ON tasks FOR EACH ROW EXECUTE FUNCTION dcms_task_scope_guard();
            CREATE OR REPLACE FUNCTION dcms_task_append_guard() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'Task history is append only' USING ERRCODE='23514'; END; $$;
            CREATE TRIGGER task_history_immutable BEFORE UPDATE OR DELETE ON task_status_histories FOR EACH ROW EXECUTE FUNCTION dcms_task_append_guard();
            CREATE OR REPLACE FUNCTION dcms_bulk_item_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF (SELECT assignment_batch_id FROM tasks WHERE id=NEW.task_id)<>(SELECT assignment_batch_id FROM bulk_operations WHERE id=NEW.bulk_operation_id) THEN RAISE EXCEPTION 'Bulk task batch mismatch' USING ERRCODE='23514'; END IF;
                IF TG_OP='UPDATE' AND (NEW.task_id,NEW.bulk_operation_id,NEW.expected_version) IS DISTINCT FROM (OLD.task_id,OLD.bulk_operation_id,OLD.expected_version) THEN RAISE EXCEPTION 'Bulk selection immutable' USING ERRCODE='23514'; END IF;
                RETURN NEW;
            END; $$;
            CREATE TRIGGER bulk_item_guard BEFORE INSERT OR UPDATE ON bulk_operation_items FOR EACH ROW EXECUTE FUNCTION dcms_bulk_item_guard();
            CREATE OR REPLACE FUNCTION dcms_bulk_definition_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF (to_jsonb(NEW)-'status'-'result_counts'-'updated_at') IS DISTINCT FROM (to_jsonb(OLD)-'status'-'result_counts'-'updated_at') THEN RAISE EXCEPTION 'Operation criteria immutable' USING ERRCODE='23514'; END IF;
                RETURN NEW;
            END; $$;
            CREATE TRIGGER bulk_definition_guard BEFORE UPDATE ON bulk_operations FOR EACH ROW EXECUTE FUNCTION dcms_bulk_definition_guard();
            CREATE OR REPLACE FUNCTION dcms_property_client_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.client_id<>OLD.client_id THEN RAISE EXCEPTION 'Property client immutable' USING ERRCODE='23514'; END IF;
                RETURN NEW;
            END; $$;
            CREATE TRIGGER property_client_guard BEFORE UPDATE ON properties FOR EACH ROW EXECUTE FUNCTION dcms_property_client_guard();
        SQL);
    }

    public function down(): void
    {
        Schema::table('account_parties', fn (Blueprint $t) => $t->dropForeign(['source_task_id']));
        foreach (['task_status_histories', 'task_allocations', 'bulk_operation_items', 'bulk_operations', 'tasks', 'account_parties', 'debt_accounts', 'properties'] as $table) {
            Schema::dropIfExists($table);
        }
        foreach (['dcms_account_scope_guard', 'dcms_party_scope_guard', 'dcms_task_scope_guard', 'dcms_task_append_guard', 'dcms_bulk_item_guard', 'dcms_bulk_definition_guard', 'dcms_property_client_guard'] as $function) {
            DB::statement("DROP FUNCTION IF EXISTS $function()");
        }
    }
};
