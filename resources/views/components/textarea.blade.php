@props(['name', 'label'])
<div class="grid gap-2">
    <label for="{{ $name }}" class="text-sm font-medium">{{ $label }}</label>
    <textarea id="{{ $name }}" name="{{ $name }}" {{ $attributes->merge(['rows' => 3])->class(['w-full rounded-lg border border-slate-300 bg-white px-3 py-3 text-sm']) }} @error($name) aria-invalid="true" @enderror></textarea>
    @error($name)<p class="text-sm text-red-700">{{ $message }}</p>@enderror
</div>
