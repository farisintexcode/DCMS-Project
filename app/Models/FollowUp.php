<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** M06 / Database.md canonical execution and submission history. */
class FollowUp extends Model
{
    use HasFactory, HasPublicId;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $hidden = ['notes_ciphertext', 'resolution_ciphertext'];

    protected $attributes = ['lock_version' => 0];

    protected function casts(): array
    {
        return ['lock_version' => 'integer', 'resolved_at' => 'immutable_datetime', 'resolution_ciphertext' => 'encrypted', 'due_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime', 'notes_ciphertext' => 'encrypted'];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'task_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Debtor::class, 'customer_id');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function activity(): BelongsTo
    {
        return $this->belongsTo(CollectionActivity::class, 'activity_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }
}
