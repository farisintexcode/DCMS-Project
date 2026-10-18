<?php

namespace App\Notifications;

use App\Models\TaskReview;
use Illuminate\Notifications\Notification;

class TaskReviewed extends Notification
{
    public function __construct(public TaskReview $review) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return ['task' => $this->review->task->public_id, 'submission' => $this->review->submission->public_id, 'review' => $this->review->public_id, 'decision' => $this->review->decision, 'message' => $this->review->decision === 'approved' ? 'Your task was verified and completed.' : 'Your task was returned for correction. Open it to read the verification remarks.'];
    }
}
