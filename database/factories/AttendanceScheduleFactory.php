<?php

namespace Database\Factories;

use App\Models\AttendancePolicy;
use App\Models\AttendanceSchedule;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AttendanceSchedule>
 */
class AttendanceScheduleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['rider_id' => Staff::factory(), 'policy_id' => AttendancePolicy::factory(), 'service_date' => now()->timezone(config('dcms.business_timezone'))->toDateString(), 'day_type' => 'scheduled', 'scheduled_start_at' => now()->timezone(config('dcms.business_timezone'))->setTime(9, 0)->utc(), 'scheduled_end_at' => now()->timezone(config('dcms.business_timezone'))->setTime(18, 0)->utc(), 'authorized_by' => User::factory()];
    }
}
