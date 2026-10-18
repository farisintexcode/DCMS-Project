<section class="grid min-w-0 gap-5">
<x-feedback />
<div><p class="eyebrow">Field workspace</p><h1 class="mt-2 text-2xl font-semibold tracking-tight">My Tasks</h1><p class="mt-2 text-sm text-ink-600">Your assigned work, next actions and submission receipts.</p></div>
<form wire:submit="$refresh" class="surface grid min-w-0 gap-4 p-5 sm:grid-cols-2">
<x-select name="status" label="View" wire:model.live="status">@foreach(['pending'=>'Pending work','today'=>"Today's tasks",'all'=>'All current assignments','assigned'=>'Assigned · not started','in_progress'=>'In progress','returned'=>'Returned for correction','submitted'=>'Pending verification','completed'=>'Completed','history'=>'My submission history'] as $code=>$label)<option value="{{ $code }}">{{ $label }}</option>@endforeach</x-select>
<x-input name="query" label="Account or customer reference" wire:model="query" placeholder="Exact reference" />
<x-input name="batch" label="Batch reference" wire:model="batch" />
<x-input name="due" type="date" label="Due date (MYT)" wire:model="due" class="min-w-0 max-w-full" />
<div class="flex flex-wrap gap-2 sm:col-span-2"><x-button type="submit" variant="secondary">Search</x-button><x-button type="button" variant="secondary" wire:click="clearFilters">Clear filters</x-button></div>
</form>
<div wire:loading.delay role="status" class="rounded-xl bg-brand-soft p-3 text-sm">Updating your task list…</div>
@if($status==='history')
<p class="text-xs leading-5 text-ink-500">Receipts contain only your own submission references and timestamps. Customer data and proof stay protected after reassignment.</p>
@forelse($history as $submission)
<a href="{{ route('rider.tasks.history',$submission) }}" class="surface grid gap-2 p-5 transition hover:border-brand-primary"><span class="font-semibold">Submission {{ $submission->submission_version }}</span><span class="break-all text-xs text-ink-600">{{ $submission->public_id }}</span><span class="text-xs">{{ $submission->submitted_at->timezone(config('dcms.business_timezone'))->format('d M Y · H:i') }} MYT</span></a>
@empty<x-empty-state title="No submission history" message="Your submitted work will appear here." />@endforelse
{{ $history->links() }}
@else
@forelse($tasks as $task)
<article class="surface grid min-w-0 gap-4 p-5" wire:key="rider-task-{{ $task->public_id }}">
<div class="flex flex-wrap items-center justify-between gap-2"><x-badge :tone="$task->review_status==='returned'?'danger':'brand'">{{ $task->review_status==='returned'?'Returned for correction':($task->work_status==='submitted'?'Pending verification':str_replace('_',' ',ucfirst($task->work_status))) }}</x-badge><x-badge>{{ ucfirst($task->priority_code) }}</x-badge></div>
<div><h2 class="text-lg font-semibold">{{ $task->customer->display_name_ciphertext }}</h2><p class="mt-1 break-words text-sm text-ink-600">{{ $task->account->account_number }} · {{ $task->batch->name }}</p></div>
<dl class="grid grid-cols-2 gap-3 border-t border-ink-100 pt-4 text-xs"><div><dt class="text-ink-500">Planned visit</dt><dd class="mt-1 font-medium">{{ $task->scheduled_visit_at?->timezone(config('dcms.business_timezone'))->format('d M · H:i')??'Not scheduled' }}</dd></div><div><dt class="text-ink-500">Due · MYT</dt><dd class="mt-1 font-medium">{{ $task->effectiveDue()->timezone(config('dcms.business_timezone'))->format('d M · H:i') }}</dd></div></dl>
<a href="{{ route('rider.tasks.show',$task) }}" class="btn btn-primary w-full"><x-icon name="arrow-right" class="size-4" />View Task</a>
</article>
@empty<x-empty-state title="No tasks in this view" message="Try another filter. Your Admin assigns work and client access." />@endforelse
{{ $tasks->links() }}
@endif
</section>
