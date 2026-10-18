<?php

namespace App\Livewire\Rider\Tasks;

use App\Models\TaskSubmission;
use App\Services\Tasks\TaskAccess;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/** UI49: former assignees see only their own redacted submission receipt. */
#[Layout('layouts.rider')]
class SubmissionHistory extends Component
{
    #[Locked]
    public int $submissionId;

    public function mount(TaskSubmission $submission): void
    {
        $this->submissionId = $submission->id;
    }

    public function render(TaskAccess $access): View
    {
        Gate::authorize('use-rider-interface');
        abort_unless(Auth::user()->can('tasks.view'), 403);
        $submission = TaskSubmission::query()->where('submitted_by', Auth::id())->whereHas('task', fn ($q) => $access->scope($q, Auth::user()))->findOrFail($this->submissionId);

        return view('livewire.rider.tasks.submission-history', compact('submission'));
    }
}
