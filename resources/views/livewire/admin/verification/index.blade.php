<section class="grid min-w-0 gap-6">
<x-feedback />
<x-page-header title="Task verification" eyebrow="Independent review" description="Review immutable Rider submissions. Only verified work becomes Completed." />
<form wire:submit="$refresh" class="surface grid min-w-0 gap-4 p-5 md:grid-cols-3">
<x-select name="status" label="Verification status" wire:model.live="status"><option value="pending">Pending verification</option><option value="approved">Approved / Completed</option><option value="returned">Returned to Rider</option><option value="all">All submission cycles</option></x-select>
<x-input name="query" label="Account or customer reference" wire:model="query" placeholder="Exact reference" />
<x-select name="batch" label="Assignment batch" wire:model.live="batch"><option value="">All accessible batches</option>@foreach($batches as $batchOption)<option value="{{ $batchOption->public_id }}">{{ $batchOption->name }}</option>@endforeach</x-select>
<x-select name="rider" label="Submitting Rider" wire:model.live="rider"><option value="">All submitting Riders</option>@foreach($riders as $riderOption)<option value="{{ $riderOption->public_id }}">{{ $riderOption->first_name.' '.$riderOption->last_name }}</option>@endforeach</x-select>
<div class="grid min-w-0 gap-4 sm:grid-cols-2 md:col-span-2"><x-input name="from" type="date" label="Submitted from (MYT)" wire:model="from" class="min-w-0 max-w-full" /><x-input name="until" type="date" label="Submitted through (MYT)" wire:model="until" class="min-w-0 max-w-full" /></div>
<x-button type="submit" variant="secondary">Apply filters</x-button>
</form>
@if($dateError)<p role="alert" class="rounded-xl bg-amber-50 p-4 text-sm text-amber-950">{{ $dateError }}</p>@endif
<p class="text-xs text-ink-500">Select explicit submissions only (maximum 50). Filters and pagination preserve selection until criteria change. Current page is not automatically selected.</p>
<div wire:loading.delay role="status" class="rounded-xl bg-brand-soft p-3 text-sm">Updating verification workspace…</div>
<div class="grid gap-4 xl:grid-cols-2">
@forelse($submissions as $submission)
<article class="surface grid gap-4 p-5" wire:key="verify-{{ $submission->public_id }}"><div class="flex flex-wrap items-center justify-between gap-3"><x-badge :tone="$submission->review?->decision==='approved'?'success':($submission->review?'warning':'brand')">{{ $submission->review?->decision??'Pending verification' }}</x-badge>@if(!$submission->review)<label class="flex min-h-11 items-center gap-2 text-xs font-semibold"><input type="checkbox" wire:model.live="selected" value="{{ $submission->public_id }}" class="size-5 accent-brand-primary" />Select submission {{ $submission->submission_version }}</label>@endif</div>
<h2 class="font-semibold">{{ $submission->completion_snapshot_ciphertext['customer']['name']??'Customer' }}</h2><p class="text-sm text-ink-600">{{ $submission->completion_snapshot_ciphertext['account']??'—' }} · {{ $submission->task->batch->name }}</p><p class="text-xs text-ink-500">{{ $submission->submitter->name }} · Version {{ $submission->submission_version }} · {{ $submission->submitted_at->timezone(config('dcms.business_timezone'))->format('d M Y H:i') }} MYT</p><p class="break-all text-[11px] text-ink-500">{{ $submission->public_id }}</p><a href="{{ route('admin.verification.show',$submission) }}" class="btn btn-secondary">Review submission</a></article>
@empty<x-empty-state title="No submissions in this view" message="Completed Rider drafts appear here after a valid submission. Try another filter." icon="shield" />@endforelse
</div>{{ $submissions->links() }}
<form wire:submit="prepareReview" class="surface grid gap-4 p-5"><h2 class="text-lg font-semibold">Review selected submissions · {{ count($selected) }}</h2><x-select name="decision" label="Decision" wire:model.live="decision"><option value="approved">Approve / Verify & complete</option><option value="returned">Reject / Return to Rider</option></x-select><div class="grid gap-2"><label for="reason" class="field-label">Verification remarks {{ $decision==='returned'?'*':'(optional)' }}</label><textarea id="reason" wire:model="reason" class="field min-h-28" maxlength="5000" @required($decision==='returned')></textarea><p class="field-help">Return requires clear correction instructions. Remarks are encrypted and retained with the immutable decision.</p>@error('reason')<p class="text-sm text-red-800">{{ $message }}</p>@enderror</div><x-button type="submit" wire:loading.attr="disabled">Prepare review preview</x-button></form>
<x-verification-preview :preview="$preview" :results="$results" />
</section>

