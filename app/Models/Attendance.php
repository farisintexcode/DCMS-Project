<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Database\Factories\AttendanceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Attendance extends Model
{
    /** @use HasFactory<AttendanceFactory> */
    use HasFactory, HasPublicId;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $attributes = ['lock_version' => 0, 'status' => 'needs_review', 'is_late' => false, 'late_minutes' => 0];

    protected function casts(): array
    {
        return ['service_date' => 'immutable_date', 'raw_clock_in_at' => 'immutable_datetime', 'raw_clock_out_at' => 'immutable_datetime', 'effective_clock_in_at' => 'immutable_datetime', 'effective_clock_out_at' => 'immutable_datetime', 'is_late' => 'boolean', 'worked_minutes' => 'integer', 'late_minutes' => 'integer', 'lock_version' => 'integer'];
    }

    public function rider(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'rider_id');
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(AttendanceSchedule::class, 'schedule_id');
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(AttendanceAdjustment::class);
    }
}
