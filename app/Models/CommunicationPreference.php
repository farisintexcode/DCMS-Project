<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** M14 service-email authority: immutable evidence/review versions, never a consent boolean. */
class CommunicationPreference extends Model
{
    use HasFactory, HasPublicId;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $hidden = ['evidence_ciphertext', 'reason_ciphertext', 'review_reason_ciphertext'];

    protected $attributes = ['state' => 'unknown', 'channel' => 'email', 'purpose' => 'service_collection', 'review_status' => 'pending', 'is_current' => false, 'lock_version' => 0];

    protected function casts(): array
    {
        return ['evidence_ciphertext' => 'encrypted:array', 'reason_ciphertext' => 'encrypted', 'review_reason_ciphertext' => 'encrypted', 'effective_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime', 'reviewed_at' => 'immutable_datetime', 'is_current' => 'boolean', 'lock_version' => 'integer'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Debtor::class, 'customer_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(DebtAccount::class, 'debt_account_id');
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
