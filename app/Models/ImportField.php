<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** M04 / Database.md: versioned registry, private source documents and encrypted staging. */
class ImportField extends Model
{
    use HasFactory, HasPublicId;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return ['validation_schema' => 'array', 'is_active' => 'boolean', 'version' => 'integer'];
    }

    public function aliases(): HasMany
    {
        return $this->hasMany(ColumnAlias::class, 'field_id');
    }
}
