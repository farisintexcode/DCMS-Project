<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** M14 canonical retained delivery evidence; Database.md corresponding table. */
class CommunicationAttempt extends Model
{
    use HasFactory, HasPublicId;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return ['attempt_number' => 'integer', 'started_at' => 'immutable_datetime', 'finished_at' => 'immutable_datetime', 'retry_after' => 'immutable_datetime'];
    }

    public function communication(): BelongsTo
    {
        return $this->belongsTo(Communication::class, 'communication_id');
    }
}
