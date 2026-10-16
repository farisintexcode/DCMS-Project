<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;

class ReferenceValue extends Model
{
    use HasPublicId;

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'metadata' => 'array'];
    }
}
