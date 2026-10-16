<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\Debtor;
use App\Models\Staff;
use App\Models\User;
use App\Models\UserClientScope;
use Illuminate\Database\Seeder;

/** Synthetic accounts only, with a runtime-supplied secret; never included in DatabaseSeeder. */
class BrowserTestSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing']) || ! str_ends_with(config('database.connections.pgsql.database'), '_e2e') || ! config('dcms.e2e_password')) {
            throw new \LogicException('Browser fixtures require a local/test *_e2e database and a runtime secret.');
        }
        $this->call(DatabaseSeeder::class);
        $admin = User::factory()->create(['name' => 'Synthetic Admin', 'email' => 'admin@example.test', 'password' => config('dcms.e2e_password')]);
        $admin->assignRole('Admin');
        $client = Client::factory()->create(['code' => 'SYNTHETIC', 'name' => 'Synthetic Client']);
        $scope = new UserClientScope;
        $scope->user_id = $admin->id;
        $scope->client_id = $client->id;
        $scope->granted_by = $admin->id;
        $scope->valid_from = now()->subMinute();
        $scope->reason = 'Synthetic browser portfolio';
        $scope->save();
        Debtor::factory()->create(['customer_reference' => 'FOREIGN-SECRET', 'display_name_ciphertext' => 'Foreign client customer']);
        $rider = User::factory()->for(Staff::factory()->create())->create(['name' => 'Synthetic Rider', 'email' => 'rider@example.test', 'password' => config('dcms.e2e_password')]);
        $rider->assignRole('Rider');
    }
}
