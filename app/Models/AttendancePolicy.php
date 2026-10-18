<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Database\Factories\AttendancePolicyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AttendancePolicy extends Model
{
    /** @use HasFactory<AttendancePolicyFactory> */
    use HasFactory, HasPublicId;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $table = 'attendance_policies';

    protected function casts(): array
    {
        return ['rules' => 'array', 'is_active' => 'boolean', 'approved_at' => 'immutable_datetime'];
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(AttendanceSchedule::class, 'policy_id');
    }
}
