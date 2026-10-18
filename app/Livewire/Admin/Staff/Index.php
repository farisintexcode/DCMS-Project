<?php

namespace App\Livewire\Admin\Staff;

use App\Models\ReferenceValue;
use App\Models\Staff;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** M10/UI30. Personnel access is explicit and independent of customer portfolio scopes. */
#[Layout('layouts.admin')]
class Index extends Component
{
    use WithPagination;

    #[Url]
    public string $kind = 'rider';

    #[Url]
    public string $status = '';

    #[Url]
    public string $position = '';

    #[Url]
    public string $from = '';

    #[Url]
    public string $until = '';

    #[Url]
    public string $sort = 'staff_no';

    #[Url]
    public string $perPage = '25';

    public string $query = '';

    public function mount(): void
    {
        $this->query = session('staff_search.'.Auth::id(), '');
    }

    public function updated(string $property): void
    {
        $this->resetPage();
        if ($property === 'query') {
            session()->put('staff_search.'.Auth::id(), mb_substr($this->query, 0, 200));
        }
    }

    public function resetFilters(): void
    {
        $this->reset('kind', 'status', 'position', 'from', 'until', 'sort', 'perPage', 'query');
        session()->forget('staff_search.'.Auth::id());
        $this->resetPage();
    }

    public function render(): View
    {
        Gate::authorize('viewAny', Staff::class);
        $query = Staff::query()->with(['position', 'user:id,staff_id,status']);
        if (in_array($this->kind, ['rider', 'office'], true)) {
            $query->where('is_rider', $this->kind === 'rider');
        } elseif ($this->kind !== 'all') {
            $query->whereRaw('false');
        }
        if ($this->status !== '') {
            in_array($this->status, ['active', 'inactive'], true) ? $query->where('is_active', $this->status === 'active') : $query->whereRaw('false');
        }
        $query->when($this->position !== '', fn ($q) => $q->where('position_code', $this->position));
        $dates = Validator::make(['from' => $this->from ?: null, 'until' => $this->until ?: null], ['from' => ['nullable', 'date_format:Y-m-d'], 'until' => ['nullable', 'date_format:Y-m-d', ...($this->from !== '' ? ['after_or_equal:from'] : [])]]);
        if ($dates->fails()) {
            $query->whereRaw('false');
        } else {
            $query->when($this->from !== '', fn ($q) => $q->where('appointment_date', '>=', $this->from))
                ->when($this->until !== '', fn ($q) => $q->where('appointment_date', '<=', $this->until));
        }
        $term = trim($this->query);
        if (mb_strlen($term) > 200) {
            $query->whereRaw('false');
        } elseif ($term !== '') {
            $prefix = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';
            $query->where(fn ($q) => $q->where('staff_no', 'ilike', $prefix)->orWhereRaw("concat_ws(' ', first_name, last_name) ilike ?", [$prefix])->orWhereRaw('lower(email) = ?', [mb_strtolower($term)]));
        }
        match ($this->sort) {
            'name' => $query->orderBy('first_name')->orderBy('last_name')->orderBy('id'),
            'appointment' => $query->orderByRaw('appointment_date desc nulls last')->orderByDesc('id'),
            default => $query->orderBy('staff_no')->orderBy('id'),
        };
        $size = in_array($this->perPage, ['25', '50', '100'], true) ? (int) $this->perPage : 25;

        return view('livewire.admin.staff.index', [
            'staffMembers' => $query->paginate($size, ['id', 'public_id', 'staff_no', 'first_name', 'last_name', 'appointment_date', 'position_code', 'is_active', 'is_rider']),
            'positions' => ReferenceValue::query()->where('category', 'position')->orderBy('sort_order')->get(),
            'filterError' => $dates->fails() ? $dates->errors()->first() : null,
        ]);
    }
}
