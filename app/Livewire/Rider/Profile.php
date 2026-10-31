<?php

namespace App\Livewire\Rider;

use App\Livewire\Account\EditsProfilePhoto;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/** M01 / UI05 / users and staff: authenticated Rider's own read-only identity and avatar. */
#[Layout('layouts.rider')]
class Profile extends Component
{
    use EditsProfilePhoto;

    protected function photoDestination(): string
    {
        return 'rider.profile';
    }

    public function render(): View
    {
        Gate::authorize('use-rider-interface');
        $user = Auth::user();

        return view('livewire.rider.profile', ['user' => $user, 'staff' => $user->staff, 'roles' => $user->getRoleNames()]);
    }
}
