<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** M14 verified webhook inbox: Database.md integration_events. */
class IntegrationEvent extends Model
{
    use HasFactory, HasPublicId;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $hidden = ['payload_ciphertext', 'payload_digest'];

    protected function casts(): array
    {
        return ['payload_ciphertext' => 'encrypted:array', 'received_at' => 'immutable_datetime', 'verified_at' => 'immutable_datetime', 'processed_at' => 'immutable_datetime'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(IntegrationAccount::class, 'integration_account_id');
    }
}
