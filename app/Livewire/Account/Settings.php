<?php

namespace App\Livewire\Account;

use App\Actions\Auth\ProfilePhoto;
use App\Models\User;
use App\Services\AuditWriter;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/** Module.md M01 / Prototype.md UI05; own user identity, password and private Document avatar. */
class Settings extends Component
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
        $this->redirectRoute('account.settings');
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
        $this->redirectRoute('account.settings');
    }

    public string $current_password = '';

    public string $password = '';

    public string $password_confirmation = '';

    public function save(AuditWriter $audit): void
    {
        abort_unless(Auth::user()?->isActive(), 403);
        $this->validate(['current_password' => ['required', 'current_password:web'], 'password' => ['required', 'confirmed', 'different:current_password', Password::min(12)->mixedCase()->numbers()->symbols()]]);
        DB::transaction(function () use ($audit): void {
            $user = User::query()->lockForUpdate()->findOrFail(Auth::id());
            $user->password = $this->password;
            $user->remember_token = Str::random(60);
            $user->save();
            $audit->record($user, 'password_changed', metadata: ['changed_fields' => ['password']]);
            Auth::setUser($user);
        });
        session()->put('password_hash_web', Auth::user()->getAuthPassword());
        session()->regenerate();
        $this->reset('current_password', 'password', 'password_confirmation');
        session()->flash('status', 'Your password has been updated. Other sessions will require sign-in.');
    }

    public function render(): View
    {
        abort_unless(Auth::user()?->isActive(), 403);

        return view('livewire.account.settings')->layout(Auth::user()->can('admin.access') ? 'layouts.admin' : 'layouts.rider');
    }
}
