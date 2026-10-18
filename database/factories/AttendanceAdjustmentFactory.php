<?php

namespace Database\Factories;

use App\Actions\Attendance\CorrectAttendance;
use App\Models\AttendanceAdjustment;
use App\Models\AttendanceSchedule;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AttendanceAdjustment>
 */
class AttendanceAdjustmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['schedule_id' => AttendanceSchedule::factory(), 'requested_by' => User::factory(), 'requested_at' => now(), 'before_values' => fn (array $a) => app(CorrectAttendance::class)->snapshot(AttendanceSchedule::query()->findOrFail($a['schedule_id']), null), 'proposed_values' => ['day_type' => 'leave'], 'reason' => 'Synthetic calendar correction', 'status' => 'pending'];
    }
}
