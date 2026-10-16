<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;

/** Persistence foundation; dispatch handlers arrive with the first queued business action. */
class OutboxEvent extends Model
{
    use HasPublicId;

    protected function casts(): array
    {
        return ['payload' => 'array', 'available_at' => 'immutable_datetime', 'dispatched_at' => 'immutable_datetime', 'attempts' => 'integer'];
    }
}
