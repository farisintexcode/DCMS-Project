<?php

namespace App\Actions\Customers;

use App\Models\CustomerCategory;
use App\Models\User;
use App\Services\AuditWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SaveCategory
{
    public function __construct(private AuditWriter $audit) {}

    public function handle(User $actor, array $input, ?CustomerCategory $existing = null, ?string $revision = null): CustomerCategory
    {
        return DB::transaction(function () use ($actor, $input, $existing, $revision): CustomerCategory {
            abort_unless($actor->isActive(), 404);
            Gate::forUser($actor)->authorize('manage', CustomerCategory::class);
            $category = $existing ? CustomerCategory::query()->lockForUpdate()->findOrFail($existing->id) : new CustomerCategory;
            if ($existing && (! $revision || ! hash_equals($category->revision(), $revision))) {
                throw ValidationException::withMessages(['revision' => 'This category changed. Reload and review before saving.']);
            }
            $input['code'] = is_string($input['code'] ?? '') ? trim($input['code'] ?? '') : $input['code'];
            $input['name'] = is_string($input['name'] ?? '') ? trim($input['name'] ?? '') : $input['name'];
            $validator = Validator::make($input, [
                'code' => ['required', 'string', 'max:64', 'regex:/^[a-zA-Z0-9_-]+$/', Rule::unique('customer_categories')->ignore($category->id)],
                'name' => ['required', 'string', 'max:160'], 'description' => ['nullable', 'string', 'max:2000'], 'is_active' => ['required', 'boolean'],
            ]);
            if ($validator->fails()) {
                throw ValidationException::withMessages(collect($validator->errors()->toArray())->mapWithKeys(fn ($m, $k) => ['data.'.$k => $m])->all());
            }
            $before = $existing ? ['is_active' => $category->is_active] : [];
            foreach ($validator->validated() as $key => $value) {
                $category->$key = $value;
            }
            $category->save();
            $this->audit->record($actor, $existing ? 'category_updated' : 'category_created', $category->public_id, ['is_active' => $category->is_active, 'changed_fields' => array_keys($validator->validated())], module: 'M13', entityType: 'customer_category', previous: $before);

            return $category->refresh();
        }, 3);
    }
}
