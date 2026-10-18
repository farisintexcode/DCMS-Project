<?php

namespace App\Policies;

use App\Models\Client;
use App\Models\ImportBatch;
use App\Models\User;

class ImportBatchPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isActive() && $user->can('admin.access') && $user->can('imports.view');
    }

    public function view(User $user, ImportBatch $batch): bool
    {
        return $this->viewAny($user) && $user->hasClientScope($batch->client);
    }

    public function create(User $user, Client $client): bool
    {
        return $this->viewAny($user) && $user->can('imports.map') && $user->hasClientScope($client);
    }

    public function map(User $user, ImportBatch $batch): bool
    {
        return $this->view($user, $batch) && $user->can('imports.map');
    }

    public function confirm(User $user, ImportBatch $batch): bool
    {
        return $this->view($user, $batch) && $user->can('imports.confirm');
    }
}
