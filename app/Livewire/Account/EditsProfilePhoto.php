<?php

namespace App\Livewire\Account;

use App\Actions\Auth\ProfilePhoto;
use Illuminate\Support\Facades\Auth;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/** M01 / UI05 / documents: shared own-account avatar editor for existing settings and Rider profile. */
trait EditsProfilePhoto
{
    use WithFileUploads;

    public ?TemporaryUploadedFile $photo = null;

    public float $photoZoom = 1;

    public float $photoX = 50;

    public float $photoY = 50;

    public function savePhoto(ProfilePhoto $photos): void
    {
        abort_unless(Auth::user()?->canManageOwnProfilePhoto(), 403);
        $this->validate(ProfilePhoto::rules());
        $photos->save(Auth::user(), $this->photo, $this->photoZoom, $this->photoX, $this->photoY);
        $this->cancelPhoto();
        session()->flash('status', 'Your profile photo has been updated.');
        $this->redirectRoute($this->photoDestination());
    }

    public function cancelPhoto(): void
    {
        $this->reset('photo', 'photoZoom', 'photoX', 'photoY');
        $this->resetValidation(['photo', 'photoZoom', 'photoX', 'photoY']);
    }

    public function removePhoto(ProfilePhoto $photos): void
    {
        abort_unless(Auth::user()?->canManageOwnProfilePhoto(), 403);
        $photos->remove(Auth::user());
        $this->cancelPhoto();
        session()->flash('status', 'Your profile photo has been removed.');
        $this->redirectRoute($this->photoDestination());
    }

    protected function photoDestination(): string
    {
        return 'account.settings';
    }
}
