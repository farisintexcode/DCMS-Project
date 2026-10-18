<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** M14 / UI65 / Database.md integration_accounts and communication_templates. */
    public function up(): void
    {
        Schema::create('integration_accounts', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->timestampsTz(6);
            $t->string('provider_code', 80);
            $t->string('channel', 24);
            $t->string('name', 160);
            $t->text('sender_reference_ciphertext')->nullable();
            $t->string('secret_reference', 255);
            $t->jsonb('capabilities')->default('{}');
            $t->timestampTz('capability_verified_at', 6)->nullable();
            $t->string('status', 24)->default('disabled');
            $t->unique(['provider_code', 'channel', 'name']);
        });
        Schema::create('communication_templates', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->timestampsTz(6);
            $t->string('code', 80);
            $t->string('name', 160);
            $t->integer('version')->default(1);
            $t->string('channel', 24);
            $t->smallInteger('email_slot')->nullable();
            $t->text('subject_template')->nullable();
            $t->text('body_template');
            $t->jsonb('allowed_placeholders')->default('{}');
            $t->string('status', 24)->default('draft');
            $t->foreignId('created_by')->nullable()->index()->constrained('users')->restrictOnDelete();
            $t->foreignId('approved_by')->nullable()->index()->constrained('users')->restrictOnDelete();
            $t->timestampTz('approved_at', 6)->nullable();
            $t->string('approval_source', 24)->nullable();
            $t->string('approval_reference', 200)->nullable();
            $t->foreignId('submitted_by')->nullable()->index()->constrained('users')->restrictOnDelete();
            $t->timestampTz('submitted_at', 6)->nullable();
            $t->foreignId('rejected_by')->nullable()->index()->constrained('users')->restrictOnDelete();
            $t->timestampTz('rejected_at', 6)->nullable();
            $t->text('rejection_reason_ciphertext')->nullable();
            $t->foreignId('integration_account_id')->nullable()->index()->constrained('integration_accounts')->restrictOnDelete();
            $t->string('provider_template_reference', 160)->nullable();
            $t->foreignId('supersedes_id')->nullable()->index()->constrained('communication_templates')->restrictOnDelete();
            $t->integer('lock_version')->default(0);
            $t->unique(['channel', 'code', 'version']);
        });
        DB::unprepared(<<<'SQL'
            ALTER TABLE integration_accounts ADD CONSTRAINT integration_account_state CHECK (status IN ('disabled','testing','enabled') AND channel IN ('email','whatsapp_messaging','whatsapp_voice') AND (status<>'enabled' OR capability_verified_at IS NOT NULL));
            ALTER TABLE communication_templates ADD CONSTRAINT template_shape CHECK (
                version>0 AND lock_version>=0 AND length(btrim(name))>0 AND status IN ('draft','pending_approval','approved','rejected','archived') AND jsonb_typeof(allowed_placeholders)='object'
                AND ((channel='email' AND email_slot IS NOT NULL AND email_slot BETWEEN 1 AND 3 AND code='email_'||email_slot::text AND subject_template IS NOT NULL AND integration_account_id IS NULL AND provider_template_reference IS NULL) OR (channel='whatsapp' AND email_slot IS NULL))
                AND ((status IN ('draft','pending_approval','rejected') AND approved_by IS NULL AND approved_at IS NULL AND approval_source IS NULL AND approval_reference IS NULL)
                    OR (status IN ('approved','archived') AND approved_at IS NOT NULL AND approval_source IS NOT NULL AND ((approval_source='authorized_user' AND approved_by IS NOT NULL AND approval_reference IS NULL) OR (approval_source='owner_default' AND approved_by IS NULL AND approval_reference IS NOT NULL AND created_by IS NULL AND version=1 AND supersedes_id IS NULL))))
                AND ((status='draft' AND submitted_by IS NULL AND submitted_at IS NULL) OR (status IN ('pending_approval','rejected') AND submitted_by IS NOT NULL AND submitted_at IS NOT NULL) OR (status IN ('approved','archived') AND ((approval_source='owner_default' AND submitted_by IS NULL AND submitted_at IS NULL) OR (approval_source='authorized_user' AND submitted_by IS NOT NULL AND submitted_at IS NOT NULL))))
                AND ((status='rejected' AND rejected_by IS NOT NULL AND rejected_at IS NOT NULL AND rejection_reason_ciphertext IS NOT NULL) OR (status<>'rejected' AND rejected_by IS NULL AND rejected_at IS NULL AND rejection_reason_ciphertext IS NULL))
                AND (status='draft' OR (length(btrim(body_template))>0 AND (channel<>'email' OR length(btrim(subject_template))>0)))
            );
            CREATE UNIQUE INDEX template_current_approved ON communication_templates(email_slot) WHERE channel='email' AND status='approved';
            CREATE UNIQUE INDEX template_working_version ON communication_templates(email_slot) WHERE channel='email' AND status IN ('draft','pending_approval');
            CREATE OR REPLACE FUNCTION communication_template_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP='DELETE' THEN RAISE EXCEPTION 'Template version history is retained' USING ERRCODE='23514'; END IF;
                IF TG_OP='INSERT' THEN
                    IF NEW.lock_version<>0 OR NOT (NEW.status='draft' OR (NEW.status='approved' AND NEW.approval_source='owner_default' AND NEW.version=1)) THEN RAISE EXCEPTION 'Begin with a draft or owner-approved initial default' USING ERRCODE='23514'; END IF;
                    IF NEW.supersedes_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM communication_templates p WHERE p.id=NEW.supersedes_id AND p.channel=NEW.channel AND p.code=NEW.code AND p.version<NEW.version) THEN RAISE EXCEPTION 'Template lineage must match the logical option' USING ERRCODE='23514'; END IF;
                    RETURN NEW;
                END IF;
                IF NEW.lock_version<>OLD.lock_version+1 OR (NEW.id,NEW.public_id,NEW.channel,NEW.code,NEW.email_slot,NEW.version,NEW.created_by,NEW.created_at,NEW.supersedes_id) IS DISTINCT FROM (OLD.id,OLD.public_id,OLD.channel,OLD.code,OLD.email_slot,OLD.version,OLD.created_by,OLD.created_at,OLD.supersedes_id) THEN RAISE EXCEPTION 'Template identity and version conflict' USING ERRCODE='23514'; END IF;
                IF OLD.status='draft' THEN
                    IF NEW.status='pending_approval' THEN
                        IF (to_jsonb(NEW)-ARRAY['status','submitted_by','submitted_at','lock_version','updated_at']) IS DISTINCT FROM (to_jsonb(OLD)-ARRAY['status','submitted_by','submitted_at','lock_version','updated_at']) THEN RAISE EXCEPTION 'Submit the exact saved content' USING ERRCODE='23514'; END IF;
                    ELSIF NEW.status<>'draft' THEN RAISE EXCEPTION 'Draft must be submitted for approval' USING ERRCODE='23514'; END IF;
                ELSIF OLD.status='pending_approval' THEN
                    IF NEW.status NOT IN ('approved','rejected') OR (to_jsonb(NEW)-ARRAY['status','approved_by','approved_at','approval_source','approval_reference','rejected_by','rejected_at','rejection_reason_ciphertext','lock_version','updated_at']) IS DISTINCT FROM (to_jsonb(OLD)-ARRAY['status','approved_by','approved_at','approval_source','approval_reference','rejected_by','rejected_at','rejection_reason_ciphertext','lock_version','updated_at']) THEN RAISE EXCEPTION 'Review the exact submitted content' USING ERRCODE='23514'; END IF;
                ELSIF OLD.status='approved' THEN
                    IF NEW.status<>'archived' OR (to_jsonb(NEW)-ARRAY['status','lock_version','updated_at']) IS DISTINCT FROM (to_jsonb(OLD)-ARRAY['status','lock_version','updated_at']) THEN RAISE EXCEPTION 'Published content and approval provenance are immutable' USING ERRCODE='23514'; END IF;
                ELSE RAISE EXCEPTION 'Rejected and archived versions are immutable' USING ERRCODE='23514';
                END IF;
                RETURN NEW;
            END; $$;
            CREATE TRIGGER communication_template_guard BEFORE INSERT OR UPDATE OR DELETE ON communication_templates FOR EACH ROW EXECUTE FUNCTION communication_template_guard();
        SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Template approvals and versions are retained; use a reviewed forward migration.');
    }
};
