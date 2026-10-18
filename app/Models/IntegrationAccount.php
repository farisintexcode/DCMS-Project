<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** M14 provider parent. Provisioning and capability activation are separate work. */
class IntegrationAccount extends Model
{
    use HasFactory, HasPublicId;

    protected $hidden = ['sender_reference_ciphertext', 'secret_reference'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return ['sender_reference_ciphertext' => 'encrypted', 'capabilities' => 'array', 'capability_verified_at' => 'immutable_datetime'];
    }

    public function templates(): HasMany
    {
        return $this->hasMany(CommunicationTemplate::class);
    }
}
