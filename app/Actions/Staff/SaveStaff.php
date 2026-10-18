<?php

namespace App\Actions\Staff;

use App\Enums\AccountStatus;
use App\Models\Staff;
use App\Models\User;
use App\Services\AuditWriter;
use App\Services\Staff\StaffAssignments;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** M10 / UI31–32 / Database.md staff. No personnel update grants account permissions. */
class SaveStaff
{
    public function __construct(private AuditWriter $audit, private StaffAssignments $assignments) {}

    public function handle(User $actor, array $input, ?Staff $existing = null, ?string $revision = null): Staff
    {
        try {
            return DB::transaction(function () use ($actor, $input, $existing, $revision): Staff {
                DB::select("select pg_advisory_xact_lock(hashtext('dcms.staff.availability'))");
                $staff = $existing ? Staff::query()->lockForUpdate()->findOrFail($existing->id) : new Staff;
                Gate::forUser($actor)->authorize($existing ? 'update' : 'create', $existing ? $staff : Staff::class);
                if ($existing && (! $revision || ! hash_equals($staff->revision(), $revision))) {
                    throw ValidationException::withMessages(['revision' => 'This staff record changed. Reload and review before saving.']);
                }
                foreach (['staff_no', 'first_name', 'last_name', 'email', 'phone', 'home_phone', 'address', 'reason'] as $field) {
                    if (is_string($input[$field] ?? null)) {
                        $input[$field] = trim($input[$field]);
                    }
                }
                if (is_string($input['email'] ?? null)) {
                    $input['email'] = mb_strtolower($input['email']);
                }
                $validator = Validator::make($input, [
                    'staff_no' => ['required', 'string', 'max:50', Rule::unique('staff', 'staff_no')->ignore($staff->id)],
                    'first_name' => ['required', 'string', 'max:100'], 'last_name' => ['nullable', 'string', 'max:100'],
                    'email' => ['nullable', 'email', 'max:254'],
                    'gender_code' => ['nullable', 'string', Rule::exists('reference_values', 'code')->where('category', 'gender')->where(fn ($q) => $q->when(! $staff->exists || ($input['gender_code'] ?? null) !== $staff->gender_code, fn ($q) => $q->where('is_active', true)))],
                    'position_code' => ['required', 'string', Rule::exists('reference_values', 'code')->where('category', 'position')->where(fn ($q) => $q->when(! $staff->exists || ($input['position_code'] ?? null) !== $staff->position_code, fn ($q) => $q->where('is_active', true)))],
                    'appointment_date' => ['nullable', 'date_format:Y-m-d'],
                    'phone' => ['nullable', 'string', 'max:40', 'regex:/^[+0-9][0-9 ()\-.]{4,39}$/'],
                    'home_phone' => ['nullable', 'string', 'max:40', 'regex:/^[+0-9][0-9 ()\-.]{4,39}$/'],
                    'address' => ['nullable', 'string', 'max:2000'], 'is_active' => ['required', 'boolean'],
                    'is_rider' => ['required', 'boolean'], 'reason' => ['nullable', 'string', 'max:1000'],
                ]);
                if ($validator->fails()) {
                    throw ValidationException::withMessages(collect($validator->errors()->toArray())->mapWithKeys(fn ($messages, $key) => ['data.'.$key => $messages])->all());
                }
                $data = $validator->validated();
                $availabilityReduced = $existing && (($staff->is_active && ! $data['is_active']) || ($staff->is_rider && ! $data['is_rider']));
                if ($availabilityReduced) {
                    if (! ($data['reason'] ?? null)) {
                        throw ValidationException::withMessages(['data.reason' => 'Explain the status or Rider eligibility change.']);
                    }
                    if ($actor->staff_id === $staff->id) {
                        throw ValidationException::withMessages(['data.is_active' => 'You cannot remove your own availability. Ask another authorized administrator.']);
                    }
                    if (($this->assignments->outstanding($staff) ?? 0) > 0) {
                        throw ValidationException::withMessages(['data.is_active' => 'Outstanding current tasks must be reassigned or resolved before disabling this staff member or removing Rider eligibility.']);
                    }
                    $account = $staff->user()->first();
                    if (! $data['is_active'] && $account?->can('admin.access') && ! User::query()->whereKeyNot($account->id)->where('status', 'active')->permission('admin.access')->where(fn ($q) => $q->whereNull('staff_id')->orWhereHas('staff', fn ($q) => $q->where('is_active', true)))->exists()) {
                        throw ValidationException::withMessages(['data.is_active' => 'The last active administrator cannot be deactivated.']);
                    }
                }
                $before = $staff->exists ? ['is_active' => $staff->is_active, 'is_rider' => $staff->is_rider] : [];
                if ($staff->exists) {
                    $account = $staff->user()->first();
                    if ($account && ($availabilityReduced || ($account->status === AccountStatus::Invited && $staff->email !== (($data['email'] ?? null) ?: null)))) {
                        DB::table('password_reset_tokens')->where('email', $account->email)->delete();
                    }
                }
                foreach (['staff_no', 'first_name', 'last_name', 'email', 'gender_code', 'position_code', 'appointment_date'] as $field) {
                    $staff->{$field} = ($data[$field] ?? null) === '' ? null : ($data[$field] ?? null);
                }
                foreach (['phone', 'home_phone', 'address'] as $field) {
                    $staff->{$field.'_ciphertext'} = ($data[$field] ?? '') ?: null;
                }
                $staff->is_active = (bool) $data['is_active'];
                $staff->is_rider = (bool) $data['is_rider'];
                $staff->save();
                $this->audit->record($actor, $existing ? 'staff_updated' : 'staff_created', $staff->public_id,
                    ['is_active' => $staff->is_active, 'is_rider' => $staff->is_rider, 'changed_fields' => array_keys($data)],
                    reason: $availabilityReduced ? $data['reason'] : null, module: 'M10', entityType: 'staff', previous: $before);

                return $staff->refresh();
            }, 3);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['data.staff_no' => 'This staff number is already in use.']);
        }
    }
}
