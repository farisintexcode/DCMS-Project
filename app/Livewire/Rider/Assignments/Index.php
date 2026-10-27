<?php

namespace App\Livewire\Rider\Assignments;

use App\Services\Tasks\RiderAssignments;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.rider')]
class Index extends Component
{
    use WithPagination;

    public function render(RiderAssignments $assignments): View
    {
        return view('livewire.rider.assignments.index', ['assignments' => $assignments->batches(auth()->user())->paginate(10)]);
    }
}
