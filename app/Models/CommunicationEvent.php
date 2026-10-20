<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** M14 canonical retained delivery evidence; Database.md corresponding table. */
class CommunicationEvent extends Model
{
    use HasFactory, HasPublicId;

    public function integrationEvent(): BelongsTo
    {
        return $this->belongsTo(IntegrationEvent::class);
    }

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return ['details' => 'array', 'occurred_at' => 'immutable_datetime', 'received_at' => 'immutable_datetime'];
    }

    public function communication(): BelongsTo
    {
        return $this->belongsTo(Communication::class, 'communication_id');
    }
}
