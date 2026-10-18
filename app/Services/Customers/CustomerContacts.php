<?php

namespace App\Services\Customers;

use App\Models\Contact;
use App\Models\Debtor;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/** Shared M13/M11 contact validation and observation persistence; callers own locks and authorization. */
class CustomerContacts
{
    public function __construct(private CustomerSearch $search) {}

    private function invalid(array $errors): never
    {
        throw ValidationException::withMessages(collect($errors)->mapWithKeys(fn ($messages, $key) => ['data.'.$key => $messages])->all());
    }

    public function validate(array $contacts): void
    {
        $primary = [];
        $seen = [];
        foreach ($contacts as $index => $contact) {
            $value = trim($contact['value']);
            if ($contact['kind'] === 'email' ? ! filter_var($value, FILTER_VALIDATE_EMAIL) : ! preg_match('/^[+0-9][0-9 ()\-.]{4,39}$/', $value)) {
                $this->invalid(["contacts.$index.value" => ['Enter a valid email address or telephone number for the selected type.']]);
            }
            $key = $contact['kind'].':'.$this->search->contact($contact['kind'], $value);
            if (in_array($key, $seen, true)) {
                $this->invalid(["contacts.$index.value" => ['Duplicate contact in this customer.']]);
            }
            $seen[] = $key;
            if ($contact['is_primary'] && isset($primary[$contact['kind']])) {
                $this->invalid(["contacts.$index.is_primary" => ['Choose only one primary contact per type.']]);
            }
            if ($contact['is_primary']) {
                $primary[$contact['kind']] = true;
            }
        }
    }

    public function sync(Debtor $customer, array $items, User $actor, string $source = 'admin'): void
    {
        $current = $customer->contacts()->whereNull('valid_to')->get();
        $retained = [];
        foreach ($items as $item) {
            $value = trim($item['value']);
            $match = $current->first(fn ($row) => $row->kind_code === $item['kind'] && $row->value_ciphertext === $value && $row->is_primary === $item['is_primary'] && ! in_array($row->id, $retained));
            if ($match) {
                $retained[] = $match->id;

                continue;
            }
            $row = new Contact;
            $row->debtor_id = $customer->id;
            $row->kind_code = $item['kind'];
            $row->value_ciphertext = $value;
            $row->value_fingerprint = $this->search->token('contact:'.$item['kind'], $this->search->contact($item['kind'], $value));
            $row->is_primary = $item['is_primary'];
            $row->source = $source.':'.$actor->public_id;
            $row->valid_from = now();
            $row->save();
        }
        $instant = now()->format('Y-m-d H:i:s.uP');
        $customer->contacts()->whereIn('id', $current->pluck('id')->diff($retained))->update(['valid_to' => $instant, 'updated_at' => $instant]);
    }
}
