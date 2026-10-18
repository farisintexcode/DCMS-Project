<?php

namespace Database\Factories;

use App\Models\CollectionActivity;
use App\Models\Document;
use App\Models\TaskSubmission;
use Illuminate\Database\Eloquent\Factories\Factory;

class TaskSubmissionDocumentFactory extends Factory
{
    public function definition(): array
    {
        return ['submission_id' => TaskSubmission::factory(), 'document_id' => function (array $a): int {
            $submission = TaskSubmission::query()->findOrFail($a['submission_id']);
            $activity = CollectionActivity::factory()->create(['task_id' => $submission->task_id, 'customer_id' => $submission->task->customer_id, 'performed_by' => $submission->submitted_by]);

            return Document::factory()->create(['owner_type' => 'task', 'owner_id' => $submission->task_id, 'activity_id' => $activity->id, 'uploaded_by' => $submission->submitted_by, 'document_type_code' => 'proof_image', 'image_category_code' => 'premises', 'scan_status' => 'clean', 'mime_type' => 'image/jpeg'])->id;
        }, 'category_code' => 'premises'];
    }
}
