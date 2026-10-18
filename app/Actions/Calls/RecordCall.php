<?php

namespace App\Actions\Calls;

use App\Models\Client;
use App\Models\CollectionActivity;
use App\Models\Contact;
use App\Models\Debtor;
use App\Models\FollowUp;
use App\Models\ReferenceValue;
use App\Models\Task;
use App\Models\User;
use App\Services\AuditWriter;
use App\Services\Calls\CallAccess;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Plan.md communication; Module.md M11; UI60; existing activity/reminder entities. */
class RecordCall
{
    public function __construct(private CallAccess $access, private AuditWriter $audit) {}

    public function handle(User $actor, Debtor $target, array $input, string $key): CollectionActivity
    {
        return DB::transaction(function () use ($actor, $target, $input, $key): CollectionActivity {
            Client::query()->lockForUpdate()->findOrFail($target->client_id);
            $actor = $actor->fresh();
            $customer = $this->access->customer($actor, $target->id);
            $customer = Debtor::query()->lockForUpdate()->findOrFail($customer->id);
            Validator::make(['key' => $key], ['key' => ['required', 'uuid']])->validate();
            $prior = CollectionActivity::query()->where('idempotency_key', $key)->first();
            if ($prior) {
                abort_unless($prior->performed_by === $actor->id && $prior->customer_id === $customer->id && $prior->activity_type_code === 'call', 403);

                return $prior;
            }
            $data = Validator::make($input, [
                'task' => ['nullable', 'uuid'], 'contact' => ['nullable', 'uuid'], 'outcome' => ['required', 'string', 'max:64'],
                'historical' => ['required', 'boolean'], 'occurred_at' => ['nullable', 'required_if:historical,1', 'date_format:Y-m-d\\TH:i'],
                'backdating_reason' => ['nullable', 'string', 'max:2000'], 'person' => ['nullable', 'string', 'max:200'],
                'remarks' => ['required', 'string', 'max:5000'], 'other_detail' => ['nullable', 'required_if:outcome,other', 'string', 'max:2000'],
                'promise_notes' => ['nullable', 'required_if:outcome,promise_to_pay', 'string', 'max:2000'],
                'promised_date' => ['nullable', 'date_format:Y-m-d'], 'promised_amount' => ['nullable', 'numeric', 'gt:0', 'regex:/^\\d{1,14}(\\.\\d{1,2})?$/'],
                'currency' => ['nullable', 'required_with:promised_amount', 'regex:/^[A-Z]{3}$/'],
                'follow_up_at' => ['nullable', 'required_if:outcome,call_back_requested,promise_to_pay', 'date_format:Y-m-d\\TH:i'],
                'follow_up_notes' => ['nullable', 'string', 'max:2000'],
            ])->validate();
            foreach (['remarks', 'other_detail', 'promise_notes'] as $field) {
                if (in_array($field, ['remarks'], true) || ($field === 'other_detail' && $data['outcome'] === 'other') || ($field === 'promise_notes' && $data['outcome'] === 'promise_to_pay')) {
                    if (trim($data[$field] ?? '') === '') {
                        throw ValidationException::withMessages([$field => 'Please provide the required details.']);
                    }
                }
            }
            $reference = ReferenceValue::query()->where('category', 'activity_outcome')->where('code', $data['outcome'])->where('is_active', true)->first();
            if (! $reference || ! in_array('call', $reference->metadata['activity_types'] ?? [], true)) {
                throw ValidationException::withMessages(['outcome' => 'Choose an active approved call outcome.']);
            }
            if (! ReferenceValue::query()->where('category', 'activity_type')->where('code', 'call')->where('is_active', true)->exists()) {
                throw ValidationException::withMessages(['outcome' => 'Call recording is disabled in reference configuration.']);
            }
            $now = now()->toImmutable();
            $tz = config('dcms.business_timezone');
            $occurred = $now;
            $reason = null;
            if ($data['historical']) {
                $occurred = CarbonImmutable::createFromFormat('!Y-m-d\\TH:i', $data['occurred_at'], $tz)->utc();
                $reason = trim($data['backdating_reason'] ?? '');
                if ($occurred->greaterThan($now) || $occurred->timezone($tz)->startOfDay()->lessThan($now->timezone($tz)->startOfDay()->subDay()) || $reason === '') {
                    throw ValidationException::withMessages(['occurred_at' => 'Historical calls must be today or yesterday, not in the future, with a backdating reason.']);
                }
            }
            $task = null;
            if (! empty($data['task'])) {
                $task = Task::query()->where('public_id', $data['task'])->where('customer_id', $customer->id)->with('batch.client')->firstOrFail();
                Gate::forUser($actor)->authorize('view', $task);
            }
            $contact = null;
            if (! empty($data['contact'])) {
                $contact = Contact::query()->where('public_id', $data['contact'])->where('debtor_id', $customer->id)->whereNull('valid_to')->whereIn('kind_code', ['mobile', 'telephone'])->firstOrFail();
            }
            $due = empty($data['follow_up_at']) ? null : CarbonImmutable::createFromFormat('!Y-m-d\\TH:i', $data['follow_up_at'], $tz)->utc();
            if ($due !== null && $due->lessThanOrEqualTo($occurred)) {
                throw ValidationException::withMessages(['follow_up_at' => 'Follow-up must be later than the recorded call.']);
            }
            $details = ['version' => 1, 'direction' => 'outgoing', 'mode' => 'manual', 'outcome_label' => $reference->label, 'contact' => $contact ? ['public_id' => $contact->public_id, 'kind' => $contact->kind_code, 'value' => $contact->value_ciphertext] : null, 'other_detail' => $data['outcome'] === 'other' ? trim($data['other_detail']) : null,
                'promise' => $data['outcome'] === 'promise_to_pay' ? ['notes' => trim($data['promise_notes']), 'stated_date' => $data['promised_date'] ?? null, 'stated_amount' => isset($data['promised_amount']) ? (string) $data['promised_amount'] : null, 'currency' => $data['currency'] ?? null] : null];
            $activity = new CollectionActivity;
            $activity->forceFill(['customer_id' => $customer->id, 'task_id' => $task?->id, 'performed_by' => $actor->id, 'activity_type_code' => 'call', 'contact_id' => $contact?->id, 'occurred_at' => $occurred, 'outcome_code' => $reference->code, 'person_contacted_ciphertext' => trim($data['person'] ?? '') ?: null, 'notes_ciphertext' => trim($data['remarks']), 'backdating_reason_ciphertext' => $reason, 'call_details_ciphertext' => $details, 'idempotency_key' => $key])->save();
            if ($due !== null) {
                $follow = new FollowUp;
                $follow->forceFill(['customer_id' => $customer->id, 'task_id' => $task?->id, 'activity_id' => $activity->id, 'assigned_to' => $actor->id, 'due_at' => $due, 'type_code' => 'reminder', 'status' => 'pending', 'notes_ciphertext' => trim($data['follow_up_notes'] ?? '') ?: null])->save();
            }
            $this->audit->record($actor, 'call.recorded', $activity->public_id, ['status' => $data['outcome']], module: 'M11', entityType: 'activity');
            if ($reason !== null) {
                $this->audit->record($actor, 'call.backdated', $activity->public_id, ['changed_fields' => ['occurred_at', 'backdating_reason']], module: 'M11', entityType: 'activity');
            }

            return $activity;
        }, 3);
    }
}
