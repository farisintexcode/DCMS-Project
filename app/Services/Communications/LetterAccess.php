<?php

namespace App\Services\Communications;

use App\Models\Communication;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

/** M14/UI64–67: recheck task scope for private letters. */
class LetterAccess
{
    public function authorize(User $actor, bool $generate = false): void
    {
        abort_unless($actor->isActive() && $actor->can('admin.access') && $actor->can('communications.view') && $actor->can('customers.view'), 403);
        if ($generate) {
            abort_unless($actor->can('communications.compose') && $actor->can('communications.generate_letter'), 403);
        }
    }

    public function query(User $actor): Builder
    {
        $this->authorize($actor);

        return Communication::query()->where('channel', 'letter')
            ->when(! Gate::forUser($actor)->allows('viewAny', Task::class), fn (Builder $q) => $q->whereNull('task_id'));
    }

    public function letter(User $actor, int $id, bool $generate = false): Communication
    {
        $this->authorize($actor, $generate);
        $letter = $this->query($actor)->findOrFail($id);
        if ($letter->task_id !== null) {
            Gate::forUser($actor)->authorize('view', Task::query()->findOrFail($letter->task_id));
        }

        return $letter;
    }
}
