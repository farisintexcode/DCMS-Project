@props(['label' => 'Loading…'])
<div {{ $attributes->class(['rounded-xl border border-ink-200 bg-white px-4 py-3 text-sm text-ink-600']) }} role="status">
    <div class="flex items-center gap-3"><span class="loading-spinner" aria-hidden="true"></span><span>{{ $label }}</span></div>
</div>
