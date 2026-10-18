<?php

namespace App\Livewire\Admin\Staff;

use App\Actions\Staff\SaveStaff;
use App\Models\ReferenceValue;
use App\Models\Staff;
use App\Services\Staff\StaffAssignments;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

#[Layout('layouts.admin')]
class Editor extends Component
{
    #[Locked]
    public ?int $staffId = null;

    #[Locked]
    public ?string $staffPublicId = null;

    #[Locked]
    public ?string $revision = null;

    public array $data = ['staff_no' => '', 'first_name' => '', 'last_name' => '', 'email' => '', 'gender_code' => '', 'position_code' => '', 'appointment_date' => '', 'phone' => '', 'home_phone' => '', 'address' => '', 'is_active' => true, 'is_rider' => true, 'reason' => ''];

    public function mount(?Staff $staff = null): void
    {
        Gate::authorize($staff?->exists ? 'update' : 'create', $staff?->exists ? $staff : Staff::class);
        if (! $staff?->exists) {
            return;
        }
        $this->staffId = $staff->id;
        $this->staffPublicId = $staff->public_id;
        $this->revision = $staff->revision();
        foreach (['staff_no', 'first_name', 'last_name', 'email', 'gender_code', 'position_code'] as $field) {
            $this->data[$field] = $staff->{$field} ?? '';
        }
        $this->data['appointment_date'] = $staff->appointment_date?->format('Y-m-d') ?? '';
        foreach (['phone', 'home_phone', 'address'] as $field) {
            $this->data[$field] = $staff->{$field.'_ciphertext'} ?? '';
        }
        $this->data['is_active'] = $staff->is_active;
        $this->data['is_rider'] = $staff->is_rider;
    }

    public function save(SaveStaff $save): void
    {
        $existing = $this->staffId ? Staff::query()->findOrFail($this->staffId) : null;
        $staff = $save->handle(Auth::user(), $this->data, $existing, $this->revision);
        $this->reset('data');
        session()->flash('status', 'Staff record saved. Account permissions are unchanged.');
        $this->redirectRoute('admin.staff.show', ['staff' => $staff->public_id]);
    }

    public function render(StaffAssignments $assignments): View
    {
        $staff = $this->staffId ? Staff::query()->findOrFail($this->staffId) : null;
        Gate::authorize($staff ? 'update' : 'create', $staff ?? Staff::class);

        return view('livewire.admin.staff.editor', [
            'staff' => $staff,
            'outstanding' => $staff ? $assignments->outstanding($staff) : null,
            'positions' => ReferenceValue::query()->where('category', 'position')->where(fn ($q) => $q->where('is_active', true)->orWhere('code', $staff?->position_code))->orderBy('sort_order')->get(),
            'genders' => ReferenceValue::query()->where('category', 'gender')->where(fn ($q) => $q->where('is_active', true)->orWhere('code', $staff?->gender_code))->orderBy('sort_order')->get(),
        ]);
    }
}
