<?php

namespace App\Services;

use App\Models\CollectionActivity;
use App\Models\ImportBatch;
use App\Models\Task;
use App\Models\User;
use App\Services\Calls\CallAccess;
use App\Services\Tasks\TaskAccess;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Gate;

/** Dashboard counts of stuck work, each matching the list it links to; items the actor may not open are left out. */
class AttentionQueue
{
    public const UNFINISHED_IMPORTS = ['mapping', 'ready', 'failed'];

    public function __construct(private TaskAccess $tasks, private CallAccess $calls, private ClientAccess $clients) {}

    /** @return list<array{key: string, label: string, count: int, detail: string, href: string}> */
    public function forUser(User $user): array
    {
        $items = [];
        if (Gate::forUser($user)->allows('viewAny', Task::class)) {
            $scope = $this->tasks->scope(Task::query(), $user);
            $pending = (clone $scope)->where('work_status', 'submitted')->where('review_status', 'pending');
            $oldest = (clone $pending)->min('submitted_at');
            $items[] = [
                'key' => 'pending_verification', 'label' => 'Waiting for verification', 'count' => (clone $pending)->count(),
                'detail' => $oldest ? 'Oldest waiting '.CarbonImmutable::parse($oldest)->diffForHumans(syntax: CarbonInterface::DIFF_ABSOLUTE) : 'Nothing waiting',
                'href' => $user->can('tasks.verify') ? route('admin.verification.index') : route('admin.tasks.index', ['status' => 'pending_verification']),
            ];
            $items[] = ['key' => 'overdue', 'label' => 'Overdue tasks', 'count' => $this->tasks->overdue(clone $scope)->count(), 'detail' => 'Past due and not finished', 'href' => route('admin.tasks.index', ['status' => 'overdue'])];
            $items[] = ['key' => 'unassigned', 'label' => 'Tasks without a Rider', 'count' => (clone $scope)->whereDoesntHave('currentAllocation')->whereNotIn('work_status', ['completed', 'cancelled'])->count(), 'detail' => 'Not yet allocated', 'href' => route('admin.tasks.index', ['status' => 'unassigned'])];
            $items[] = ['key' => 'returned', 'label' => 'Returned tasks', 'count' => (clone $scope)->where('review_status', 'returned')->count(), 'detail' => 'Sent back for correction', 'href' => route('admin.tasks.index', ['status' => 'returned'])];
        }
        if (Gate::forUser($user)->allows('viewAny', CollectionActivity::class)) {
            $items[] = [
                'key' => 'overdue_follow_ups', 'label' => 'Overdue follow-ups',
                'count' => $this->calls->followUps($user)->where('status', 'pending')->where('due_at', '<', now()->format('Y-m-d H:i:s.uP'))->count(),
                'detail' => 'Pending past their due time', 'href' => route('admin.calls.follow-ups', ['status' => 'pending', 'ownership' => 'all', 'due' => 'overdue']),
            ];
        }
        if (Gate::forUser($user)->allows('viewAny', ImportBatch::class)) {
            $items[] = [
                'key' => 'unfinished_imports', 'label' => 'Unfinished imports',
                'count' => $this->clients->scope(ImportBatch::query(), $user)->whereIn('status', self::UNFINISHED_IMPORTS)->count(),
                'detail' => 'Awaiting mapping, confirmation or a retry', 'href' => route('admin.imports.index'),
            ];
        }

        return $items;
    }
}
