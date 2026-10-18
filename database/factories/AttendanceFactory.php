<?php

namespace Database\Factories;

use App\Models\Attendance;
use App\Models\Staff;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Attendance>
 */
class AttendanceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['rider_id' => Staff::factory(), 'service_date' => now()->timezone(config('dcms.business_timezone'))->toDateString(), 'raw_clock_in_at' => now()->subHour(), 'effective_clock_in_at' => now()->subHour(), 'clock_in_request_id' => (string) Str::uuid7(), 'status' => 'needs_review'];
    }
}
