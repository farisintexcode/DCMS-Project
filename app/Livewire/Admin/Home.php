<?php

namespace App\Livewire\Admin;

use App\Models\Attendance;
use App\Models\Task;
use App\Services\Attendance\AttendanceRegister;
use App\Services\Customers\CustomerOverview;
use App\Services\Tasks\TaskAccess;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.admin')]
class Home extends Component
{
    public function render(CustomerOverview $overview): View
    {
        Gate::authorize('admin.access');

        $taskMetrics = null;
        if (Gate::allows('viewAny', Task::class)) {
            $scope = app(TaskAccess::class)->scope(Task::query(), Auth::user());
            $taskMetrics = [
                'active' => (clone $scope)->whereNotIn('work_status', ['completed', 'cancelled'])->count(),
                'pending_verification' => (clone $scope)->where('work_status', 'submitted')->where('review_status', 'pending')->count(),
                'completed' => (clone $scope)->where('work_status', 'completed')->where('review_status', 'approved')->count(),
                'returned' => (clone $scope)->where('review_status', 'returned')->count(),
            ];
        }

        $attendanceMetrics = null;
        $attendanceDate = now()->timezone(config('dcms.business_timezone'))->toDateString();
        if (Gate::allows('viewAny', Attendance::class)) {
            $attendanceMetrics = app(AttendanceRegister::class)->query(Auth::user(), ['from' => $attendanceDate, 'until' => $attendanceDate])->selectRaw('result_status,count(*) as total')->groupBy('result_status')->pluck('total', 'result_status')->all();
        }

        return view('livewire.admin.home', ['overview' => Auth::user()->can('customers.view') ? $overview->forUser(Auth::user()) : null, 'taskMetrics' => $taskMetrics, 'attendanceMetrics' => $attendanceMetrics, 'attendanceDate' => $attendanceDate]);
    }
}
