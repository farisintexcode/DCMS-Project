<div x-data="nexoraPhotoEditor($wire)" class="profile-photo-editor">
    <div class="relative inline-flex">
        <span class="profile-avatar grid size-20 place-items-center overflow-hidden rounded-full border-2 border-white bg-brand-soft text-brand-dark text-2xl font-semibold shadow-sm"><x-profile-photo /></span>
        @if(auth()->user()->canManageOwnProfilePhoto())
            <button x-ref="editPhoto" type="button" @click="$refs.photoPicker.click()" class="profile-photo-edit" aria-label="Edit profile photo" title="Edit profile photo"><x-icon name="edit" class="size-4" /></button>
        @endif
    </div>
    @if(auth()->user()->canManageOwnProfilePhoto())
        <input x-ref="photoPicker" type="file" @change="selectPhoto($event)" accept="image/jpeg,image/png,image/webp" class="sr-only" tabindex="-1" aria-label="Choose profile picture" />
        @if(auth()->user()->profilePhoto)
            <button type="button" class="mt-3 block min-h-11 text-xs text-ink-500 underline decoration-ink-200 underline-offset-4 transition-colors hover:text-brand-dark" wire:click="removePhoto" wire:confirm="Remove your profile photo and return to your initials?" wire:loading.attr="disabled" wire:target="photo,savePhoto,removePhoto">Remove photo</button>
        @endif
        <p x-cloak x-show="localError" x-text="localError" role="alert" class="mt-3 text-sm text-red-700 dark:text-red-300"></p>
        <dialog x-ref="photoDialog" wire:ignore.self @cancel.prevent="cancelPhoto()" @close="restoreFocus()" class="profile-photo-dialog" aria-labelledby="photo-editor-title" aria-describedby="photo-editor-description">
            <form wire:submit="savePhoto" class="flex max-h-[90dvh] flex-col gap-4">
                <div class="flex shrink-0 items-start justify-between gap-3 px-5 pt-5 sm:px-6"><div><h2 id="photo-editor-title" class="text-lg font-semibold">Update profile photo</h2><p id="photo-editor-description" class="mt-2 text-xs leading-5 text-ink-500">Adjust your photo inside the circle before saving.</p></div><button type="button" class="grid size-11 shrink-0 place-items-center rounded-full transition-colors hover:bg-brand-soft" aria-label="Cancel photo editing" @click="cancelPhoto()"><x-icon name="close" /></button></div>
                <div class="grid min-h-0 gap-4 overflow-y-auto px-5 sm:px-6">
                <div class="profile-photo-preview" aria-label="Profile photo preview">
                    <img x-ref="photoPreview" :src="preview || undefined" alt="Selected profile picture preview" :style="previewStyle" @load="imageReady($event)" x-on:error="imageFailed()" />
                </div>
                <div class="grid gap-2">
                    <label class="grid gap-2 text-xs font-medium">Zoom<input type="range" min="1" max="3" step="0.05" x-model.number="zoom" class="profile-photo-range" /></label>
                    <label class="grid gap-2 text-xs font-medium">Horizontal position<input type="range" min="0" max="100" step="1" x-model.number="positionX" class="profile-photo-range" /></label>
                    <label class="grid gap-2 text-xs font-medium">Vertical position<input type="range" min="0" max="100" step="1" x-model.number="positionY" class="profile-photo-range" /></label>
                </div>
                <p class="text-xs leading-5 text-ink-500">JPG, JPEG, PNG or WebP · Up to 5 MB · Maximum 4096 × 4096 pixels.</p>
                <p x-cloak x-show="localError" x-text="localError" role="alert" class="text-sm text-red-700 dark:text-red-300"></p>
                @if(!config('filesystems.disks.s3.bucket'))<p role="status" class="rounded-xl bg-brand-soft p-3 text-xs leading-5">Photo uploads are temporarily unavailable. Please contact your administrator.</p>@endif
                @foreach(['photo', 'photoZoom', 'photoX', 'photoY'] as $photoField)
                    @error($photoField)<p role="alert" class="text-sm text-red-700 dark:text-red-300">{{ $message }}</p>@enderror
                @endforeach
                <p x-cloak x-show="uploading" role="status" class="text-xs text-ink-500">Uploading your photo…</p>
                <p wire:loading wire:target="savePhoto" role="status" class="text-xs text-ink-500">Checking and saving your photo…</p>
                </div>
                <div class="flex shrink-0 flex-wrap justify-end gap-3 border-t border-ink-200 px-5 py-4 sm:px-6"><button type="button" class="btn btn-secondary" @click="cancelPhoto()" wire:loading.attr="disabled" wire:target="savePhoto">Cancel</button><x-button type="submit" x-bind:disabled="uploading || !imageLoaded || !!localError" wire:loading.attr="disabled" wire:target="savePhoto">Save Photo</x-button></div>
            </form>
        </dialog>
    @endif
</div>
