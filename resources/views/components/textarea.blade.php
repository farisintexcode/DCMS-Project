@props(['name', 'label'])
<div class="grid gap-2">
    <div class="flex items-center gap-1.5"><label for="{{ $name }}" class="field-label">{{ $label }}</label>@if($attributes->has('required'))<span class="text-xs text-ink-500" aria-hidden="true" title="Required">*</span>@endif</div>
    <textarea id="{{ $name }}" name="{{ $name }}" {{ $attributes->merge(['rows' => 3])->class(['field text-sm']) }} @error($name) aria-invalid="true" aria-describedby="{{ $name }}-error" @enderror></textarea>
    @error($name)<p id="{{ $name }}-error" class="text-sm text-red-700">{{ $message }}</p>@enderror
</div>
