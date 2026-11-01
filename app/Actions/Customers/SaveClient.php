<?php

namespace App\Actions\Customers;

use App\Models\Client;
use App\Models\User;
use App\Models\UserClientScope;
use App\Services\AuditWriter;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** M12 client onboarding: the creator receives an explicit, audited portfolio scope for each new client only. */
class SaveClient
{
    public function __construct(private AuditWriter $audit) {}

    /**
     * @param  array{code?: mixed, name?: mixed, category?: mixed}  $input
     */
    public function handle(User $actor, array $input): Client
    {
        $this->authorize($actor);
        $validator = $this->validator($input);
        if ($validator->fails()) {
            throw ValidationException::withMessages(collect($validator->errors()->toArray())->mapWithKeys(fn ($m, $k) => ['data.'.$k => $m])->all());
        }

        return DB::transaction(fn (): Client => $this->create($actor, $validator->validated(), 'manual'), 3);
    }

    /**
     * Creates every row or none, so a partly valid workbook never leaves half a portfolio behind.
     *
     * @param  array<int, array{code?: mixed, name?: mixed, category?: mixed}>  $rows  keyed by workbook row number
     * @return Collection<int, Client>
     */
    public function handleMany(User $actor, array $rows): Collection
    {
        $this->authorize($actor);
        if ($rows === [] || count($rows) > config('imports.max_rows')) {
            throw ValidationException::withMessages(['upload' => 'The workbook must contain between 1 and '.config('imports.max_rows').' client rows.']);
        }
        $errors = $this->rowErrors($rows);
        if ($errors !== []) {
            throw ValidationException::withMessages(['upload' => $errors]);
        }

        return DB::transaction(fn (): Collection => collect($rows)->map(fn (array $row): Client => $this->create($actor, $this->validator($row)->validated(), 'workbook')), 3);
    }

    /**
     * @param  array<int, array{code?: mixed, name?: mixed, category?: mixed}>  $rows
     * @return array<int, string>
     */
    public function rowErrors(array $rows): array
    {
        $errors = [];
        $seen = [];
        foreach ($rows as $number => $row) {
            $validator = $this->validator($row);
            foreach ($validator->errors()->all() as $message) {
                $errors[] = "Row {$number}: {$message}";
            }
            $code = mb_strtolower(trim((string) ($row['code'] ?? '')));
            if ($code !== '' && isset($seen[$code])) {
                $errors[] = "Row {$number}: client code {$row['code']} is repeated from row {$seen[$code]}.";
            }
            $seen[$code] ??= $number;
        }

        return $errors;
    }

    private function authorize(User $actor): void
    {
        abort_unless($actor->isActive(), 403);
        Gate::forUser($actor)->authorize('scopes.manage');
    }

    private function validator(array $input): \Illuminate\Validation\Validator
    {
        foreach (['code', 'name', 'category'] as $field) {
            $input[$field] = is_string($input[$field] ?? null) ? trim(preg_replace('/\s+/u', ' ', $input[$field])) : ($input[$field] ?? null);
        }
        $input['category'] = $input['category'] === '' ? null : $input['category'];
        if (is_string($input['category'])) {
            $input['category'] = Client::query()->whereRaw('lower(category) = ?', [mb_strtolower($input['category'])])->value('category') ?? $input['category'];
        }

        return Validator::make($input, [
            'code' => ['required', 'string', 'max:50', 'regex:/^[a-zA-Z0-9_-]+$/', 'unique:clients,code'],
            'name' => ['required', 'string', 'max:200'],
            'category' => ['nullable', 'string', 'max:100'],
        ], ['code.regex' => 'The client code may only contain letters, numbers, dashes and underscores.']);
    }

    /**
     * @param  array{code: string, name: string, category: ?string}  $data
     */
    private function create(User $actor, array $data, string $source): Client
    {
        $client = Client::query()->create($data);
        $scope = new UserClientScope;
        $scope->user_id = $actor->id;
        $scope->client_id = $client->id;
        $scope->granted_by = $actor->id;
        $scope->reason = 'Creator access on client onboarding';
        $scope->valid_from = now();
        $scope->save();
        $this->audit->record($actor, 'client_created', $client->public_id, ['source' => $source], module: 'M12', entityType: 'client');
        $this->audit->record($actor, 'scope_granted', $scope->public_id, module: 'M12', entityType: 'user_client_scope');

        return $client;
    }
}
