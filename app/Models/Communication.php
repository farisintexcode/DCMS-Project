<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/** M14 canonical private recipient/rendered envelope. */
class Communication extends Model
{
    use HasFactory, HasPublicId;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $hidden = ['recipient_ciphertext', 'recipient_fingerprint', 'subject_ciphertext', 'body_ciphertext', 'context_snapshot_ciphertext'];

    protected function casts(): array
    {
        return ['recipient_ciphertext' => 'encrypted', 'subject_ciphertext' => 'encrypted', 'body_ciphertext' => 'encrypted', 'context_snapshot_ciphertext' => 'encrypted:array', 'sent_at' => 'immutable_datetime', 'next_attempt_at' => 'immutable_datetime', 'delivered_at' => 'immutable_datetime', 'bounced_at' => 'immutable_datetime', 'complained_at' => 'immutable_datetime'];
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(CommunicationAttempt::class);
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'owner');
    }

    public function events(): HasMany
    {
        return $this->hasMany(CommunicationEvent::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Debtor::class, 'customer_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(CommunicationBatch::class, 'batch_id');
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(CommunicationTemplate::class);
    }
}
