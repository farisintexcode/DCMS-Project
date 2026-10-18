<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Module.md M14 / UI65 / Database.md canonical versioned email and letter options. */
class CommunicationTemplate extends Model
{
    use HasFactory, HasPublicId;

    protected $hidden = ['rejection_reason_ciphertext'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $attributes = ['version' => 1, 'status' => 'draft', 'lock_version' => 0];

    protected function casts(): array
    {
        return ['allowed_placeholders' => 'array', 'submitted_at' => 'immutable_datetime', 'rejected_at' => 'immutable_datetime', 'rejection_reason_ciphertext' => 'encrypted', 'approved_at' => 'immutable_datetime', 'email_slot' => 'integer', 'version' => 'integer', 'lock_version' => 'integer'];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function rejecter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function predecessor(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_id');
    }

    public function integrationAccount(): BelongsTo
    {
        return $this->belongsTo(IntegrationAccount::class);
    }
}
