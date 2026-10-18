<?php

namespace Database\Seeders;

use App\Models\AttendancePolicy;
use Illuminate\Database\Seeder;

class AttendancePolicySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (! AttendancePolicy::query()->where('code', config('attendance.initial_code'))->exists()) {
            $policy = new AttendancePolicy;
            $policy->forceFill(['code' => config('attendance.initial_code'), 'version' => 1, 'timezone' => config('dcms.business_timezone'), 'rules' => config('attendance.initial_rules'), 'is_active' => false])->save();
        }
    }
}
