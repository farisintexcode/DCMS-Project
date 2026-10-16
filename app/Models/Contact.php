<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Contact extends Model
{
    use HasFactory, HasPublicId;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $hidden = ['value_ciphertext', 'value_fingerprint'];

    protected function casts(): array
    {
        return ['value_ciphertext' => 'encrypted', 'is_primary' => 'boolean', 'verified_at' => 'immutable_datetime', 'valid_from' => 'immutable_datetime', 'valid_to' => 'immutable_datetime'];
    }

    public function debtor(): BelongsTo
    {
        return $this->belongsTo(Debtor::class);
    }
}
