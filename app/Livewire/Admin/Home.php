<?php

namespace App\Livewire\Admin;

use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.admin')]
class Home extends Component
{
    public function render(): View
    {
        Gate::authorize('admin.access');

        return view('livewire.admin.home');
    }
}
