<div x-data="nexoraPhotoEditor($wire)">
    <div class="relative inline-flex">
        <span class="grid size-20 place-items-center overflow-hidden rounded-full border-2 border-white bg-brand-soft text-brand-dark text-2xl font-semibold"><x-profile-photo /></span>
        <button type="button" x-ref="editPhoto" @click="$refs.photoPicker.click()" aria-label="Edit profile photo" class="profile-photo-edit absolute -right-1 -bottom-1 grid size-9 place-items-center rounded-full border-2 border-white shadow-sm transition hover:scale-105 active:scale-95"><svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m15 5 4 4M4 20l5-1L20 8a3 3 0 0 0-4-4L5 15l-1 5Z" /></svg></button>
    </div>
    <input x-ref="photoPicker" @change="selectPhoto($event)" type="file" accept="image/jpeg,image/png,image/webp" aria-label="Choose profile picture" class="sr-only" tabindex="-1" />
    @if(auth()->user()->profilePhoto)
        <button type="button" wire:click="removePhoto" wire:confirm="Remove your profile photo?" wire:loading.attr="disabled" wire:target="savePhoto,removePhoto" class="mt-3 block text-xs text-ink-500 underline underline-offset-4">Remove photo</button>
    @endif
    <p x-show="localError && !$refs.photoDialog.open" x-text="localError" class="mt-3 text-xs text-red-600" role="alert"></p>
    <dialog x-ref="photoDialog" wire:ignore.self @cancel.prevent="cancelPhoto()" @close="restoreFocus()" class="profile-photo-dialog" aria-labelledby="photo-editor-title">
        <form wire:submit="savePhoto" class="flex max-h-[90dvh] flex-col gap-4">
            <div class="shrink-0 px-5 pt-5"><h2 id="photo-editor-title" class="text-lg font-semibold">Update profile photo</h2><p class="mt-1 text-xs text-ink-500">Adjust your photo inside the circular frame.</p></div>
            <div class="grid min-h-0 gap-4 overflow-y-auto px-5">
                <div class="profile-photo-preview mx-auto size-40 sm:size-44"><img x-ref="photoPreview" :src="preview || undefined" :style="previewStyle" @load="imageReady($event)" x-on:error="imageFailed()" alt="Selected photo preview" /></div>
                <label class="grid gap-1 text-xs">Zoom<input type="range" min="1" max="3" step="0.01" x-model.number="zoom" /></label>
                <label class="grid gap-1 text-xs">Horizontal position<input type="range" min="0" max="100" step="1" x-model.number="positionX" /></label>
                <label class="grid gap-1 text-xs">Vertical position<input type="range" min="0" max="100" step="1" x-model.number="positionY" /></label>
                <p class="text-xs text-ink-500">JPG, PNG or WebP · up to 5 MB · maximum 4096 × 4096 pixels.</p>
                @foreach(['photo', 'photoZoom', 'photoX', 'photoY'] as $field)
                    @error($field)<p class="text-xs text-red-600" role="alert">{{ $message }}</p>@enderror
                @endforeach
                <p x-show="localError" x-text="localError" class="text-xs text-red-600" role="alert"></p>
                <p x-show="uploading" class="text-xs text-ink-500" role="status">Preparing your photo…</p>
                <p wire:loading wire:target="savePhoto" class="text-xs text-ink-500" role="status">Checking and saving your photo…</p>
            </div>
            <div class="flex shrink-0 justify-end gap-3 border-t border-ink-100 px-5 py-4"><button type="button" @click="cancelPhoto()" wire:loading.attr="disabled" wire:target="savePhoto" class="btn btn-secondary">Cancel</button><x-button x-bind:disabled="uploading || !imageLoaded || !!localError" wire:loading.attr="disabled" wire:target="savePhoto">Save Photo</x-button></div>
        </form>
    </dialog>
</div>
