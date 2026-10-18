<?php

namespace App\Livewire\Admin\Calls;

use App\Models\CollectionActivity;
use App\Models\CustomerCategory;
use App\Models\Staff;
use App\Models\Task;
use App\Services\Calls\CallAccess;
use App\Services\ClientAccess;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** M11/UI60: searchable scoped customer context, never legacy databank guesses. */
#[Layout('layouts.admin')]
class Index extends Component
{
    use WithPagination;

    public string $query = '';

    #[Url]
    public string $searchBy = 'reference';

    #[Url]
    public string $client = '';

    #[Url]
    public string $category = '';

    #[Url]
    public string $rider = '';

    public function mount(): void
    {
        $this->query = session('calls.search.'.auth()->id(), '');
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['query', 'searchBy', 'client', 'category', 'rider'], true)) {
            $this->resetPage();
        }
        if ($property === 'query') {
            session()->put('calls.search.'.auth()->id(), mb_substr($this->query, 0, 200));
        }
    }

    public function render(CallAccess $access, ClientAccess $clients): View
    {
        Gate::authorize('viewAny', CollectionActivity::class);
        $customers = $access->search(auth()->user(), $this->only(['query', 'searchBy', 'client', 'category', 'rider']))->with('client')->orderBy('customer_reference')->orderBy('id')->paginate(25);
        $canTasks = Gate::allows('viewAny', Task::class);

        return view('livewire.admin.calls.index', [
            'customers' => $customers, 'clients' => $clients->clients(auth()->user())->orderBy('name')->get(),
            'categories' => $clients->scope(CustomerCategory::query(), auth()->user())->where('is_active', true)->orderBy('name')->limit(200)->get(),
            'riders' => $canTasks ? Staff::query()->whereHas('user.clientScopes', fn ($q) => $q->whereIn('client_id', $clients->clients(auth()->user())->select('clients.id'))->where('valid_from', '<=', now())->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>', now())))->where('is_rider', true)->orderBy('staff_no')->limit(200)->get() : collect(),
            'canTasks' => $canTasks,
        ]);
    }
}
