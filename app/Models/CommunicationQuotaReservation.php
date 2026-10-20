<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** M14 canonical retained delivery evidence; Database.md corresponding table. */
class CommunicationQuotaReservation extends Model
{
    use HasFactory, HasPublicId;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return ['reserved_at' => 'immutable_datetime', 'accepted_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime'];
    }

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(CommunicationAttempt::class, 'attempt_id');
    }
}
