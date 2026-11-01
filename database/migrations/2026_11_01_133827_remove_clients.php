<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Removes the client tenancy layer: clients, user_client_scopes, every client_id column and the guards built on them.
 * References that were unique per client become unique system-wide, so the upgrade stops instead of merging records silently.
 */
return new class extends Migration
{
    /** @var array<string, string> table => reference column that becomes unique system-wide */
    private const NEWLY_GLOBAL_REFERENCES = [
        'debtors' => 'customer_reference',
        'customer_categories' => 'code',
        'debt_accounts' => 'account_number',
        'assignment_batches' => 'external_reference',
        'properties' => 'external_key',
    ];

    public function up(): void
    {
        $this->assertReferencesStayUnique();

        DB::unprepared(<<<'SQL'
            WITH ranked AS (SELECT id, row_number() OVER (PARTITION BY module, normalized_alias ORDER BY client_id NULLS FIRST, id) AS position FROM column_aliases WHERE is_active)
            UPDATE column_aliases SET is_active = false FROM ranked WHERE column_aliases.id = ranked.id AND ranked.position > 1;

            ALTER TABLE communication_preferences DISABLE TRIGGER USER;
            UPDATE communication_preferences SET basis = 'AUTHORIZED_COLLECTION' WHERE basis = 'CLIENT_AUTHORIZED_COLLECTION';
            ALTER TABLE communication_preferences ENABLE TRIGGER USER;

            DROP TRIGGER assignment_batch_client_guard ON assignment_batches;
            DROP TRIGGER property_client_guard ON properties;
            DROP TRIGGER debtor_namespace ON debtors;
            DROP TRIGGER category_namespace ON customer_categories;
            DROP TRIGGER account_scope_guard ON debt_accounts;
            DROP FUNCTION dcms_batch_client_guard();
            DROP FUNCTION dcms_property_client_guard();
            DROP FUNCTION dcms_customer_namespace_guard();
            DROP FUNCTION dcms_account_scope_guard();

            CREATE OR REPLACE FUNCTION dcms_category_membership_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE category_active boolean;
            BEGIN
                IF TG_OP = 'UPDATE' THEN
                    IF (to_jsonb(NEW) - 'revoked_at' - 'updated_at') IS DISTINCT FROM (to_jsonb(OLD) - 'revoked_at' - 'updated_at') OR OLD.revoked_at IS NOT NULL THEN
                        RAISE EXCEPTION 'Membership history is immutable except first revocation';
                    END IF;
                END IF;
                SELECT is_active INTO category_active FROM customer_categories WHERE id = NEW.category_id FOR SHARE;
                IF TG_OP = 'INSERT' AND NOT category_active THEN RAISE EXCEPTION 'Inactive category cannot receive new membership'; END IF;
                RETURN NEW;
            END; $$;

            CREATE OR REPLACE FUNCTION dcms_party_scope_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP='UPDATE' AND (to_jsonb(NEW)-'valid_to'-'updated_at') IS DISTINCT FROM (to_jsonb(OLD)-'valid_to'-'updated_at') THEN RAISE EXCEPTION 'Party observation immutable' USING ERRCODE='23514'; END IF;
                RETURN NEW;
            END; $$;

            CREATE OR REPLACE FUNCTION dcms_task_scope_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP='INSERT' AND NOT EXISTS (SELECT 1 FROM account_parties WHERE debt_account_id=NEW.debt_account_id AND debtor_id=NEW.customer_id AND valid_from<=statement_timestamp() AND (valid_to IS NULL OR valid_to>statement_timestamp())) THEN RAISE EXCEPTION 'Customer not on account' USING ERRCODE='23514'; END IF;
                IF TG_OP='UPDATE' AND (NEW.source_snapshot_ciphertext,NEW.origin_code,NEW.source_import_row_id,NEW.source_iwk_id,NEW.customer_id,NEW.debt_account_id,NEW.assignment_batch_id,NEW.created_by,NEW.type_code) IS DISTINCT FROM (OLD.source_snapshot_ciphertext,OLD.origin_code,OLD.source_import_row_id,OLD.source_iwk_id,OLD.customer_id,OLD.debt_account_id,OLD.assignment_batch_id,OLD.created_by,OLD.type_code) THEN RAISE EXCEPTION 'Task identity/source immutable' USING ERRCODE='23514'; END IF;
                RETURN NEW;
            END; $$;

            CREATE OR REPLACE FUNCTION import_scope_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.kind <> (SELECT kind FROM import_templates WHERE id=NEW.template_id) THEN RAISE EXCEPTION 'Template kind mismatch' USING ERRCODE='23514'; END IF;
                IF NEW.mapping_profile_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM import_mapping_profiles WHERE id=NEW.mapping_profile_id AND template_id=NEW.template_id) THEN RAISE EXCEPTION 'Profile scope mismatch' USING ERRCODE='23514'; END IF;
                IF NEW.source_document_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM documents WHERE id=NEW.source_document_id AND owner_type='import' AND owner_id=NEW.id) THEN RAISE EXCEPTION 'Source ownership mismatch' USING ERRCODE='23514'; END IF;
                IF TG_OP='UPDATE' AND (NEW.kind,NEW.template_id,NEW.content_sha256,NEW.uploaded_by,NEW.assignment_batch_id) IS DISTINCT FROM (OLD.kind,OLD.template_id,OLD.content_sha256,OLD.uploaded_by,OLD.assignment_batch_id) THEN RAISE EXCEPTION 'Import identity immutable' USING ERRCODE='23514'; END IF;
                IF TG_OP='UPDATE' AND OLD.source_document_id IS NOT NULL AND NEW.source_document_id IS DISTINCT FROM OLD.source_document_id THEN RAISE EXCEPTION 'Source document immutable' USING ERRCODE='23514'; END IF;
                IF TG_OP='UPDATE' AND OLD.confirmed_at IS NOT NULL AND (NEW.mapping_digest,NEW.mapping_profile_id,NEW.selected_sheet,NEW.header_row_number,NEW.confirmed_by,NEW.confirmed_at) IS DISTINCT FROM (OLD.mapping_digest,OLD.mapping_profile_id,OLD.selected_sheet,OLD.header_row_number,OLD.confirmed_by,OLD.confirmed_at) THEN RAISE EXCEPTION 'Confirmed import immutable' USING ERRCODE='23514'; END IF;
                RETURN NEW;
            END; $$;

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
                IF NEW.target_customer_id IS NOT NULL AND batch.kind <> 'customer' THEN RAISE EXCEPTION 'Customer scope mismatch' USING ERRCODE='23514'; END IF;
                IF NEW.target_assignment_batch_id IS NOT NULL AND batch.kind <> 'assignment_batch' THEN RAISE EXCEPTION 'Batch scope mismatch' USING ERRCODE='23514'; END IF;
                RETURN NEW;
            END; $$;

            CREATE OR REPLACE FUNCTION communication_recipient_context() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.contact_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM contacts WHERE id=NEW.contact_id AND debtor_id=NEW.customer_id) THEN RAISE EXCEPTION 'Communication contact context mismatch' USING ERRCODE='23514'; END IF;
                IF TG_TABLE_NAME='communication_preferences' THEN
                    IF NEW.supersedes_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM communication_preferences p WHERE p.id=NEW.supersedes_id AND p.customer_id=NEW.customer_id AND p.contact_id IS NOT DISTINCT FROM NEW.contact_id AND p.debt_account_id IS NOT DISTINCT FROM NEW.debt_account_id AND p.channel=NEW.channel AND p.purpose=NEW.purpose) THEN RAISE EXCEPTION 'Preference lineage mismatch' USING ERRCODE='23514'; END IF;
                    IF NEW.debt_account_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM account_parties p WHERE p.debt_account_id=NEW.debt_account_id AND p.debtor_id=NEW.customer_id) THEN RAISE EXCEPTION 'Preference account mismatch' USING ERRCODE='23514'; END IF;
                    IF NEW.task_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM tasks WHERE id=NEW.task_id AND customer_id=NEW.customer_id AND debt_account_id=NEW.debt_account_id) THEN RAISE EXCEPTION 'Preference task mismatch' USING ERRCODE='23514'; END IF;
                ELSIF TG_TABLE_NAME='communications' THEN
                    IF NEW.task_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM tasks WHERE id=NEW.task_id AND customer_id=NEW.customer_id) THEN RAISE EXCEPTION 'Message task mismatch' USING ERRCODE='23514'; END IF;
                    IF NEW.batch_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM communication_batches b WHERE b.id=NEW.batch_id AND b.template_id=NEW.template_id AND (TG_OP='UPDATE' OR b.status='draft')) THEN RAISE EXCEPTION 'Message batch mismatch or frozen batch' USING ERRCODE='23514'; END IF;
                    IF NEW.conversation_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM conversations WHERE id=NEW.conversation_id AND customer_id=NEW.customer_id AND contact_id=NEW.contact_id) THEN RAISE EXCEPTION 'Conversation mismatch' USING ERRCODE='23514'; END IF;
                END IF;
                RETURN NEW;
            END; $$;
        SQL);

        foreach (['debtors', 'customer_categories', 'debt_accounts', 'assignment_batches', 'properties', 'import_batches', 'import_mapping_profiles', 'column_aliases', 'communication_preferences', 'communication_batches'] as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropConstrainedForeignId('client_id');
            });
        }

        Schema::table('debtors', function (Blueprint $table): void {
            $table->unique('customer_reference');
            $table->index('identity_fingerprint');
            $table->index(['status', 'id']);
        });
        Schema::table('customer_categories', function (Blueprint $table): void {
            $table->unique('code');
            $table->index(['is_active', 'name']);
        });
        Schema::table('debt_accounts', function (Blueprint $table): void {
            $table->unique('account_number');
            $table->index('status');
        });
        Schema::table('assignment_batches', function (Blueprint $table): void {
            $table->index(['status', 'due_date']);
        });
        Schema::table('properties', function (Blueprint $table): void {
            $table->index('postcode');
        });
        Schema::table('import_mapping_profiles', function (Blueprint $table): void {
            $table->unique(['code', 'version']);
        });
        Schema::table('communication_batches', function (Blueprint $table): void {
            $table->index(['status', 'created_at']);
        });
        DB::statement('CREATE UNIQUE INDEX assignment_batches_external_reference_unique ON assignment_batches (external_reference) WHERE external_reference IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX property_external_key_unique ON properties (external_key) WHERE external_key IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX active_alias ON column_aliases (module, normalized_alias) WHERE is_active');

        Schema::dropIfExists('user_client_scopes');
        Schema::dropIfExists('clients');

        DB::table('permissions')->where('name', 'scopes.manage')->delete();
    }

    /** Client rows, scope grants and each record's client link are gone, so only a database backup can restore them. */
    public function down(): void
    {
        throw new RuntimeException('Removing clients cannot be rolled back. Restore a database backup taken before this migration.');
    }

    /** Stops before any change, naming what must be resolved, because two clients may legitimately have reused a reference. */
    private function assertReferencesStayUnique(): void
    {
        $conflicts = [];
        foreach (self::NEWLY_GLOBAL_REFERENCES as $table => $column) {
            $repeated = DB::table($table)->whereNotNull($column)->groupBy($column)->havingRaw('count(*) > 1')->pluck($column);
            if ($repeated->isNotEmpty()) {
                $conflicts[] = "{$table}.{$column}: {$repeated->take(20)->implode(', ')}";
            }
        }
        $profiles = DB::table('import_mapping_profiles')->select('code', 'version')->groupBy('code', 'version')->havingRaw('count(*) > 1')->get()->count();
        if ($profiles > 0) {
            $conflicts[] = "import_mapping_profiles.(code, version): {$profiles} repeated";
        }
        if ($conflicts !== []) {
            throw new RuntimeException('Records in different clients share a reference that must become unique. Resolve these first: '.implode('; ', $conflicts));
        }
    }
};
