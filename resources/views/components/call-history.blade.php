@props(['history'])
<div class="grid min-w-0 gap-4">
@forelse($history as $event)
<article class="surface grid min-w-0 gap-4 p-5" wire:key="conversation-{{ $event->public_id }}"><div class="flex flex-wrap items-start justify-between gap-3"><div><h3 class="font-semibold">{{ $event->activity_type_code==='call'?'Manual outgoing call':'Site visit' }}</h3><p class="mt-2 text-xs text-ink-500">{{ $event->occurred_at->timezone(config('dcms.business_timezone'))->format('d M Y H:i:s') }} MYT · {{ $event->performer->name }}</p></div><x-badge tone="brand">{{ $event->call_details_ciphertext['outcome_label'] ?? ucwords(str_replace('_',' ',$event->outcome_code ?? 'Recorded')) }}</x-badge></div>
@if($event->activity_type_code==='call')
@if($event->call_details_ciphertext['contact'] ?? null)<p class="text-sm">Contact used: {{ $event->call_details_ciphertext['contact']['value'] }}</p>@endif
@if($event->person_contacted_ciphertext)<p class="text-sm">Person contacted: {{ $event->person_contacted_ciphertext }}</p>@endif
<p class="whitespace-pre-line break-words text-sm leading-6">{{ $event->notes_ciphertext }}</p>
@if($event->call_details_ciphertext['other_detail'] ?? null)<p class="break-words text-sm">{{ $event->call_details_ciphertext['other_detail'] }}</p>@endif
@if($event->call_details_ciphertext['promise'] ?? null)@php($promise=$event->call_details_ciphertext['promise'])<div class="rounded-xl bg-brand-soft p-4 text-sm"><p class="font-semibold">Promise to Pay — stated context</p><p class="mt-2 break-words">{{ $promise['notes'] }}</p>@if($promise['stated_date'] ?? null)<p class="mt-2">Stated date: {{ $promise['stated_date'] }}</p>@endif @if($promise['stated_amount'] ?? null)<p class="mt-2">Stated amount: {{ $promise['currency'] }} {{ $promise['stated_amount'] }}</p>@endif<p class="mt-2 text-xs">No payment or receipt has been posted.</p></div>@endif
@if($event->backdating_reason_ciphertext)<p class="text-xs leading-5 text-ink-600">Historical entry reason: {{ $event->backdating_reason_ciphertext }}</p>@endif
<p class="text-xs text-ink-500">Entered {{ $event->created_at->timezone(config('dcms.business_timezone'))->format('d M Y H:i:s') }} MYT · {{ $event->public_id }}</p>
@else<p class="whitespace-pre-line break-words text-sm">{{ $event->visit_remarks_ciphertext }}</p>@endif
@foreach($event->followUps as $reminder)<div class="rounded-xl border border-ink-200 p-4 text-sm"><div class="flex flex-wrap justify-between gap-2"><strong>Follow-up · {{ $reminder->due_at->timezone(config('dcms.business_timezone'))->format('d M Y H:i') }} MYT</strong><x-badge>{{ ucfirst($reminder->status) }}</x-badge></div><p class="mt-2 text-xs text-ink-500">Assigned to {{ $reminder->assignee->name }}</p>@if($reminder->notes_ciphertext)<p class="mt-2 break-words">{{ $reminder->notes_ciphertext }}</p>@endif @if($reminder->resolved_at)<p class="mt-2 break-words">{{ $reminder->resolution_ciphertext }}</p><p class="mt-2 text-xs text-ink-500">{{ $reminder->resolver?->name }} · {{ $reminder->resolved_at->timezone(config('dcms.business_timezone'))->format('d M Y H:i') }} MYT</p>@endif</div>@endforeach
</article>
@empty<x-empty-state title="No conversations recorded" message="Manual calls and authorized visits will appear here. Record the first call outcome to start the timeline." icon="phone" />@endforelse
{{ $history->links() }}</div>
