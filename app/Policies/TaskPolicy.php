<?php

namespace App\Policies;

use App\Models\Client;
use App\Models\Task;
use App\Models\User;
use App\Services\Tasks\RiderAccess;

class TaskPolicy
{
    public function execute(User $user, Task $task): bool
    {
        return $user->can('tasks.submit') && app(RiderAccess::class)->current($user, $task);
    }

    public function viewAny(User $user): bool
    {
        return $user->isActive() && $user->can('admin.access') && $user->can('tasks.view') && $user->can('tasks.monitor');
    }

    public function view(User $user, Task $task): bool
    {
        return $this->viewAny($user) && $user->hasClientScope($task->batch->client);
    }

    public function create(User $user, Client $client): bool
    {
        return $this->viewAny($user) && $user->can('tasks.create') && $user->hasClientScope($client);
    }

    public function update(User $user, Task $task): bool
    {
        return $this->view($user, $task) && $user->can('tasks.update');
    }
}
