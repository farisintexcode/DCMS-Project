<?php

namespace App\Policies;

use App\Models\CommunicationBatch;
use App\Models\User;
use App\Services\Communications\RecipientAccess;

class CommunicationBatchPolicy
{
    public function viewAny(User $user): bool
    {
        return app(RecipientAccess::class)->allowed($user);
    }

    public function view(User $user, CommunicationBatch $batch): bool
    {
        return $this->viewAny($user) && app(RecipientAccess::class)->batches($user)->whereKey($batch->id)->exists();
    }

    public function cancel(User $user, CommunicationBatch $batch): bool
    {
        return $this->view($user, $batch) && $user->can('communications.compose') && $batch->requested_by === $user->id && ($batch->status === 'previewed' || ($user->can('communications.send') && in_array($batch->status, ['queued', 'sending', 'paused'], true)));
    }

    public function confirm(User $user, CommunicationBatch $batch): bool
    {
        return $this->view($user, $batch) && $user->can('communications.compose') && $user->can('communications.send') && ($batch->selection_criteria['mode'] !== 'bulk' || $user->can('communications.bulk_send'));
    }
}
