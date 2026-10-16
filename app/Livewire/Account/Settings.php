<?php

namespace App\Livewire\Account;

use App\Models\User;
use App\Services\AuditWriter;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;
use Livewire\Component;

/** Module.md M01 / Prototype.md UI05; avatar arrives with quarantined Document storage. */
class Settings extends Component
{
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
