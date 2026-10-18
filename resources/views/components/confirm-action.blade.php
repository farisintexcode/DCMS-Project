@props(['label', 'title', 'message', 'variant' => 'primary'])
<div x-data>
    <x-button type="button" :variant="$variant" @click="$refs.confirmDialog.showModal()" wire:loading.attr="disabled">{{ $label }}</x-button>
    <dialog x-ref="confirmDialog" aria-label="{{ $title }}" class="confirmation-dialog" @click="if ($event.target === $refs.confirmDialog) $refs.confirmDialog.close()">
        <div class="p-6 sm:p-8"><span class="icon-tile"><x-icon name="shield" /></span><h2 class="mt-5 text-xl font-semibold">{{ $title }}</h2><p class="mt-3 text-sm leading-6 text-ink-600">{{ $message }}</p><div class="mt-7 flex flex-wrap justify-end gap-3"><x-button type="button" variant="secondary" @click="$refs.confirmDialog.close()">Cancel</x-button><x-button type="button" :variant="$variant" @click="$refs.confirmDialog.close(); $el.closest('form').requestSubmit()" wire:loading.attr="disabled">Confirm</x-button></div></div>
    </dialog>
</div>
