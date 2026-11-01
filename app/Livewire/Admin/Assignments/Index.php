<?php

namespace App\Livewire\Admin\Assignments;

use App\Models\AssignmentBatch;
use App\Models\ReferenceValue;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** UI09/10; search is session-private, filters are shareable, every query checks scope. */
#[Layout('layouts.admin')]
class Index extends Component
{
    use WithPagination;

    #[Url]
    public string $status = 'active';

    #[Url]
    public string $type = '';

    #[Url]
    public string $state = '';

    #[Url]
    public string $from = '';

    #[Url]
    public string $until = '';

    #[Url]
    public string $sort = 'newest';

    #[Url]
    public string $perPage = '25';

    public string $query = '';

    public function mount(): void
    {
        $this->query = session('assignment_search.'.Auth::id(), '');
        session()->put('assignment_filters.'.Auth::id(), $this->filters());
    }

    public function updated(string $property): void
    {
        $this->resetPage();
        if ($property === 'query') {
            session()->put('assignment_search.'.Auth::id(), mb_substr($this->query, 0, 200));
        }
        session()->put('assignment_filters.'.Auth::id(), $this->filters());
    }

    private function filters(): array
    {
        return array_filter(['status' => $this->status, 'type' => $this->type, 'state' => $this->state, 'from' => $this->from, 'until' => $this->until, 'sort' => $this->sort, 'perPage' => $this->perPage], fn ($value) => $value !== '');
    }

    public function resetFilters(): void
    {
        $this->reset('status', 'type', 'state', 'from', 'until', 'sort', 'perPage', 'query');
        session()->forget(['assignment_search.'.Auth::id(), 'assignment_filters.'.Auth::id()]);
        $this->resetPage();
    }

    public function render(): View
    {
        Gate::authorize('viewAny', AssignmentBatch::class);
        $batches = AssignmentBatch::query()->with(['type', 'state']);
        in_array($this->status, ['active', 'archived'], true) ? $batches->where('status', $this->status) : $batches->whereRaw('false');
        $batches->when($this->type !== '', fn ($q) => $q->where('type_code', $this->type))->when($this->state !== '', fn ($q) => $q->where('state_code', $this->state));
        $dates = Validator::make(['from' => $this->from ?: null, 'until' => $this->until ?: null], ['from' => ['nullable', 'date_format:Y-m-d'], 'until' => ['nullable', 'date_format:Y-m-d', ...($this->from !== '' ? ['after_or_equal:from'] : [])]]);
        if ($dates->fails()) {
            $batches->whereRaw('false');
        } else {
            $batches->when($this->from !== '', fn ($q) => $q->where('start_date', '>=', $this->from))->when($this->until !== '', fn ($q) => $q->where('start_date', '<=', $this->until));
        }
        $term = trim($this->query);
        if (mb_strlen($term) > 200) {
            $batches->whereRaw('false');
        } elseif ($term !== '') {
            $literal = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';
            $batches->where(fn ($q) => $q->where('name', 'ilike', $literal)->orWhere('external_reference', 'ilike', $literal));
        }
        match ($this->sort) {
            'name' => $batches->orderBy('name')->orderBy('id'),
            'due' => $batches->orderBy('due_date')->orderBy('id'),
            default => $batches->orderByDesc('id'),
        };
        $size = in_array($this->perPage, ['25', '50', '100'], true) ? (int) $this->perPage : 25;

        return view('livewire.admin.assignments.index', [
            'batches' => $batches->paginate($size),
            'types' => ReferenceValue::query()->where('category', 'assignment_type')->orderBy('sort_order')->get(),
            'states' => ReferenceValue::query()->where('category', 'state')->orderBy('label')->get(),
            'filterError' => $dates->fails() ? $dates->errors()->first() : null,
        ]);
    }
}
