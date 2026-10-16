<section class="grid gap-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div><p class="text-xs font-semibold tracking-widest text-teal-700 uppercase">Customer Management</p><h1 class="mt-2 text-3xl font-semibold">Customers</h1><p class="mt-2 text-sm text-slate-600">Customer records within your current client portfolio.</p></div>
        <div class="flex flex-wrap gap-3">@can('customers.manage_categories')<a class="rounded-lg border border-slate-300 px-4 py-3 text-sm font-medium" href="{{ route('admin.customers.categories') }}">Categories</a>@endcan @can('customers.create')<a class="rounded-lg bg-teal-700 px-4 py-3 text-sm font-semibold text-white" href="{{ route('admin.customers.create') }}">Create customer</a>@endcan</div>
    </div>
    <div class="grid gap-4 rounded-2xl border border-slate-200 bg-white p-5 sm:grid-cols-2 xl:grid-cols-4">
        <x-select name="client" label="Client" wire:model.live="client"><option value="">All permitted clients</option>@foreach($clients as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach</x-select>
        <x-select name="type" label="Customer type" wire:model.live="type"><option value="">All types</option><option value="person">Individual</option><option value="company">Company</option></x-select>
        <x-select name="status" label="Status" wire:model.live="status"><option value="">All statuses</option><option value="active">Active</option><option value="inactive">Inactive</option></x-select>
        <x-select name="category" label="Category" wire:model.live="category"><option value="">All categories</option>@foreach($categories as $c)<option value="{{ $c->id }}">{{ $c->name }}{{ $c->is_active ? '' : ' (inactive)' }}</option>@endforeach</x-select>
        <x-select name="searchBy" label="Search by" wire:model.live="searchBy"><option value="reference">Exact customer reference</option><option value="name">Name tokens / prefixes</option><option value="identifier">Exact identifier</option><option value="contact">Exact phone / email</option></x-select>
        <x-input name="query" label="Search customers" wire:model.live.debounce.400ms="query" maxlength="200" autocomplete="off" />
        <x-select name="sort" label="Sort" wire:model.live="sort"><option value="newest">Newest first</option><option value="reference">Reference</option></x-select>
        <x-select name="perPage" label="Customers per page" wire:model.live="perPage"><option value="25">25</option><option value="50">50</option><option value="100">100</option></x-select>
        <div class="flex items-end"><button type="button" wire:click="resetFilters" class="min-h-11 text-sm text-teal-800 underline">Clear filters</button></div>
        @if($searchBy === 'name')<p class="text-xs text-slate-500 sm:col-span-2 xl:col-span-4">Use at least 3 characters per name token. Sensitive search text is kept out of the address bar.</p>@endif
    </div>
    <p role="status" wire:loading class="text-sm text-teal-800">Loading customers…</p>
    @if($clients->isEmpty())
        <div class="rounded-xl border border-amber-200 bg-amber-50 p-6 text-sm">No client portfolio access is currently granted. Ask an authorized administrator to grant access before creating or viewing customers.</div>
    @else
        <p class="text-sm text-slate-500">{{ $customers->total() }} matching customers · {{ $customers->perPage() }} per page</p>
        <div class="grid gap-3 lg:hidden">
            @foreach($customers as $customer)<article class="grid gap-2 rounded-xl border border-slate-200 bg-white p-5" wire:key="customer-card-{{ $customer->public_id }}"><a class="font-semibold text-teal-800 underline" href="{{ route('admin.customers.show', ['customer' => $customer->public_id]) }}">{{ $customer->display_name_ciphertext }}</a><p class="text-sm">Reference: {{ $customer->customer_reference }}</p><p class="text-xs text-slate-500">{{ $customer->client->name }} · {{ ucfirst($customer->party_type) }} · {{ ucfirst($customer->status) }}</p><p class="text-xs">@foreach($customer->categoryAssignments as $membership)<span class="mr-2 inline-block rounded bg-slate-100 px-2 py-1">{{ $membership->category->name }}</span>@endforeach</p></article>@endforeach
        </div>
        <div class="hidden overflow-x-auto rounded-xl border border-slate-200 bg-white lg:block">
            <table class="w-full text-left text-sm"><thead class="border-b border-slate-200 bg-slate-50 text-xs text-slate-600"><tr>@foreach(['Reference', 'Customer', 'Client', 'Type', 'Categories', 'Status'] as $heading)<th class="px-5 py-4" scope="col">{{ $heading }}</th>@endforeach</tr></thead><tbody class="divide-y divide-slate-100">
            @foreach($customers as $customer)<tr wire:key="customer-{{ $customer->public_id }}"><td class="px-5 py-4"><a class="font-medium text-teal-800 underline" href="{{ route('admin.customers.show', ['customer' => $customer->public_id]) }}">{{ $customer->customer_reference }}</a></td><td class="px-5 py-4">{{ $customer->display_name_ciphertext }}</td><td class="px-5 py-4">{{ $customer->client->name }}</td><td class="px-5 py-4">{{ $customer->party_type === 'person' ? 'Individual' : 'Company' }}</td><td class="px-5 py-4">@forelse($customer->categoryAssignments as $membership)<span class="mr-1 inline-block rounded bg-slate-100 px-2 py-1 text-xs">{{ $membership->category->name }}</span>@empty<span class="text-slate-400">None</span>@endforelse</td><td class="px-5 py-4"><span class="rounded-full bg-slate-100 px-3 py-1 text-xs">{{ ucfirst($customer->status) }}</span></td></tr>@endforeach
            </tbody></table>
        </div>
        @if($customers->isEmpty())<div class="rounded-xl border border-slate-200 bg-white p-8 text-center"><h2 class="font-semibold">No customers match these filters</h2><p class="mt-2 text-sm text-slate-500">Clear the filters or create an authorized customer record.</p></div>@endif
        {{ $customers->links() }}
    @endif
</section>
