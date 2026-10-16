<?php

namespace App\Livewire\Rider;

use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.rider')]
class Home extends Component
{
    public function render(): View
    {
        Gate::authorize('use-rider-interface');

        return view('livewire.rider.home');
    }
}
