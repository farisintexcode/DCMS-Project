<?php

namespace App\Actions\Accounts;

use App\Models\AccountParty;
use App\Models\DebtAccount;
use App\Models\Debtor;
use App\Models\Property;
use App\Models\User;
use App\Services\AuditWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** M05 prerequisite / UI24 / Database.md properties, debt_accounts, account_parties. */
class SaveDebtAccount
{
    public function handle(User $actor, Debtor $customer, array $input, ?DebtAccount $existing = null, ?string $revision = null): DebtAccount
    {
        return DB::transaction(function () use ($actor, $customer, $input, $existing, $revision): DebtAccount {
            abort_unless($actor->isActive(), 404);
            $customer = Debtor::query()->lockForUpdate()->findOrFail($customer->id);
            $account = $existing ? DebtAccount::query()->with('property')->lockForUpdate()->findOrFail($existing->id) : new DebtAccount;
            if ($existing) {
                abort_unless($account->parties()->where('debtor_id', $customer->id)->exists(), 404);
                Gate::forUser($actor)->authorize('update', $account);
                if (! $revision || ! hash_equals($account->revision(), $revision)) {
                    throw ValidationException::withMessages(['revision' => 'This account changed. Reload before saving.']);
                }
            } else {
                Gate::forUser($actor)->authorize('create', DebtAccount::class);
            }
            foreach ($input as $key => $value) {
                if (is_string($value)) {
                    $input[$key] = trim($value);
                }
            }
            $rules = ['account_number' => ['required', 'string', 'max:100', Rule::unique('debt_accounts')->ignore($account->id)], 'billing_reference' => ['nullable', 'string', 'max:160'], 'currency' => ['required', 'string', 'regex:/^[A-Z]{3}$/'], 'status' => ['required', Rule::in(['active', 'closed', 'disputed'])], 'role_code' => ['required', Rule::exists('reference_values', 'code')->where('category', 'party_role')->where('is_active', true)], 'address' => ['nullable', 'string', 'max:2000'], 'postcode' => ['nullable', 'string', 'max:20'], 'building' => ['nullable', 'string', 'max:200'], 'state_code' => ['nullable', Rule::exists('reference_values', 'code')->where('category', 'state')->where('is_active', true)]];
            $validator = Validator::make($input, $rules);
            if ($validator->fails()) {
                throw ValidationException::withMessages(collect($validator->errors()->toArray())->mapWithKeys(fn ($messages, $key) => ['data.'.$key => $messages])->all());
            } $data = $validator->validated();
            $property = $account->property_id ? Property::query()->lockForUpdate()->findOrFail($account->property_id) : new Property;
            if (($data['address'] ?? '') !== '' || $property->exists || ($data['postcode'] ?? '') !== '' || ($data['building'] ?? '') !== '' || ($data['state_code'] ?? '') !== '') {
                $property->forceFill(['address_lines_ciphertext' => ($data['address'] ?? '') ?: null, 'postcode' => ($data['postcode'] ?? '') ?: null, 'building' => ($data['building'] ?? '') ?: null, 'state_code' => ($data['state_code'] ?? '') ?: null])->save();
                $account->property_id = $property->id;
            }
            $account->forceFill(['account_number' => $data['account_number'], 'billing_reference' => ($data['billing_reference'] ?? '') ?: null, 'currency' => $data['currency'], 'status' => $data['status']])->save();
            $current = $account->parties()->where('debtor_id', $customer->id)->whereNull('valid_to')->whereNull('source_slot')->get();
            if (! $current->contains('role_code', $data['role_code'])) {
                foreach ($current as $party) {
                    $party->valid_to = now();
                    $party->save();
                }
                $party = new AccountParty;
                $party->forceFill(['debt_account_id' => $account->id, 'debtor_id' => $customer->id, 'role_code' => $data['role_code'], 'valid_from' => now()])->save();
            }
            app(AuditWriter::class)->record($actor, $existing ? 'account_updated' : 'account_created', $account->public_id, ['status' => $account->status, 'changed_fields' => array_keys($data)], module: 'M05', entityType: 'debt_account');

            return $account->refresh();
        }, 3);
    }
}
