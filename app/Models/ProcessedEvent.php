<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;

class ProcessedEvent extends Model
{
    use HasPublicId;

    protected function casts(): array
    {
        return ['processed_at' => 'immutable_datetime'];
    }
}
