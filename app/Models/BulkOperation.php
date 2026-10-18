<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** M05 / Database.md: canonical account, task and allocation entities. */
class BulkOperation extends Model
{
    use HasFactory,HasPublicId;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $hidden = ['source_snapshot_ciphertext', 'address_lines_ciphertext', 'special_instruction_ciphertext', 'task_remarks_ciphertext', 'admin_remarks_ciphertext'];

    protected function casts(): array
    {
        return ['result_counts' => 'array'];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(AssignmentBatch::class, 'assignment_batch_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(BulkOperationItem::class);
    }

    public function collector(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'target_collector_id');
    }
}
