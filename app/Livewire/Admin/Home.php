<?php

namespace App\Livewire\Admin;

use App\Models\AssignmentBatch;
use App\Services\AttentionQueue;
use App\Services\ClientAccess;
use App\Services\Tasks\TaskProgress;
use App\Services\Tasks\WorkVolume;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.admin')]
class Home extends Component
{
    #[Url]
    public string $period = 'daily';

    #[Url]
    public string $range = '7';

    #[Url]
    public string $client = '';

    #[Url]
    public string $assignment = '';

    public function updatedPeriod(): void
    {
        $this->range = $this->period === 'monthly' ? '12' : '7';
    }

    public function render(AttentionQueue $attention, TaskProgress $taskProgress, WorkVolume $workVolume, ClientAccess $access): View
    {
        Gate::authorize('admin.access');

        $progress = $taskProgress->forUser(Auth::user());
        $assignments = $progress === null ? collect() : $access->scope(AssignmentBatch::query(), Auth::user())->where('status', 'active')
            ->orderByDesc('id')->get(['id', 'public_id', 'name', 'external_reference']);
        $selectedAssignment = $assignments->firstWhere('public_id', $this->assignment);
        $report = $selectedAssignment === null ? $progress : $taskProgress->forUser(Auth::user(), $selectedAssignment->id, null);

        $period = array_key_exists($this->period, WorkVolume::RANGES) ? $this->period : 'daily';
        $range = in_array((int) $this->range, WorkVolume::RANGES[$period], true) ? (int) $this->range : WorkVolume::RANGES[$period][0];
        $clientId = ctype_digit($this->client) && strlen($this->client) < 19 ? (int) $this->client : null;
        $volume = $workVolume->forUser(Auth::user(), $period, $range, $clientId);

        return view('livewire.admin.home', [
            'attention' => $attention->forUser(Auth::user()),
            'progress' => $progress,
            'assignmentProgress' => $taskProgress->assignments(Auth::user()),
            'report' => $report,
            'assignments' => $assignments,
            'selectedAssignment' => $selectedAssignment,
            'volume' => $volume,
            'volumePeriod' => $period,
            'volumeRange' => $range,
            'clients' => $volume === null ? collect() : $access->clients(Auth::user())->orderBy('name')->get(['clients.id', 'clients.name']),
        ]);
    }
}
