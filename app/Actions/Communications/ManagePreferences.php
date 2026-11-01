<?php

namespace App\Actions\Communications;

use App\Models\AccountParty;
use App\Models\CommunicationPreference;
use App\Models\Contact;
use App\Models\DebtAccount;
use App\Models\Task;
use App\Models\User;
use App\Services\AuditWriter;
use App\Services\Communications\EmailEligibility;
use App\Services\Communications\RecipientAccess;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Owner-approved M14 service-email evidence and review; no marketing/force-send authority. */
class ManagePreferences
{
    public function __construct(private RecipientAccess $access, private EmailEligibility $eligibility, private AuditWriter $audit) {}

    private function scope(CommunicationPreference $pref): Builder
    {
        return CommunicationPreference::query()->where('customer_id', $pref->customer_id)->where('contact_id', $pref->contact_id)->where('debt_account_id', $pref->debt_account_id)->where('channel', 'email')->where('purpose', 'service_collection');
    }

    public function request(User $actor, string $customerId, array $input, string $key): CommunicationPreference
    {
        return DB::transaction(function () use ($actor, $customerId, $input, $key): CommunicationPreference {
            $actor = $actor->fresh();
            $customer = $this->access->customers($actor)->where('public_id', $customerId)->firstOrFail();
            abort_unless($actor->can('communication-permission.create') || $actor->can('communication-permission.update') || $actor->can('communication-permission.block'), 403);
            Validator::make(['key' => $key], ['key' => ['required', 'uuid']])->validate();
            $prior = CommunicationPreference::query()->where('request_key', $key)->first();
            if ($prior) {
                abort_unless($prior->recorded_by === $actor->id && $prior->customer_id === $customer->id, 403);

                return $prior;
            }
            $data = Validator::make($input, [
                'state' => ['required', Rule::in(config('communications.preference_states'))], 'basis' => ['nullable', Rule::in(config('communications.basis_codes'))],
                'contact' => ['nullable', 'uuid'], 'account' => ['nullable', 'uuid'], 'task' => ['nullable', 'uuid'],
                'source' => ['required', 'string', 'max:160'], 'reference' => ['required', 'string', 'max:2000'], 'email_source' => ['required', 'string', 'max:160'], 'reason' => ['required', 'string', 'max:2000'], 'notes' => ['nullable', 'string', 'max:3000'],
                'effective_at' => ['nullable', 'date_format:Y-m-d\TH:i'], 'expires_at' => ['nullable', 'date_format:Y-m-d\TH:i'],
                'notice_version' => ['nullable', 'string', 'max:100'], 'consent_method' => ['nullable', 'string', 'max:160'], 'granted_at' => ['nullable', 'date_format:Y-m-d\TH:i'],
                'assignment_date' => ['nullable', 'date_format:Y-m-d'], 'authority_reference' => ['nullable', 'string', 'max:2000'],
            ])->validate();
            foreach (['source', 'reference', 'email_source', 'reason'] as $field) {
                if (trim($data[$field]) === '') {
                    throw ValidationException::withMessages([$field => 'Documented source, evidence and reason are required.']);
                }
            }
            if (! $actor->can('communication-permission.create') && ! $actor->can('communication-permission.update')) {
                abort_unless(in_array($data['state'], EmailEligibility::Restrictive, true) && $actor->can('communication-permission.block'), 403);
            }
            $contact = empty($data['contact']) ? null : Contact::query()->where('debtor_id', $customer->id)->where('kind_code', 'email')->where('public_id', $data['contact'])->whereNull('valid_to')->firstOrFail();
            $account = empty($data['account']) ? null : DebtAccount::query()->where('public_id', $data['account'])->whereHas('parties', fn ($q) => $q->where('debtor_id', $customer->id)->whereNull('valid_to'))->firstOrFail();
            $task = empty($data['task']) ? null : Task::query()->where('customer_id', $customer->id)->where('debt_account_id', $account?->id)->where('public_id', $data['task'])->with('batch')->firstOrFail();
            if ($task) {
                Gate::forUser($actor)->authorize('view', $task);
            }
            if ($data['state'] === 'allowed') {
                abort_unless($actor->can('communication-permission.create') || $actor->can('communication-permission.update'), 403);
                if (! $contact || ! $account || empty($data['basis']) || ! filter_var($contact->value_ciphertext, FILTER_VALIDATE_EMAIL)) {
                    throw ValidationException::withMessages(['basis' => 'Allowed requests require a valid current email, linked account and approved basis.']);
                }
                if ($data['basis'] === 'EXPLICIT_SERVICE_CONSENT' && (trim($data['notice_version'] ?? '') === '' || trim($data['consent_method'] ?? '') === '' || empty($data['granted_at']))) {
                    throw ValidationException::withMessages(['notice_version' => 'Record consent notice version, method and grant time.']);
                }
                if ($data['basis'] === 'AUTHORIZED_COLLECTION' && (! $task || empty($data['assignment_date']) || trim($data['authority_reference'] ?? '') === '' || $task->batch->status->value !== 'active' || $task->work_status === 'cancelled')) {
                    throw ValidationException::withMessages(['authority_reference' => 'Record a matching active case, assignment date and communication authority reference.']);
                }
            }
            $tz = config('dcms.business_timezone');
            $effective = empty($data['effective_at']) ? now()->toImmutable() : CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $data['effective_at'], $tz)->utc();
            if (in_array($data['state'], EmailEligibility::Restrictive, true)) {
                $effective = now()->toImmutable();
            }
            $expires = empty($data['expires_at']) ? null : CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $data['expires_at'], $tz)->utc();
            if ($expires && $expires->lessThanOrEqualTo($effective)) {
                throw ValidationException::withMessages(['expires_at' => 'Expiry must follow the effective time.']);
            }
            if (! empty($data['granted_at']) && CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $data['granted_at'], $tz)->utc()->greaterThan(now())) {
                throw ValidationException::withMessages(['granted_at' => 'Consent grant time cannot be in the future.']);
            }
            if (! empty($data['granted_at']) && CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $data['granted_at'], $tz)->utc()->greaterThan($effective)) {
                throw ValidationException::withMessages(['granted_at' => 'Consent must exist by the effective time.']);
            }
            if (! empty($data['assignment_date']) && $data['assignment_date'] > now()->timezone($tz)->toDateString()) {
                throw ValidationException::withMessages(['assignment_date' => 'An authorized assignment date cannot be in the future.']);
            }
            $pref = new CommunicationPreference;
            $pref->forceFill(['customer_id' => $customer->id, 'contact_id' => $contact?->id, 'debt_account_id' => $account?->id, 'task_id' => $task?->id, 'state' => $data['state'], 'basis' => $data['basis'] ?? null, 'evidence_ciphertext' => [...array_intersect_key($data, array_flip(['source', 'reference', 'email_source', 'notes', 'notice_version', 'consent_method', 'granted_at', 'assignment_date', 'authority_reference'])), 'email' => $contact?->value_ciphertext, 'account' => $account?->public_id, 'task' => $task?->public_id], 'reason_ciphertext' => trim($data['reason']), 'effective_at' => $effective, 'expires_at' => $expires, 'recorded_by' => $actor->id, 'request_key' => $key]);
            $current = $this->scope($pref)->where('is_current', true)->lockForUpdate()->first();
            if ($current) {
                abort_unless($actor->can('communication-permission.update') || (in_array($pref->state, EmailEligibility::Restrictive, true) && $actor->can('communication-permission.block')), 403);
            }
            $pref->supersedes_id = $current?->id;
            if (in_array($pref->state, EmailEligibility::Restrictive, true) && $actor->can('communication-permission.block')) {
                if ($current) {
                    $current->forceFill(['is_current' => false, 'lock_version' => $current->lock_version + 1])->save();
                }
                $pref->forceFill(['review_status' => 'approved', 'reviewed_by' => $actor->id, 'reviewed_at' => now(), 'review_reason_ciphertext' => 'Immediate restriction: '.trim($data['reason']), 'is_current' => true]);
            }
            $pref->save();
            $pref->load('customer');
            $this->record($actor, 'communication.preference_requested', $pref, $current);

            return $pref;
        }, 3);
    }

    public function review(User $actor, CommunicationPreference $target, int $version, string $decision, string $reason): CommunicationPreference
    {
        Validator::make(compact('decision', 'reason'), ['decision' => ['required', 'in:approved,rejected'], 'reason' => ['required', 'string', 'max:2000']])->validate();
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reviewReason' => 'Review reason is required.']);
        }

        return DB::transaction(function () use ($actor, $target, $version, $decision, $reason): CommunicationPreference {
            $actor = $actor->fresh();
            $this->access->customers($actor)->whereKey($target->customer_id)->firstOrFail();
            abort_unless($actor->can('communication-permission.approve'), 403);
            $pref = CommunicationPreference::query()->with(['contact', 'customer', 'task.batch'])->lockForUpdate()->findOrFail($target->id);
            if ($pref->review_status !== 'pending' || $pref->lock_version !== $version) {
                throw ValidationException::withMessages(['revision' => 'This request was reviewed or changed. Reload it.']);
            }
            $current = $this->scope($pref)->where('is_current', true)->lockForUpdate()->first();
            if ($decision === 'approved' && $current?->id !== $pref->supersedes_id) {
                throw ValidationException::withMessages(['revision' => 'The current preference changed. Submit a fresh evidence request.']);
            }
            if (($decision === 'rejected' && in_array($pref->state, EmailEligibility::Restrictive, true)) || ($decision === 'approved' && $pref->state === 'allowed' && ($current && in_array($current->state, EmailEligibility::Restrictive, true) || ($pref->contact_id && $this->eligibility->restriction($pref->contact))))) {
                abort_unless($actor->can('communication-permission.override'), 403);
            }
            if ($decision === 'approved' && $pref->state === 'allowed') {
                if ($pref->basis === 'AUTHORIZED_COLLECTION') {
                    if (! $pref->task || $pref->task->work_status === 'cancelled' || $pref->task->batch->status->value !== 'active') {
                        throw ValidationException::withMessages(['revision' => 'Collection authority is no longer active.']);
                    } Gate::forUser($actor)->authorize('view', $pref->task);
                }
                if (! $pref->contact || $pref->contact->valid_to !== null || ! filter_var($pref->contact->value_ciphertext, FILTER_VALIDATE_EMAIL) || $pref->evidence_ciphertext['email'] !== $pref->contact->value_ciphertext || ! AccountParty::query()->where('debtor_id', $pref->customer_id)->where('debt_account_id', $pref->debt_account_id)->whereNull('valid_to')->exists()) {
                    throw ValidationException::withMessages(['revision' => 'Contact/account evidence is no longer current. Submit a new request.']);
                }
            }
            if ($decision === 'approved' && $current) {
                $current->forceFill(['is_current' => false, 'lock_version' => $current->lock_version + 1])->save();
            }
            $pref->forceFill(['review_status' => $decision, 'reviewed_by' => $actor->id, 'reviewed_at' => now(), 'review_reason_ciphertext' => trim($reason), 'is_current' => $decision === 'approved', 'lock_version' => $version + 1])->save();
            $this->record($actor, 'communication.preference_'.$decision, $pref, $current);

            return $pref;
        }, 3);
    }

    private function record(User $actor, string $action, CommunicationPreference $pref, ?CommunicationPreference $previous): void
    {
        $this->audit->record($actor, $action, $pref->public_id, ['status' => $pref->state, 'basis' => $pref->basis, 'customer_id' => $pref->customer->public_id, 'evidence_fingerprint' => hash('sha256', json_encode($pref->evidence_ciphertext, JSON_THROW_ON_ERROR))], module: 'M14', entityType: 'communication_preference', previous: $previous ? ['status' => $previous->state, 'basis' => $previous->basis] : ['status' => 'unknown']);
    }
}
