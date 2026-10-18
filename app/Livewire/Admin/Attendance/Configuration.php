<?php

namespace App\Livewire\Admin\Attendance;

use App\Actions\Attendance\ConfigureAttendance;
use App\Models\Attendance;
use App\Models\AttendancePolicy;
use App\Services\Attendance\AttendanceAccess;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/** M10/UI59: publish immutable rule versions and explicit calendar assignments. */
#[Layout('layouts.admin')]
class Configuration extends Component
{
    public string $code = 'standard';

    public array $rules = [];

    public string $riderId = '';

    public string $policyId = '';

    public string $from = '';

    public string $until = '';

    public string $dayType = 'calendar';

    public string $remarks = '';

    public function mount(): void
    {
        Gate::authorize('configure', Attendance::class);
        $this->rules = config('attendance.initial_rules');
        $this->from = now()->timezone(config('dcms.business_timezone'))->toDateString();
        $this->until = $this->from;
    }

    public function publish(ConfigureAttendance $configure): void
    {
        $policy = $configure->policy(auth()->user(), $this->only(['code', 'rules']));
        $this->policyId = $policy->public_id;
        session()->flash('status', 'Policy version '.$policy->version.' published. Existing schedules keep their pinned version.');
    }

    public function assign(ConfigureAttendance $configure): void
    {
        $count = $configure->assign(auth()->user(), $this->riderId, $this->policyId, $this->from, $this->until, $this->dayType, $this->remarks);
        session()->flash('status', $count.' calendar days assigned. Existing records were preserved.');
    }

    public function render(AttendanceAccess $access): View
    {
        Gate::authorize('configure', Attendance::class);

        return view('livewire.admin.attendance.configuration', ['policies' => AttendancePolicy::query()->where('is_active', true)->orderBy('code')->orderByDesc('version')->get(), 'riders' => $access->riders(auth()->user())->with('user')->where('is_active', true)->orderBy('staff_no')->limit(200)->get()]);
    }
}
