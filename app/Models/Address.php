<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Address extends Model
{
    use HasFactory, HasPublicId;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $hidden = ['lines_ciphertext', 'recipient_name_ciphertext'];

    protected function casts(): array
    {
        return ['lines_ciphertext' => 'encrypted', 'recipient_name_ciphertext' => 'encrypted', 'valid_from' => 'immutable_datetime', 'valid_to' => 'immutable_datetime'];
    }

    public function debtor(): BelongsTo
    {
        return $this->belongsTo(Debtor::class);
    }
}
