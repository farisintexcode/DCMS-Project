<?php

namespace App\Policies;

use App\Models\Task;
use App\Models\TaskSubmission;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class TaskSubmissionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('tasks.verify') && Gate::forUser($user)->allows('viewAny', Task::class);
    }

    public function view(User $user, TaskSubmission $submission): bool
    {
        return $this->viewAny($user) && $user->hasClientScope($submission->task->batch->client);
    }

    public function decide(User $user, TaskSubmission $submission): bool
    {
        return $this->view($user, $submission) && $user->id !== $submission->submitted_by;
    }
}
