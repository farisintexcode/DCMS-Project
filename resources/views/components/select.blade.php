@props(['name', 'label'])
<div class="grid gap-2">
    <label for="{{ $name }}" class="text-sm font-medium">{{ $label }}</label>
    <select id="{{ $name }}" name="{{ $name }}" {{ $attributes->class(['min-h-11 w-full rounded-lg border border-slate-300 bg-white px-3 py-3 text-sm']) }} @error($name) aria-invalid="true" aria-describedby="{{ $name }}-error" @enderror>{{ $slot }}</select>
    @error($name)<p id="{{ $name }}-error" class="text-sm text-red-700">{{ $message }}</p>@enderror
</div>
