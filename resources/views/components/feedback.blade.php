@if (session('status'))
<div role="status" class="flex items-start gap-3 rounded-2xl border border-green-200 bg-green-50 p-4 text-sm text-green-900"><x-icon name="check" class="mt-0.5 size-4" /><p class="flex-1 leading-6">{{ session('status') }}</p></div>
@endif
@if (isset($errors) && $errors->any())
<div role="alert" class="flex items-start gap-3 rounded-2xl border border-red-200 bg-red-50 p-5 text-sm text-red-900"><x-icon name="info" class="mt-0.5" /><div><p class="font-semibold">Please check the following:</p><ul class="mt-2 grid list-disc gap-1.5 pl-4 text-xs leading-5">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div></div>
@endif
