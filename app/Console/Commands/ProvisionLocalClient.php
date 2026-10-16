<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\User;
use App\Models\UserClientScope;
use App\Services\AuditWriter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/** Local onboarding bridge until M12 client/scope administration is implemented. */
class ProvisionLocalClient extends Command
{
    protected $signature = 'dcms:provision-local-client {code} {name} {adminEmail}';

    protected $description = 'Create a local commissioning client and explicit scope for an authorized local Admin';

    public function handle(AuditWriter $audit): int
    {
        if (! app()->environment('local')) {
            $this->error('Local development only.');

            return self::FAILURE;
        }
        $actor = User::query()->where('email', mb_strtolower(trim($this->argument('adminEmail'))))->first();
        if (! $actor?->isActive() || ! $actor->can('scopes.manage')) {
            $this->error('An active account with scopes.manage is required.');

            return self::FAILURE;
        }
        $data = ['code' => trim($this->argument('code')), 'name' => trim($this->argument('name'))];
        $validator = Validator::make($data, ['code' => ['required', 'string', 'max:50', 'unique:clients,code'], 'name' => ['required', 'string', 'max:200']]);
        if ($validator->fails()) {
            $this->error($validator->errors()->first());

            return self::FAILURE;
        }
        DB::transaction(function () use ($data, $actor, $audit): void {
            $client = Client::query()->create($data);
            $scope = new UserClientScope;
            $scope->user_id = $actor->id;
            $scope->client_id = $client->id;
            $scope->granted_by = $actor->id;
            $scope->reason = 'Explicit local client onboarding';
            $scope->valid_from = now();
            $scope->save();
            $audit->record($actor, 'client_created', $client->public_id, module: 'M12', entityType: 'client');
            $audit->record($actor, 'scope_granted', $scope->public_id, module: 'M12', entityType: 'user_client_scope');
        });
        $this->info('Local client and explicit Admin portfolio scope created.');

        return self::SUCCESS;
    }
}
