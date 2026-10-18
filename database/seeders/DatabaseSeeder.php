<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([ReferenceValueSeeder::class, RiderReferenceSeeder::class, RolePermissionSeeder::class, ImportRegistrySeeder::class, AttendancePolicySeeder::class, CallReferenceSeeder::class, CommunicationTemplateSeeder::class]);
    }
}
