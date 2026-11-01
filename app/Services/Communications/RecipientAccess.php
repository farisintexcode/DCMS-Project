<?php

namespace App\Services\Communications;

use App\Models\CommunicationBatch;
use App\Models\Contact;
use App\Models\Debtor;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class RecipientAccess
{
    public function allowed(User $actor): bool
    {
        return $actor->isActive() && $actor->can('admin.access') && $actor->can('communications.view') && $actor->can('customers.view');
    }

    public function compose(User $actor, bool $bulk = false): void
    {
        abort_unless($this->allowed($actor) && $actor->can('communications.compose') && (! $bulk || $actor->can('communications.bulk_send')), 403);
    }

    public function customers(User $actor): Builder
    {
        abort_unless($this->allowed($actor), 403);

        return Debtor::query();
    }

    public function contacts(User $actor): Builder
    {
        return Contact::query()->whereIn('debtor_id', $this->customers($actor)->select('debtors.id'))->where('kind_code', 'email');
    }

    public function batches(User $actor): Builder
    {
        abort_unless($this->allowed($actor), 403);

        return CommunicationBatch::query();
    }
}
