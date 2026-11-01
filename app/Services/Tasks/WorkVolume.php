<?php

namespace App\Services\Tasks;

use App\Models\AssignmentBatch;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/** Dashboard volume of assignments and tasks created per local day or month. */
class WorkVolume
{
    /** @var array{daily: list<int>, monthly: list<int>} */
    public const RANGES = ['daily' => [7, 14, 30], 'monthly' => [3, 6, 12]];

    /**
     * A series the actor may not view is null; when neither is viewable the whole result is null.
     *
     * @return array{buckets: Collection<int, array{label: string, date: string, assignments: ?int, tasks: ?int}>, assignments: ?int, tasks: ?int}|null
     */
    public function forUser(User $user, string $period, int $range): ?array
    {
        $canViewAssignments = Gate::forUser($user)->allows('viewAny', AssignmentBatch::class);
        $canViewTasks = Gate::forUser($user)->allows('viewAny', Task::class);
        if (! $canViewAssignments && ! $canViewTasks) {
            return null;
        }

        $monthly = $period === 'monthly';
        $timezone = config('dcms.business_timezone');
        $now = now()->timezone($timezone);
        $start = $monthly ? $now->copy()->startOfMonth()->subMonthsNoOverflow($range - 1) : $now->copy()->startOfDay()->subDays($range - 1);
        $bucketFormat = $monthly ? 'YYYY-MM' : 'YYYY-MM-DD';
        $count = fn (Builder $query): Collection => $query->where('created_at', '>=', $start->copy()->utc())->where('created_at', '<=', now())
            ->selectRaw("to_char(created_at at time zone ?, '{$bucketFormat}') as bucket, count(*) as total", [$timezone])
            ->groupBy('bucket')->pluck('total', 'bucket');

        $assignments = null;
        if ($canViewAssignments) {
            $assignments = $count(AssignmentBatch::query());
        }
        $tasks = null;
        if ($canViewTasks) {
            $tasks = $count(Task::query());
        }

        $buckets = collect(range(0, $range - 1))->map(function (int $offset) use ($monthly, $start, $range, $assignments, $tasks): array {
            $date = $monthly ? $start->copy()->addMonthsNoOverflow($offset) : $start->copy()->addDays($offset);
            $key = $date->format($monthly ? 'Y-m' : 'Y-m-d');

            return [
                'label' => $date->format($monthly ? 'M' : ($range === 7 ? 'D' : 'j')),
                'date' => $date->format($monthly ? 'M Y' : 'd M Y'),
                'assignments' => $assignments === null ? null : (int) ($assignments[$key] ?? 0),
                'tasks' => $tasks === null ? null : (int) ($tasks[$key] ?? 0),
            ];
        });

        return [
            'buckets' => $buckets,
            'assignments' => $assignments === null ? null : $buckets->sum('assignments'),
            'tasks' => $tasks === null ? null : $buckets->sum('tasks'),
        ];
    }
}
