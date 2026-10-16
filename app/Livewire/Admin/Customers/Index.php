<?php

namespace App\Livewire\Admin\Customers;

use App\Models\CustomerCategory;
use App\Models\Debtor;
use App\Services\Customers\CustomerAccess;
use App\Services\Customers\CustomerSearch;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** M13 / UI62. PII queries stay out of URLs; bounded indexed searches only. */
#[Layout('layouts.admin')]
class Index extends Component
{
    use WithPagination;

    #[Url]
    public string $client = '';

    #[Url]
    public string $status = '';

    #[Url]
    public string $type = '';

    #[Url]
    public string $category = '';

    #[Url]
    public string $sort = 'newest';

    public string $query = '';

    public string $searchBy = 'reference';

    #[Url]
    public string $perPage = '25';

    public function mount(): void
    {
        $state = session('customer_search.'.Auth::id(), []);
        $this->query = $state['query'] ?? '';
        $this->searchBy = $state['searchBy'] ?? 'reference';
    }

    public function updated(string $property): void
    {
        $this->resetPage();
        if (in_array($property, ['query', 'searchBy'])) {
            session()->put('customer_search.'.Auth::id(), ['query' => mb_substr($this->query, 0, 200), 'searchBy' => $this->searchBy]);
        }
    }

    public function resetFilters(): void
    {
        $this->reset('client', 'status', 'type', 'category', 'sort', 'query', 'searchBy', 'perPage');
        session()->forget('customer_search.'.Auth::id());
        $this->resetPage();
    }

    public function render(): View
    {
        Gate::authorize('viewAny', Debtor::class);
        $access = app(CustomerAccess::class);
        $clients = $access->clients(Auth::user())->orderBy('name')->get();
        $query = $access->scope(Debtor::query(), Auth::user())->with(['client', 'categoryAssignments' => fn ($q) => $q->whereNull('revoked_at')->with('category')]);
        foreach (['client_id' => $this->client, 'status' => $this->status, 'party_type' => $this->type] as $field => $value) {
            if ($value !== '') {
                $query->where($field, $field === 'client_id' ? (int) $value : $value);
            }
        }
        if ($this->category !== '') {
            $query->whereHas('categoryAssignments', fn ($q) => $q->whereNull('revoked_at')->where('category_id', (int) $this->category));
        }
        if (mb_strlen($this->query) > 200) {
            $query->whereRaw('false');
        } else {
            app(CustomerSearch::class)->apply($query, $this->searchBy, $this->query);
        }
        $this->sort === 'reference' ? $query->orderBy('customer_reference')->orderBy('id') : $query->orderByDesc('id');
        $categories = $access->scope(CustomerCategory::query(), Auth::user())->when($this->client !== '', fn ($q) => $q->where('client_id', (int) $this->client))->orderBy('name')->get();

        $size = in_array($this->perPage, ['25', '50', '100'], true) ? (int) $this->perPage : 25;

        return view('livewire.admin.customers.index', ['customers' => $query->paginate($size), 'clients' => $clients, 'categories' => $categories]);
    }
}
