<?php

namespace App\Livewire\Admin\Communications;

use App\Models\CommunicationTemplate;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/** M14/UI65 template readiness; delivery is a separately activated workflow. */
#[Layout('layouts.admin')]
class Index extends Component
{
    public function render(): View
    {
        Gate::authorize('viewAny', CommunicationTemplate::class);
        $rows = CommunicationTemplate::query()->where('channel', 'email')->whereIn('email_slot', [1, 2, 3])->whereIn('status', ['draft', 'pending_approval', 'approved'])->orderBy('email_slot')->orderByDesc('version')->get();
        $options = collect([1, 2, 3])->map(fn ($slot) => ['slot' => $slot, 'draft' => $rows->first(fn ($r) => $r->email_slot === $slot && in_array($r->status, ['draft', 'pending_approval'], true)), 'approved' => $rows->first(fn ($r) => $r->email_slot === $slot && $r->status === 'approved'), 'latest' => CommunicationTemplate::query()->where('channel', 'email')->where('email_slot', $slot)->latest('version')->first()]);
        $context = [];
        foreach (['customer', 'task'] as $key) {
            $value = request()->query($key);
            if (is_string($value) && Str::isUuid($value)) {
                $context[$key] = $value;
            }
        }

        return view('livewire.admin.communications.index', ['options' => $options, 'approvedCount' => $rows->where('status', 'approved')->count(), 'context' => $context]);
    }
}
