<?php

namespace App\Actions\Tasks;

use App\Enums\BatchStatus;
use App\Models\AssignmentBatch;
use App\Models\DebtAccount;
use App\Models\Debtor;
use App\Models\Task;
use App\Models\User;
use App\Services\Tasks\TaskHistory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** M05 / UI56: fixed source provenance and explicit metadata edits, no arbitrary status writes. */
class SaveTask
{
    public function handle(User $actor, array $input, ?Task $existing = null, ?int $version = null): Task
    {
        Validator::make($input, ['assignment_batch_id' => ['required', 'integer'], 'debt_account_id' => ['required', 'integer'], 'customer_id' => ['required', 'integer']])->validate();

        return DB::transaction(function () use ($actor, $input, $existing, $version): Task {
            $context = $existing ? AssignmentBatch::query()->findOrFail($existing->assignment_batch_id) : AssignmentBatch::query()->findOrFail($input['assignment_batch_id']);
            abort_unless($actor->isActive(), 404);
            $batch = AssignmentBatch::query()->lockForUpdate()->findOrFail($context->id);
            if ($batch->status !== BatchStatus::Active) {
                throw ValidationException::withMessages(['data.assignment_batch_id' => 'Archived assignments cannot accept task changes.']);
            }
            $task = $existing ? Task::query()->lockForUpdate()->findOrFail($existing->id) : new Task;
            Gate::forUser($actor)->authorize($existing ? 'update' : 'create', $existing ? $task : Task::class);
            if ($existing && ($version === null || $version !== $task->lock_version)) {
                throw ValidationException::withMessages(['version' => 'This task changed. Reload and review before saving.']);
            }
            if ($existing && in_array($task->work_status, ['submitted', 'completed', 'cancelled'], true)) {
                throw ValidationException::withMessages(['status' => 'Submitted, completed and cancelled tasks are read-only here.']);
            }
            if ($existing && ((int) $input['assignment_batch_id'] !== $task->assignment_batch_id || (int) $input['debt_account_id'] !== $task->debt_account_id || (int) $input['customer_id'] !== $task->customer_id)) {
                throw ValidationException::withMessages(['data.customer_id' => 'Task identity is fixed. Cancel and create a corrected task to change its source context.']);
            }
            $account = DebtAccount::query()->lockForUpdate()->findOrFail($input['debt_account_id']);
            $customer = Debtor::query()->lockForUpdate()->findOrFail($input['customer_id']);
            if (! $existing && ($account->status !== 'active' || $customer->status !== 'active' || ! $account->parties()->where('debtor_id', $customer->id)->where('valid_from', '<=', now()->format('Y-m-d H:i:s.uP'))->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>', now()->format('Y-m-d H:i:s.uP')))->exists())) {
                throw ValidationException::withMessages(['data.debt_account_id' => 'Select an active account with a current relationship to this customer.']);
            }
            $validator = Validator::make($input, ['priority_code' => ['required', Rule::exists('reference_values', 'code')->where('category', 'priority')->where('is_active', true)], 'scheduled_visit_at' => ['nullable', 'date_format:Y-m-d\TH:i'], 'due_at' => ['nullable', 'date_format:Y-m-d\TH:i'], 'instructions' => ['nullable', 'string', 'max:5000'], 'admin_remarks' => ['nullable', 'string', 'max:5000']]);
            if ($validator->fails()) {
                throw ValidationException::withMessages(collect($validator->errors()->toArray())->mapWithKeys(fn ($messages, $key) => ['data.'.$key => $messages])->all());
            } $data = $validator->validated();
            $visit = ($data['scheduled_visit_at'] ?? '') !== '' ? Carbon::createFromFormat('!Y-m-d\TH:i', $data['scheduled_visit_at'], config('dcms.business_timezone'))->utc() : null;
            $due = ($data['due_at'] ?? '') !== '' ? Carbon::createFromFormat('!Y-m-d\TH:i', $data['due_at'], config('dcms.business_timezone'))->utc() : null;
            $effectiveDue = $due ?? Carbon::parse($batch->due_date->format('Y-m-d'), config('dcms.business_timezone'))->endOfDay()->utc();
            if ($visit && $visit->gt($effectiveDue)) {
                throw ValidationException::withMessages(['data.due_at' => 'Due time must be on or after the planned visit (including the inherited batch due date).']);
            }
            $from = $existing ? $task->work_status : null;
            $review = $existing ? $task->review_status : null;
            if (! $existing) {
                $task->forceFill(['assignment_batch_id' => $batch->id, 'debt_account_id' => $account->id, 'customer_id' => $customer->id, 'type_code' => $batch->type_code, 'created_by' => $actor->id, 'origin_code' => 'manual', 'work_status' => 'unstarted', 'review_status' => 'none', 'lock_version' => 0, 'source_snapshot_ciphertext' => ['origin' => 'manual', 'schema_version' => 1, 'customer_reference' => $customer->customer_reference, 'account_number' => $account->account_number, 'property' => $account->property?->address_lines_ciphertext, 'batch' => $batch->public_id, 'created_by' => $actor->public_id, 'created_at' => now()->toIso8601String()]]);
            } else {
                $task->lock_version++;
            }
            $task->forceFill(['priority_code' => $data['priority_code'], 'scheduled_visit_at' => $visit, 'due_at' => $due, 'special_instruction_ciphertext' => trim($data['instructions'] ?? '') ?: null, 'admin_remarks_ciphertext' => trim($data['admin_remarks'] ?? '') ?: null, 'last_updated_by' => $actor->id])->save();
            app(TaskHistory::class)->record($actor, $task, $existing ? 'updated' : 'created', $from, $review);

            return $task->refresh();
        }, 3);
    }
}
