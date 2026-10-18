<?php

namespace App\Actions\Communications;

use App\Models\CommunicationTemplate;
use App\Models\User;
use App\Services\AuditWriter;
use App\Services\Communications\TemplateRenderer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** M14/UI65: slot-serialized editing, frozen submission and retained review/version history. */
class ManageTemplates
{
    public function __construct(private TemplateRenderer $renderer, private AuditWriter $audit) {}

    private function locked(CommunicationTemplate $target): CommunicationTemplate
    {
        abort_unless($target->channel === 'email' && in_array($target->email_slot, [1, 2, 3], true), 404);
        DB::select('SELECT pg_advisory_xact_lock(?, ?)', [14065, $target->email_slot]);

        return CommunicationTemplate::query()->lockForUpdate()->findOrFail($target->id);
    }

    private function version(CommunicationTemplate $template, int $expected): void
    {
        if ($template->lock_version !== $expected) {
            throw ValidationException::withMessages(['revision' => 'This version changed. Reload and review the saved content.']);
        }
    }

    private function savedContent(CommunicationTemplate $template): void
    {
        $required = collect($template->allowed_placeholders)->filter(fn ($value) => $value['required'] ?? false)->keys()->all();
        $this->renderer->validate(['subject' => $template->subject_template, 'body' => $template->body_template, 'required' => $required], true);
    }

    public function save(User $actor, CommunicationTemplate $target, int $revision, array $input): CommunicationTemplate
    {
        return DB::transaction(function () use ($actor, $target, $revision, $input): CommunicationTemplate {
            $template = $this->locked($target);
            $actor = $actor->fresh();
            Gate::forUser($actor)->authorize('update', $template);
            $this->version($template, $revision);
            $data = Validator::make($input, ['name' => ['required', 'string', 'max:160']])->validate();
            if (trim($data['name']) === '') {
                throw ValidationException::withMessages(['name' => 'Template name is required.']);
            }
            $template->forceFill([...$this->renderer->validate($input), 'name' => trim($data['name']), 'lock_version' => $revision + 1])->save();
            $this->audit->record($actor, 'template.draft_saved', $template->public_id, ['changed_fields' => ['name', 'subject', 'body', 'placeholder_requirements']], module: 'M14', entityType: 'communication_template');

            return $template;
        }, 3);
    }

    public function createVersion(User $actor, CommunicationTemplate $target): CommunicationTemplate
    {
        return DB::transaction(function () use ($actor, $target): CommunicationTemplate {
            $source = $this->locked($target);
            $actor = $actor->fresh();
            Gate::forUser($actor)->authorize('createVersion', $source);
            $working = CommunicationTemplate::query()->where('channel', 'email')->where('email_slot', $source->email_slot)->whereIn('status', ['draft', 'pending_approval'])->first();
            if ($working) {
                return $working;
            }
            $draft = new CommunicationTemplate;
            $draft->forceFill(['channel' => 'email', 'email_slot' => $source->email_slot, 'code' => $source->code, 'name' => $source->name, 'version' => CommunicationTemplate::query()->where('channel', 'email')->where('code', $source->code)->max('version') + 1, 'subject_template' => $source->subject_template, 'body_template' => $source->body_template, 'allowed_placeholders' => $source->allowed_placeholders ?: (object) [], 'supersedes_id' => $source->id, 'created_by' => $actor->id])->save();
            $this->audit->record($actor, 'template.version_created', $draft->public_id, ['status' => 'draft'], module: 'M14', entityType: 'communication_template');

            return $draft;
        }, 3);
    }

    public function submit(User $actor, CommunicationTemplate $target, int $revision): CommunicationTemplate
    {
        return DB::transaction(function () use ($actor, $target, $revision): CommunicationTemplate {
            $template = $this->locked($target);
            $actor = $actor->fresh();
            Gate::forUser($actor)->authorize('submit', $template);
            $this->version($template, $revision);
            $this->savedContent($template);
            $template->forceFill(['status' => 'pending_approval', 'submitted_by' => $actor->id, 'submitted_at' => now(), 'lock_version' => $revision + 1])->save();
            $this->audit->record($actor, 'template.submitted', $template->public_id, ['status' => 'pending_approval'], module: 'M14', entityType: 'communication_template');

            return $template;
        }, 3);
    }

    public function approve(User $actor, CommunicationTemplate $target, int $revision): CommunicationTemplate
    {
        return DB::transaction(function () use ($actor, $target, $revision): CommunicationTemplate {
            $template = $this->locked($target);
            $actor = $actor->fresh();
            Gate::forUser($actor)->authorize('approve', $template);
            $this->version($template, $revision);
            $this->savedContent($template);
            $previous = CommunicationTemplate::query()->where('channel', 'email')->where('email_slot', $template->email_slot)->where('status', 'approved')->lockForUpdate()->first();
            if ($previous) {
                $previous->forceFill(['status' => 'archived', 'lock_version' => $previous->lock_version + 1])->save();
                $this->audit->record($actor, 'template.superseded', $previous->public_id, ['status' => 'archived'], module: 'M14', entityType: 'communication_template');
            }
            $template->forceFill(['status' => 'approved', 'approved_by' => $actor->id, 'approved_at' => now(), 'approval_source' => 'authorized_user', 'lock_version' => $revision + 1])->save();
            $this->audit->record($actor, 'template.approved', $template->public_id, ['status' => 'approved'], module: 'M14', entityType: 'communication_template');

            return $template;
        }, 3);
    }

    public function reject(User $actor, CommunicationTemplate $target, int $revision, string $reason): CommunicationTemplate
    {
        Validator::make(['rejectionReason' => $reason], ['rejectionReason' => ['required', 'string', 'max:2000']])->validate();
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['rejectionReason' => 'A rejection reason is required.']);
        }

        return DB::transaction(function () use ($actor, $target, $revision, $reason): CommunicationTemplate {
            $template = $this->locked($target);
            $actor = $actor->fresh();
            Gate::forUser($actor)->authorize('reject', $template);
            $this->version($template, $revision);
            $template->forceFill(['status' => 'rejected', 'rejected_by' => $actor->id, 'rejected_at' => now(), 'rejection_reason_ciphertext' => trim($reason), 'lock_version' => $revision + 1])->save();
            $this->audit->record($actor, 'template.rejected', $template->public_id, ['status' => 'rejected'], module: 'M14', entityType: 'communication_template');

            return $template;
        }, 3);
    }

    public function archive(User $actor, CommunicationTemplate $target, int $revision): CommunicationTemplate
    {
        return DB::transaction(function () use ($actor, $target, $revision): CommunicationTemplate {
            $template = $this->locked($target);
            $actor = $actor->fresh();
            Gate::forUser($actor)->authorize('archive',$template);
            $this->version($template,$revision);
            $template->forceFill(['status' => 'archived', 'lock_version' => $revision + 1])->save();
            $this->audit->record($actor,'template.archived',$template->public_id,['status' => 'archived'],module: 'M14',entityType: 'communication_template');

            return $template;
        }, 3);
    }
}
