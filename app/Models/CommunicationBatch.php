<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** UI66–68 frozen selection; previewed is not queued or delivered. */
class CommunicationBatch extends Model
{
    use HasFactory, HasPublicId;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $attributes = ['status' => 'draft', 'lock_version' => 0];

    protected function casts(): array
    {
        return ['selection_criteria' => 'array', 'counts' => 'array', 'confirmed_at' => 'immutable_datetime', 'lock_version' => 'integer'];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(CommunicationTemplate::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function communications(): HasMany
    {
        return $this->hasMany(Communication::class, 'batch_id');
    }
}
