<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** M04 / Database.md: versioned registry, private source documents and encrypted staging. */
class ColumnAlias extends Model
{
    use HasFactory, HasPublicId;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function field(): BelongsTo
    {
        return $this->belongsTo(ImportField::class, 'field_id');
    }
}
