<?php

namespace App\Actions\Customers;

use App\Models\Debtor;
use App\Models\User;
use App\Services\AuditWriter;
use App\Services\Customers\CustomerContacts;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class SaveCustomerContacts
{
    public function __construct(private CustomerContacts $contacts, private AuditWriter $audit) {}

    /** M11/UI60 contact-only edit: never grants unrelated customer writes. */
    public function handle(User $actor, Debtor $target, array $items, string $revision): Debtor
    {
        return DB::transaction(function () use ($actor, $target, $items, $revision): Debtor {
            $actor = $actor->fresh();
            $customer = Debtor::query()->lockForUpdate()->findOrFail($target->id);
            abort_unless($actor->isActive(), 404);
            Gate::forUser($actor)->authorize('updateContacts', $customer);
            if (! hash_equals($customer->revision(), $revision)) {
                throw ValidationException::withMessages(['revision' => 'Customer contacts changed. Reload and review before saving.']);
            }
            Validator::make(['data' => ['contacts' => $items]], ['data.contacts' => ['present', 'array', 'max:20'], 'data.contacts.*' => ['array:kind,value,is_primary'], 'data.contacts.*.kind' => ['required', 'in:telephone,mobile,fax,email'], 'data.contacts.*.value' => ['required', 'string', 'max:254'], 'data.contacts.*.is_primary' => ['required', 'boolean']])->validate();
            $this->contacts->validate($items);
            $this->contacts->sync($customer, $items, $actor, 'call_centre');
            $customer->touch();
            $this->audit->record($actor, 'customer_contacts_updated', $customer->public_id, ['changed_fields' => ['contacts']], module: 'M13', entityType: 'debtor');

            return $customer->refresh();
        }, 3);
    }
}
