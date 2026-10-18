@props(['title', 'message', 'icon' => 'search'])
<div {{ $attributes->class(['empty-state']) }}>
    <span class="icon-tile mx-auto"><x-icon :name="$icon" class="size-6" /></span>
    <h2 class="mt-5 text-base font-semibold tracking-tight">{{ $title }}</h2>
    <p class="mx-auto mt-2 max-w-md text-sm leading-6 text-ink-600">{{ $message }}</p>
    @if($slot->isNotEmpty())<div class="mt-5 flex flex-wrap justify-center gap-3">{{ $slot }}</div>@endif
</div>
