<?php

namespace App\Actions\Customers;

use App\Models\Address;
use App\Models\Client;
use App\Models\Contact;
use App\Models\Debtor;
use App\Models\User;
use App\Services\AuditWriter;
use App\Services\Customers\CustomerContacts;
use App\Services\Customers\CustomerSearch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Plan.md customer contract / M13 / UI63 / Database.md party/contact/category tables. */
class SaveCustomer
{
    public function __construct(private CustomerSearch $search, private AuditWriter $audit, private SyncCustomerCategories $categories, private CustomerContacts $contacts) {}

    public function handle(User $actor, array $input, ?Debtor $existing = null, ?string $revision = null): Debtor
    {
        return DB::transaction(function () use ($actor, $input, $existing, $revision): Debtor {
            $clientId = $existing?->client_id ?? (is_scalar($input['client_id'] ?? null) ? filter_var($input['client_id'], FILTER_VALIDATE_INT) : 0);
            $client = Client::query()->lockForUpdate()->findOrFail($clientId ?: 0);
            abort_unless($actor->hasClientScope($client), 404);
            $customer = $existing ? Debtor::query()->with('client')->lockForUpdate()->findOrFail($existing->id) : new Debtor;
            Gate::forUser($actor)->authorize($existing ? 'update' : 'create', $existing ? $customer : [Debtor::class, $client]);
            if ($existing && (! $revision || ! hash_equals($customer->revision(), $revision))) {
                throw ValidationException::withMessages(['revision' => 'This customer changed after you opened the form. Reload and review before saving.']);
            }
            if (isset($input['client_id']) && (int) $input['client_id'] !== $client->id) {
                throw ValidationException::withMessages(['data.client_id' => 'The customer’s client cannot be changed.']);
            }
            $input['customer_reference'] = is_string($input['customer_reference'] ?? '') ? trim($input['customer_reference'] ?? '') : $input['customer_reference'];
            $input['name'] = is_string($input['name'] ?? '') ? trim($input['name'] ?? '') : $input['name'];
            $rules = [
                'customer_reference' => ['required', 'string', 'max:80', Rule::unique('debtors')->where('client_id', $client->id)->ignore($customer->id)],
                'party_type' => ['required', Rule::in(['person', 'company'])],
                'name' => ['required', 'string', 'max:200'],
                'identity' => ['nullable', 'string', 'max:100', 'regex:/^[\p{L}\p{N}\s.\/-]+$/u'],
                'clear_identity' => ['sometimes', 'boolean'],
                'duplicate_confirmed' => ['sometimes', 'boolean'],
                'status' => ['required', Rule::in(['active', 'inactive'])],
                'contacts' => ['sometimes', 'array', 'max:20'],
                'contacts.*' => ['array:kind,value,is_primary'],
                'contacts.*.kind' => ['required', Rule::in(['telephone', 'mobile', 'fax', 'email'])],
                'contacts.*.value' => ['required', 'string', 'max:254'],
                'contacts.*.is_primary' => ['required', 'boolean'],
                'addresses' => ['sometimes', 'array', 'max:10'],
                'addresses.*' => ['array:kind,recipient,lines,postcode,state_code'],
                'addresses.*.kind' => ['required', Rule::in(['billing', 'contact'])],
                'addresses.*.recipient' => ['nullable', 'string', 'max:200'],
                'addresses.*.lines' => ['required', 'string', 'max:2000'],
                'addresses.*.postcode' => ['nullable', 'string', 'max:20'],
                'addresses.*.state_code' => ['nullable', Rule::exists('reference_values', 'code')->where('category', 'state')->where('is_active', true)],
                'category_ids' => ['sometimes', 'array', 'max:100'],
                'category_ids.*' => ['integer', 'distinct'],
            ];
            $validator = Validator::make($input, $rules);
            if ($validator->fails()) {
                $this->invalid($validator->errors()->toArray());
            }
            $data = $validator->validated();
            if (array_key_exists('contacts', $data)) {
                abort_unless($actor->can('customers.update_contacts'), 403);
                $this->contacts->validate($data['contacts']);
            }
            $before = $existing ? ['status' => $customer->status, 'party_type' => $customer->party_type] : [];
            $customer->client_id = $client->id;
            $customer->setRelation('client', $client);
            $customer->customer_reference = $data['customer_reference'];
            $customer->party_type = $data['party_type'];
            $customer->display_name_ciphertext = $data['name'];
            $customer->search_tokens = $this->search->names($data['name']);
            $customer->status = $data['status'];
            $identity = trim($data['identity'] ?? '');
            if ($identity !== '') {
                $fingerprint = $this->search->token('identity', $this->search->identity($identity));
                $duplicate = Debtor::query()->where('client_id', $client->id)->where('identity_fingerprint', $fingerprint)->when($customer->exists, fn ($q) => $q->where('id', '!=', $customer->id))->exists();
                if ($duplicate && ! ($data['duplicate_confirmed'] ?? false) && $customer->identity_fingerprint !== $fingerprint) {
                    $this->invalid(['identity' => ['This identifier matches an existing customer in this client. Review the duplicate warning before keeping a separate customer.']]);
                }
                $customer->identity_ciphertext = $identity;
                $customer->identity_fingerprint = $fingerprint;
            } elseif ($data['clear_identity'] ?? false) {
                $customer->identity_ciphertext = null;
                $customer->identity_fingerprint = null;
            }
            $customer->save();
            if (array_key_exists('contacts', $data)) {
                $this->contacts->sync($customer, $data['contacts'], $actor);
            }
            if (array_key_exists('addresses', $data)) {
                $this->saveAddresses($customer, $data['addresses'], $actor);
            }
            if (array_key_exists('category_ids', $data)) {
                $this->categories->handle($actor, $customer, $data['category_ids']);
            }
            $this->audit->record($actor, $existing ? 'customer_updated' : 'customer_created', $customer->public_id,
                ['status' => $customer->status, 'party_type' => $customer->party_type, 'changed_fields' => array_keys($data), 'duplicate_reviewed' => (bool) ($data['duplicate_confirmed'] ?? false)],
                module: 'M13', entityType: 'debtor', previous: $before);

            return $customer->refresh();
        }, 3);
    }

    private function invalid(array $errors): never
    {
        throw ValidationException::withMessages(collect($errors)->mapWithKeys(fn ($messages, $key) => ['data.'.$key => $messages])->all());
    }

    private function saveAddresses(Debtor $customer, array $items, User $actor): void
    {
        $current = $customer->addresses()->whereNull('valid_to')->get();
        $retained = [];
        foreach ($items as $item) {
            $attributes = ['kind_code' => $item['kind'], 'recipient_name_ciphertext' => trim($item['recipient'] ?? '') ?: null, 'lines_ciphertext' => trim($item['lines']), 'postcode' => trim($item['postcode'] ?? '') ?: null, 'state_code' => ($item['state_code'] ?? '') ?: null];
            $match = $current->first(function ($row) use ($attributes, $retained): bool {
                foreach ($attributes as $key => $value) {
                    if ($value !== $row->$key) {
                        return false;
                    }
                }

                return ! in_array($row->id, $retained);
            });
            if ($match) {
                $retained[] = $match->id;

                continue;
            }
            $row = new Address;
            foreach ($attributes as $key => $value) {
                $row->$key = $value;
            }
            $row->debtor_id = $customer->id;
            $row->source = 'admin:'.$actor->public_id;
            $row->valid_from = now();
            $row->save();
        }
        $instant = now()->format('Y-m-d H:i:s.uP');
        $customer->addresses()->whereIn('id', $current->pluck('id')->diff($retained))->update(['valid_to' => $instant, 'updated_at' => $instant]);
    }
}
