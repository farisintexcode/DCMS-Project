<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** M04 / UI14–15, UI39, UI57–58 / Database.md import registry, staging and documents. */
return new class extends Migration
{
    private function identity(Blueprint $table): void
    {
        $table->id();
        $table->uuid('public_id')->unique();
        $table->timestampsTz(6);
    }

    public function up(): void
    {
        Schema::create('import_fields', function (Blueprint $t): void {
            $this->identity($t);
            $t->string('module', 40);
            $t->string('canonical_field', 100);
            $t->integer('version')->default(1);
            $t->string('data_type', 40);
            $t->jsonb('validation_schema')->default('{}');
            $t->boolean('is_active')->default(true);
            $t->unique(['module', 'canonical_field', 'version']);
        });
        Schema::create('column_aliases', function (Blueprint $t): void {
            $this->identity($t);
            $t->foreignId('field_id')->constrained('import_fields')->restrictOnDelete();
            $t->foreignId('client_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('module', 40);
            $t->string('alias', 160);
            $t->string('normalized_alias', 160);
            $t->string('locale', 20)->nullable();
            $t->boolean('is_active')->default(true);
        });
        Schema::create('import_templates', function (Blueprint $t): void {
            $this->identity($t);
            $t->string('code', 80);
            $t->integer('version');
            $t->string('kind', 32);
            $t->string('assignment_type_category', 50)->default('assignment_type');
            $t->string('assignment_type_code', 64)->nullable();
            $t->jsonb('schema_definition');
            $t->boolean('is_active')->default(false);
            $t->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->foreign(['assignment_type_category', 'assignment_type_code'])->references(['category', 'code'])->on('reference_values')->restrictOnDelete();
            $t->unique(['code', 'version']);
        });
        Schema::create('import_mapping_profiles', function (Blueprint $t): void {
            $this->identity($t);
            $t->foreignId('client_id')->constrained()->restrictOnDelete();
            $t->foreignId('template_id')->constrained('import_templates')->restrictOnDelete();
            $t->string('code', 100);
            $t->integer('version')->default(1);
            $t->string('name', 160);
            $t->jsonb('mapping_definition');
            $t->boolean('is_active')->default(true);
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->unique(['client_id', 'code', 'version']);
        });
        Schema::create('import_batches', function (Blueprint $t): void {
            $this->identity($t);
            $t->foreignId('assignment_batch_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('client_id')->constrained()->restrictOnDelete();
            $t->foreignId('template_id')->constrained('import_templates')->restrictOnDelete();
            $t->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $t->string('kind', 32);
            $t->unsignedBigInteger('source_document_id')->nullable();
            $t->char('content_sha256', 64);
            $t->string('idempotency_key', 200)->unique();
            $t->string('status', 32)->default('uploaded');
            foreach (['input', 'accepted', 'rejected', 'duplicate', 'processed', 'failed'] as $count) {
                $t->integer($count.'_count')->default(0);
            }
            $t->timestampTz('started_at', 6)->nullable();
            $t->timestampTz('completed_at', 6)->nullable();
            $t->text('error_summary')->nullable();
            $t->foreignId('mapping_profile_id')->nullable()->constrained('import_mapping_profiles')->restrictOnDelete();
            $t->string('selected_sheet', 100)->nullable();
            $t->integer('header_row_number')->nullable();
            $t->char('mapping_digest', 64)->nullable();
            foreach (['mapping_confirmed', 'confirmed'] as $confirmation) {
                $t->foreignId($confirmation.'_by')->nullable()->constrained('users')->restrictOnDelete();
                $t->timestampTz($confirmation.'_at', 6)->nullable();
            }
            $t->unsignedBigInteger('error_document_id')->nullable();
            $t->index(['assignment_batch_id', 'created_at']);
            $t->index(['status', 'created_at']);
        });
        Schema::create('import_column_mappings', function (Blueprint $t): void {
            $this->identity($t);
            $t->foreignId('import_batch_id')->constrained()->restrictOnDelete();
            $t->string('source_sheet', 100);
            $t->integer('source_column_index');
            $t->string('original_header', 255)->nullable();
            $t->string('normalized_header', 255)->nullable();
            $t->foreignId('field_id')->nullable()->constrained('import_fields')->restrictOnDelete();
            $t->foreignId('alias_id')->nullable()->constrained('column_aliases')->restrictOnDelete();
            $t->string('match_method', 24)->default('unmatched');
            $t->decimal('confidence', 5, 4)->nullable();
            $t->string('transform_code', 64)->nullable();
            $t->jsonb('matched_rule_snapshot')->default('{}');
            $t->boolean('is_ignored')->default(false);
            $t->foreignId('confirmed_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestampTz('confirmed_at', 6)->nullable();
            $t->unique(['import_batch_id', 'source_sheet', 'source_column_index'], 'import_source_column_unique');
        });
        Schema::create('import_rows', function (Blueprint $t): void {
            $this->identity($t);
            $t->foreignId('import_batch_id')->constrained()->restrictOnDelete();
            $t->string('sheet_name', 100);
            $t->integer('row_number');
            $t->string('source_key', 160)->nullable();
            $t->text('raw_payload_ciphertext');
            $t->text('normalized_payload_ciphertext')->nullable();
            $t->jsonb('validation_errors')->default('[]');
            $t->string('state', 24)->default('staged');
            $t->unsignedBigInteger('target_task_id')->nullable();
            $t->unsignedBigInteger('target_account_id')->nullable();
            $t->foreignId('target_customer_id')->nullable()->constrained('debtors')->restrictOnDelete();
            $t->foreignId('target_assignment_batch_id')->nullable()->constrained('assignment_batches')->restrictOnDelete();
            $t->timestampTz('processed_at', 6)->nullable();
            $t->unique(['import_batch_id', 'sheet_name', 'row_number']);
            $t->index(['import_batch_id', 'state']);
        });
        Schema::create('documents', function (Blueprint $t): void {
            $this->identity($t);
            $t->string('owner_type', 40);
            $t->unsignedBigInteger('owner_id');
            $t->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $t->string('disk', 32)->default('s3');
            $t->string('bucket', 160);
            $t->string('object_key', 1024);
            $t->string('version_id', 200)->nullable();
            $t->string('original_filename', 255);
            $t->string('mime_type', 160);
            $t->bigInteger('size_bytes');
            $t->char('sha256', 64);
            $t->string('document_type_category', 50)->default('document_type');
            $t->string('document_type_code', 64);
            $t->string('image_category_category', 50)->default('image_category');
            $t->string('image_category_code', 64)->nullable();
            $t->string('scan_status', 24)->default('pending');
            $t->text('caption_ciphertext')->nullable();
            $t->timestampTz('captured_at', 6)->nullable();
            $t->timestampTz('retained_until', 6)->nullable();
            $t->softDeletesTz('deleted_at', 6);
            $t->unique(['bucket', 'object_key']);
            $t->index(['owner_type', 'owner_id']);
            $t->index(['scan_status', 'created_at']);
            $t->foreign(['document_type_category', 'document_type_code'])->references(['category', 'code'])->on('reference_values')->restrictOnDelete();
            $t->foreign(['image_category_category', 'image_category_code'])->references(['category', 'code'])->on('reference_values')->restrictOnDelete();
        });
        Schema::table('import_batches', function (Blueprint $t): void {
            $t->foreign('source_document_id')->references('id')->on('documents')->restrictOnDelete();
            $t->foreign('error_document_id')->references('id')->on('documents')->restrictOnDelete();
        });
        DB::statement('CREATE UNIQUE INDEX active_global_alias ON column_aliases (module, normalized_alias) WHERE is_active AND client_id IS NULL');
        DB::statement('CREATE UNIQUE INDEX active_client_alias ON column_aliases (module, client_id, normalized_alias) WHERE is_active AND client_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX mapped_field_unique ON import_column_mappings (import_batch_id, source_sheet, field_id) WHERE NOT is_ignored AND field_id IS NOT NULL');
        foreach ([
            'import_fields' => "version > 0 AND module IN ('customer','assignment_batch','debt_account','assignment','payment_update','databank')",
            'import_templates' => "version > 0 AND assignment_type_category = 'assignment_type' AND kind IN ('customer','assignment_batch','debt_account','assignment','payment_update','databank')",
            'import_mapping_profiles' => 'version > 0',
            'import_column_mappings' => "source_column_index > 0 AND (confidence IS NULL OR confidence BETWEEN 0 AND 1) AND match_method IN ('canonical','alias','profile','manual','unmatched') AND (NOT is_ignored OR field_id IS NULL)",
            'import_rows' => "row_number > 0 AND target_task_id IS NULL AND target_account_id IS NULL AND state IN ('staged','accepted','rejected','duplicate','processed','failed') AND (state <> 'processed' OR num_nonnulls(target_customer_id,target_assignment_batch_id) = 1)",
            'documents' => "size_bytes >= 0 AND document_type_category = 'document_type' AND image_category_category = 'image_category' AND scan_status IN ('pending','clean','rejected','error') AND owner_type IN ('task','activity','import','export','user','communication')",
            'import_batches' => "status IN ('uploaded','scanning','mapping','validating','ready','processing','completed','failed','cancelled') AND input_count >= 0 AND accepted_count >= 0 AND rejected_count >= 0 AND duplicate_count >= 0 AND processed_count >= 0 AND failed_count >= 0 AND processed_count + failed_count <= accepted_count AND (header_row_number IS NULL OR header_row_number > 0) AND (status NOT IN ('ready','processing','completed') OR (mapping_digest IS NOT NULL AND mapping_confirmed_at IS NOT NULL AND input_count = accepted_count + rejected_count + duplicate_count)) AND (status <> 'completed' OR accepted_count = processed_count + failed_count)",
        ] as $table => $check) {
            DB::statement("ALTER TABLE $table ADD CONSTRAINT {$table}_values CHECK ($check)");
        }
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION import_scope_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.kind <> (SELECT kind FROM import_templates WHERE id=NEW.template_id) THEN RAISE EXCEPTION 'Template kind mismatch' USING ERRCODE='23514'; END IF;
                IF NEW.assignment_batch_id IS NOT NULL AND NEW.client_id <> (SELECT client_id FROM assignment_batches WHERE id=NEW.assignment_batch_id) THEN RAISE EXCEPTION 'Import client mismatch' USING ERRCODE='23514'; END IF;
                IF NEW.mapping_profile_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM import_mapping_profiles WHERE id=NEW.mapping_profile_id AND client_id=NEW.client_id AND template_id=NEW.template_id) THEN RAISE EXCEPTION 'Profile scope mismatch' USING ERRCODE='23514'; END IF;
                IF NEW.source_document_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM documents WHERE id=NEW.source_document_id AND owner_type='import' AND owner_id=NEW.id) THEN RAISE EXCEPTION 'Source ownership mismatch' USING ERRCODE='23514'; END IF;
                IF TG_OP='UPDATE' AND (NEW.client_id,NEW.kind,NEW.template_id,NEW.content_sha256,NEW.uploaded_by,NEW.assignment_batch_id) IS DISTINCT FROM (OLD.client_id,OLD.kind,OLD.template_id,OLD.content_sha256,OLD.uploaded_by,OLD.assignment_batch_id) THEN RAISE EXCEPTION 'Import identity immutable' USING ERRCODE='23514'; END IF;
                IF TG_OP='UPDATE' AND OLD.source_document_id IS NOT NULL AND NEW.source_document_id IS DISTINCT FROM OLD.source_document_id THEN RAISE EXCEPTION 'Source document immutable' USING ERRCODE='23514'; END IF;
                IF TG_OP='UPDATE' AND OLD.confirmed_at IS NOT NULL AND (NEW.mapping_digest,NEW.mapping_profile_id,NEW.selected_sheet,NEW.header_row_number,NEW.confirmed_by,NEW.confirmed_at) IS DISTINCT FROM (OLD.mapping_digest,OLD.mapping_profile_id,OLD.selected_sheet,OLD.header_row_number,OLD.confirmed_by,OLD.confirmed_at) THEN RAISE EXCEPTION 'Confirmed import immutable' USING ERRCODE='23514'; END IF;
                RETURN NEW;
            END; $$;
            CREATE TRIGGER import_scope_guard BEFORE INSERT OR UPDATE ON import_batches FOR EACH ROW EXECUTE FUNCTION import_scope_guard();
            CREATE OR REPLACE FUNCTION import_mapping_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE batch import_batches;
            BEGIN
                SELECT * INTO batch FROM import_batches WHERE id=COALESCE(NEW.import_batch_id,OLD.import_batch_id);
                IF batch.confirmed_at IS NOT NULL THEN RAISE EXCEPTION 'Confirmed mapping immutable' USING ERRCODE='23514'; END IF;
                IF TG_OP='DELETE' THEN RETURN OLD; END IF;
                IF NEW.field_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM import_fields WHERE id=NEW.field_id AND module=batch.kind) THEN RAISE EXCEPTION 'Mapping module mismatch' USING ERRCODE='23514'; END IF;
                RETURN NEW;
            END; $$;
            CREATE TRIGGER import_mapping_guard BEFORE INSERT OR UPDATE OR DELETE ON import_column_mappings FOR EACH ROW EXECUTE FUNCTION import_mapping_guard();
            CREATE OR REPLACE FUNCTION import_definition_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP='DELETE' OR (to_jsonb(NEW)-'is_active'-'updated_at') IS DISTINCT FROM (to_jsonb(OLD)-'is_active'-'updated_at') THEN RAISE EXCEPTION 'Create a new definition version' USING ERRCODE='23514'; END IF;
                RETURN NEW;
            END; $$;
            CREATE TRIGGER import_field_immutable BEFORE UPDATE OR DELETE ON import_fields FOR EACH ROW EXECUTE FUNCTION import_definition_guard();
            CREATE TRIGGER import_template_immutable BEFORE UPDATE OR DELETE ON import_templates FOR EACH ROW EXECUTE FUNCTION import_definition_guard();
            CREATE TRIGGER import_profile_immutable BEFORE UPDATE OR DELETE ON import_mapping_profiles FOR EACH ROW EXECUTE FUNCTION import_definition_guard();
            CREATE OR REPLACE FUNCTION import_row_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE batch import_batches;
            BEGIN
                SELECT * INTO batch FROM import_batches WHERE id=COALESCE(NEW.import_batch_id,OLD.import_batch_id);
                IF TG_OP='DELETE' THEN
                    IF batch.confirmed_at IS NOT NULL THEN RAISE EXCEPTION 'Confirmed row immutable' USING ERRCODE='23514'; END IF;
                    RETURN OLD;
                END IF;
                IF TG_OP='INSERT' AND batch.confirmed_at IS NOT NULL THEN RAISE EXCEPTION 'Confirmed row set immutable' USING ERRCODE='23514'; END IF;
                IF TG_OP='UPDATE' AND (OLD.state='processed' OR (batch.confirmed_at IS NOT NULL AND (NEW.raw_payload_ciphertext,NEW.normalized_payload_ciphertext,NEW.sheet_name,NEW.row_number,NEW.import_batch_id) IS DISTINCT FROM (OLD.raw_payload_ciphertext,OLD.normalized_payload_ciphertext,OLD.sheet_name,OLD.row_number,OLD.import_batch_id))) THEN RAISE EXCEPTION 'Committed payload immutable' USING ERRCODE='23514'; END IF;
                IF NEW.target_customer_id IS NOT NULL AND (batch.kind <> 'customer' OR batch.client_id <> (SELECT client_id FROM debtors WHERE id=NEW.target_customer_id)) THEN RAISE EXCEPTION 'Customer scope mismatch' USING ERRCODE='23514'; END IF;
                IF NEW.target_assignment_batch_id IS NOT NULL AND (batch.kind <> 'assignment_batch' OR batch.client_id <> (SELECT client_id FROM assignment_batches WHERE id=NEW.target_assignment_batch_id)) THEN RAISE EXCEPTION 'Batch scope mismatch' USING ERRCODE='23514'; END IF;
                RETURN NEW;
            END; $$;
            CREATE TRIGGER import_row_guard BEFORE INSERT OR UPDATE OR DELETE ON import_rows FOR EACH ROW EXECUTE FUNCTION import_row_guard();
            CREATE OR REPLACE FUNCTION import_alias_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.module <> (SELECT module FROM import_fields WHERE id=NEW.field_id) THEN RAISE EXCEPTION 'Alias module mismatch' USING ERRCODE='23514'; END IF;
                RETURN NEW;
            END; $$;
            CREATE TRIGGER import_alias_guard BEFORE INSERT OR UPDATE ON column_aliases FOR EACH ROW EXECUTE FUNCTION import_alias_guard();
            CREATE OR REPLACE FUNCTION import_document_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.owner_type='import' AND NOT EXISTS (SELECT 1 FROM import_batches WHERE id=NEW.owner_id) THEN RAISE EXCEPTION 'Document owner missing' USING ERRCODE='23514'; END IF;
                IF NEW.owner_type='user' AND NOT EXISTS (SELECT 1 FROM users WHERE id=NEW.owner_id) THEN RAISE EXCEPTION 'Document owner missing' USING ERRCODE='23514'; END IF;
                IF NEW.owner_type NOT IN ('import','user') THEN RAISE EXCEPTION 'Document owner module unavailable' USING ERRCODE='23514'; END IF;
                IF TG_OP='UPDATE' AND (NEW.owner_type,NEW.owner_id,NEW.bucket,NEW.object_key,NEW.version_id,NEW.disk,NEW.sha256,NEW.size_bytes,NEW.uploaded_by,NEW.original_filename) IS DISTINCT FROM (OLD.owner_type,OLD.owner_id,OLD.bucket,OLD.object_key,OLD.version_id,OLD.disk,OLD.sha256,OLD.size_bytes,OLD.uploaded_by,OLD.original_filename) THEN RAISE EXCEPTION 'Document source immutable' USING ERRCODE='23514'; END IF;
                RETURN NEW;
            END; $$;
            CREATE TRIGGER import_document_guard BEFORE INSERT OR UPDATE ON documents FOR EACH ROW EXECUTE FUNCTION import_document_guard();
        SQL);
    }

    public function down(): void
    {
        Schema::table('import_batches', function (Blueprint $t): void {
            $t->dropForeign(['source_document_id']);
            $t->dropForeign(['error_document_id']);
        });
        foreach (['documents', 'import_rows', 'import_column_mappings', 'import_batches', 'import_mapping_profiles', 'import_templates', 'column_aliases', 'import_fields'] as $table) {
            Schema::dropIfExists($table);
        }
        foreach (['import_scope_guard', 'import_mapping_guard', 'import_definition_guard', 'import_row_guard', 'import_alias_guard', 'import_document_guard'] as $function) {
            DB::statement("DROP FUNCTION IF EXISTS $function()");
        }
    }
};
