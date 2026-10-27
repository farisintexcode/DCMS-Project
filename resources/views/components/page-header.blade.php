@props(['title', 'eyebrow' => 'Nexora workspace', 'description' => ''])
<div {{ $attributes->class(['flex flex-wrap items-end justify-between gap-5']) }}>
    <div class="min-w-0"><p class="eyebrow">{{ $eyebrow }}</p><h1 class="page-heading mt-2">{{ $title }}</h1>@if($description)<p class="mt-2 max-w-2xl text-sm leading-6 text-ink-600">{{ $description }}</p>@endif</div>
    @if(isset($actions))<div class="flex flex-wrap items-center gap-3">{{ $actions }}</div>@endif
</div>
