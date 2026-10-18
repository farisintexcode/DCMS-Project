<?php

namespace App\Policies;

use App\Models\AssignmentBatch;
use App\Models\Client;
use App\Models\User;

/** M03 / UI09–12: permission plus a current, active client portfolio grant. */
class AssignmentBatchPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isActive() && $user->can('admin.access') && $user->can('assignments.view');
    }

    public function view(User $user, AssignmentBatch $batch): bool
    {
        return $this->viewAny($user) && $user->hasClientScope($batch->client);
    }

    public function create(User $user, ?Client $client = null): bool
    {
        return $this->viewAny($user) && $user->can('assignments.manage') && ($client === null || $user->hasClientScope($client));
    }

    public function update(User $user, AssignmentBatch $batch): bool
    {
        return $this->view($user, $batch) && $user->can('assignments.manage');
    }

    public function reopen(User $user, AssignmentBatch $batch): bool
    {
        return $this->update($user, $batch) && $user->can('assignments.reopen');
    }
}
