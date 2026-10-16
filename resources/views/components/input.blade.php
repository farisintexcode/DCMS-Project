@props(['name', 'label', 'type' => 'text'])
<div class="grid gap-2">
    <label for="{{ $name }}" class="text-sm font-medium">{{ $label }}</label>
    <input id="{{ $name }}" name="{{ $name }}" type="{{ $type }}" {{ $attributes->class(['w-full rounded-lg border border-slate-300 bg-white px-3 py-3 text-base focus:border-teal-700 focus:ring-2 focus:ring-teal-700/20']) }} @error($name) aria-invalid="true" aria-describedby="{{ $name }}-error" @enderror>
    @error($name)<p id="{{ $name }}-error" class="text-sm text-red-700">{{ $message }}</p>@enderror
</div>
