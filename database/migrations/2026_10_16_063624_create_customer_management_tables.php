<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** M13 / UI24,61–63 / Database.md debtors, contacts, addresses, category history. */
return new class extends Migration
{
    public function up(): void
    {
        $common = function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->timestampTz('created_at', 6)->useCurrent();
            $table->timestampTz('updated_at', 6)->useCurrent();
        };
        Schema::create('debtors', function (Blueprint $table) use ($common): void {
            $common($table);
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->string('party_type', 24);
            $table->text('display_name_ciphertext');
            $table->text('identity_ciphertext')->nullable();
            $table->char('identity_fingerprint', 64)->nullable();
            $table->string('nationality_category', 50)->default('nationality');
            $table->string('nationality_code', 64)->nullable();
            $table->string('verification_status', 24)->default('unverified');
            $table->string('customer_reference', 80);
            $table->string('status', 24)->default('active');
            $table->jsonb('search_tokens')->default('[]');
            $table->unique(['client_id', 'customer_reference']);
            $table->index(['client_id', 'identity_fingerprint']);
            $table->index(['client_id', 'status', 'id']);
            $table->index(['nationality_category', 'nationality_code']);
            $table->foreign(['nationality_category', 'nationality_code'])->references(['category', 'code'])->on('reference_values')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE debtors ADD CONSTRAINT debtor_values CHECK (party_type IN ('person','company') AND status IN ('active','inactive') AND verification_status IN ('unverified','verified','disputed') AND nationality_category = 'nationality' AND length(trim(customer_reference)) > 0)");
        DB::statement('CREATE INDEX debtors_search_tokens_gin ON debtors USING gin (search_tokens)');
        Schema::create('contacts', function (Blueprint $table) use ($common): void {
            $common($table);
            $table->foreignId('debtor_id')->constrained()->restrictOnDelete();
            $table->string('kind_category', 50)->default('contact_type');
            $table->string('kind_code', 32);
            $table->text('value_ciphertext');
            $table->char('value_fingerprint', 64)->nullable();
            $table->string('source', 100);
            $table->boolean('is_primary')->default(false);
            $table->timestampTz('verified_at', 6)->nullable();
            $table->timestampTz('valid_from', 6)->useCurrent();
            $table->timestampTz('valid_to', 6)->nullable();
            $table->index(['debtor_id', 'kind_code']);
            $table->index(['kind_code', 'value_fingerprint']);
            $table->index(['kind_category', 'kind_code']);
            $table->foreign(['kind_category', 'kind_code'])->references(['category', 'code'])->on('reference_values')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE contacts ADD CONSTRAINT contact_values CHECK (kind_category = 'contact_type' AND (valid_to IS NULL OR valid_to >= valid_from))");
        Schema::create('addresses', function (Blueprint $table) use ($common): void {
            $common($table);
            $table->foreignId('debtor_id')->constrained()->restrictOnDelete();
            $table->string('kind_category', 50)->default('address_type');
            $table->string('kind_code', 32)->default('billing');
            $table->text('recipient_name_ciphertext')->nullable();
            $table->text('lines_ciphertext');
            $table->string('postcode', 20)->nullable();
            $table->string('state_category', 50)->default('state');
            $table->string('state_code', 64)->nullable();
            $table->string('source', 100);
            $table->timestampTz('valid_from', 6)->useCurrent();
            $table->timestampTz('valid_to', 6)->nullable();
            $table->index(['debtor_id', 'kind_code']);
            $table->index(['kind_category', 'kind_code']);
            $table->index(['state_category', 'state_code']);
            $table->foreign(['kind_category', 'kind_code'])->references(['category', 'code'])->on('reference_values')->restrictOnDelete();
            $table->foreign(['state_category', 'state_code'])->references(['category', 'code'])->on('reference_values')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE addresses ADD CONSTRAINT address_values CHECK (kind_category = 'address_type' AND state_category = 'state' AND (valid_to IS NULL OR valid_to >= valid_from))");
        Schema::create('customer_categories', function (Blueprint $table) use ($common): void {
            $common($table);
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->string('code', 64);
            $table->string('name', 160);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unique(['client_id', 'code']);
            $table->index(['client_id', 'is_active', 'name']);
        });
        Schema::create('customer_category_assignments', function (Blueprint $table) use ($common): void {
            $common($table);
            $table->foreignId('customer_id')->index()->constrained('debtors')->restrictOnDelete();
            $table->foreignId('category_id')->constrained('customer_categories')->restrictOnDelete();
            $table->foreignId('assigned_by')->index()->constrained('users')->restrictOnDelete();
            $table->timestampTz('assigned_at', 6)->useCurrent();
            $table->timestampTz('revoked_at', 6)->nullable();
            $table->index(['category_id', 'revoked_at', 'customer_id'], 'category_membership_history');
        });
        DB::statement('CREATE UNIQUE INDEX customer_category_current_unique ON customer_category_assignments (customer_id, category_id) WHERE revoked_at IS NULL');
        DB::statement('ALTER TABLE customer_category_assignments ADD CONSTRAINT category_membership_dates CHECK (revoked_at IS NULL OR revoked_at >= assigned_at)');
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION dcms_customer_namespace_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.client_id <> OLD.client_id THEN RAISE EXCEPTION 'Client namespace is immutable'; END IF;
                RETURN NEW;
            END; $$;
            CREATE TRIGGER debtor_namespace BEFORE UPDATE ON debtors FOR EACH ROW EXECUTE FUNCTION dcms_customer_namespace_guard();
            CREATE TRIGGER category_namespace BEFORE UPDATE ON customer_categories FOR EACH ROW EXECUTE FUNCTION dcms_customer_namespace_guard();
            CREATE OR REPLACE FUNCTION dcms_category_membership_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE customer_client bigint; category_client bigint; category_active boolean;
            BEGIN
                IF TG_OP = 'UPDATE' THEN
                    IF (to_jsonb(NEW) - 'revoked_at' - 'updated_at') IS DISTINCT FROM (to_jsonb(OLD) - 'revoked_at' - 'updated_at') OR OLD.revoked_at IS NOT NULL THEN
                        RAISE EXCEPTION 'Membership history is immutable except first revocation';
                    END IF;
                END IF;
                SELECT client_id INTO customer_client FROM debtors WHERE id = NEW.customer_id FOR SHARE;
                SELECT client_id, is_active INTO category_client, category_active FROM customer_categories WHERE id = NEW.category_id FOR SHARE;
                IF customer_client IS DISTINCT FROM category_client THEN RAISE EXCEPTION 'Category and customer must share a client'; END IF;
                IF TG_OP = 'INSERT' AND NOT category_active THEN RAISE EXCEPTION 'Inactive category cannot receive new membership'; END IF;
                RETURN NEW;
            END; $$;
            CREATE TRIGGER category_membership_guard BEFORE INSERT OR UPDATE ON customer_category_assignments FOR EACH ROW EXECUTE FUNCTION dcms_category_membership_guard();
            CREATE OR REPLACE FUNCTION dcms_contact_history_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'Customer observation history cannot be deleted'; END IF;
                IF (to_jsonb(NEW) - 'valid_to' - 'updated_at') IS DISTINCT FROM (to_jsonb(OLD) - 'valid_to' - 'updated_at') OR OLD.valid_to IS NOT NULL THEN
                    RAISE EXCEPTION 'Customer observations must be superseded, not overwritten';
                END IF;
                RETURN NEW;
            END; $$;
            CREATE TRIGGER contact_history_guard BEFORE UPDATE OR DELETE ON contacts FOR EACH ROW EXECUTE FUNCTION dcms_contact_history_guard();
            CREATE TRIGGER address_history_guard BEFORE UPDATE OR DELETE ON addresses FOR EACH ROW EXECUTE FUNCTION dcms_contact_history_guard();
            SQL);
    }

    public function down(): void
    {
        foreach (['customer_category_assignments', 'customer_categories', 'addresses', 'contacts', 'debtors'] as $table) {
            Schema::dropIfExists($table);
        }
        foreach (['dcms_customer_namespace_guard', 'dcms_category_membership_guard', 'dcms_contact_history_guard'] as $function) {
            DB::statement("DROP FUNCTION IF EXISTS {$function}()");
        }
    }
};
