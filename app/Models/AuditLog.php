<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class AuditLog extends Model
{
    use HasPublicId;

    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Audit history is append-only.'));
        static::deleting(fn () => throw new LogicException('Audit history is append-only.'));
    }

    protected function casts(): array
    {
        return ['previous_values_redacted' => 'array', 'new_values_redacted' => 'array', 'occurred_at' => 'immutable_datetime'];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
