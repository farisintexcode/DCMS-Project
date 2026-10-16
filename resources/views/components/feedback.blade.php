@if (session('status'))<div role="status" class="rounded-lg border border-teal-200 bg-teal-50 p-4 text-sm text-teal-900">{{ session('status') }}</div>@endif
@if ($errors->any())
    <div role="alert" class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-900">
        <p class="font-semibold">Please check the following:</p>
        <ul class="mt-2 list-disc pl-5">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
    </div>
@endif
