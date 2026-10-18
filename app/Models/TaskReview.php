<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** M07 / Database.md task_reviews: no overwriting accepted decisions or sensitive remarks. */
class TaskReview extends Model
{
    use HasFactory,HasPublicId;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $hidden = ['reason'];

    protected function casts(): array
    {
        return ['reason' => 'encrypted', 'reviewed_at' => 'immutable_datetime', 'submission_version' => 'integer'];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(TaskSubmission::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    public function previousReview(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_review_id');
    }
}
