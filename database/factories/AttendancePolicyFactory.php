<?php

namespace Database\Factories;

use App\Models\AttendancePolicy;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AttendancePolicy>
 */
class AttendancePolicyFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['code' => fake()->unique()->bothify('policy-########'), 'version' => 1, 'timezone' => config('dcms.business_timezone'), 'rules' => config('attendance.initial_rules'), 'is_active' => true, 'approved_by' => User::factory(), 'approved_at' => now()];
    }
}
