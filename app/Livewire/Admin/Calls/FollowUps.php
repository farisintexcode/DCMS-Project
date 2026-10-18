<?php

namespace App\Livewire\Admin\Calls;

use App\Actions\Calls\ResolveFollowUp;
use App\Models\CollectionActivity;
use App\Models\FollowUp;
use App\Models\Task;
use App\Services\Calls\CallAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** M11/UI60: scoped due queue with assigned-agent decisions. */
#[Layout('layouts.admin')]
class FollowUps extends Component
{
    use WithPagination;

    #[Url]
    public string $status = 'pending';

    #[Url]
    public string $ownership = 'mine';

    #[Url]
    public string $due = 'all';

    public array $resolutions = [];

    public array $decisions = [];

    public function updated(string $property): void
    {
        if (in_array($property, ['status', 'ownership', 'due'], true)) {
            $this->resetPage();
        }
    }

    private function scoped(CallAccess $access): Builder
    {
        $query = FollowUp::query()->whereIn('customer_id', $access->customers(auth()->user())->select('debtors.id'));

        return $query->where(function ($q): void {
            $q->whereHas('activity', fn ($q) => $q->where('activity_type_code', 'call'));
            if (Gate::allows('viewAny', Task::class)) {
                $q->orWhereHas('activity', fn ($q) => $q->where('activity_type_code', 'visit'));
            }
        });
    }

    public function resolve(string $id, int $version, ResolveFollowUp $resolve, CallAccess $access): void
    {
        Gate::authorize('viewAny', CollectionActivity::class);
        abort_unless(Str::isUuid($id), 404);
        $follow = $this->scoped($access)->where('public_id', $id)->firstOrFail();
        $resolve->handle(auth()->user(), $follow, $version, $this->decisions[$id] ?? 'done', $this->resolutions[$id] ?? '');
        unset($this->resolutions[$id],$this->decisions[$id]);
        session()->flash('status', 'Follow-up decision recorded. Original reminder context is unchanged.');
    }

    public function render(CallAccess $access): View
    {
        Gate::authorize('viewAny', CollectionActivity::class);
        $query = $this->scoped($access);
        if ($this->status !== 'all') {
            in_array($this->status, ['pending', 'done', 'cancelled'], true) ? $query->where('status', $this->status) : $query->whereRaw('false');
        }
        if ($this->ownership === 'mine') {
            $query->where('assigned_to', auth()->id());
        } elseif ($this->ownership !== 'all') {
            $query->whereRaw('false');
        }
        if ($this->due === 'overdue') {
            $query->where('status', 'pending')->where('due_at', '<', now()->format('Y-m-d H:i:s.uP'));
        } elseif ($this->due === 'today') {
            $today = now()->timezone(config('dcms.business_timezone'))->startOfDay();
            $query->where('due_at', '>=', $today->copy()->utc())->where('due_at', '<', $today->addDay()->utc());
        } elseif ($this->due !== 'all') {
            $query->whereRaw('false');
        }

        return view('livewire.admin.calls.follow-ups', ['followUps' => $query->with(['customer', 'assignee', 'resolver', 'activity'])->orderBy('due_at')->orderBy('id')->paginate(25)]);
    }
}
