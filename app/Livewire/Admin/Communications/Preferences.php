<?php

namespace App\Livewire\Admin\Communications;

use App\Actions\Communications\ManagePreferences;
use App\Models\CommunicationPreference;
use App\Models\Debtor;
use App\Models\Task;
use App\Services\Communications\RecipientAccess;
use App\Services\Communications\TemplateContext;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

/** M14 approved service-email policy and evidence review workspace. */
#[Layout('layouts.admin')]
class Preferences extends Component
{
    use WithPagination;

    #[Locked]
    public int $customerId;

    #[Locked]
    public string $requestKey;

    public array $form = [];

    public array $reviewReasons = [];

    public function mount(Debtor $customer): void
    {
        $this->customerId = $customer->id;
        $this->customer();
        $this->resetForm();
    }

    private function customer(): Debtor
    {
        abort_unless(collect(['create', 'update', 'block', 'approve', 'override'])->contains(fn ($name) => auth()->user()->can('communication-permission.'.$name)), 403);

        return app(RecipientAccess::class)->customers(auth()->user())->with('client')->findOrFail($this->customerId);
    }

    private function resetForm(): void
    {
        $this->requestKey = (string) Str::uuid7();
        $this->form = ['state' => 'unknown', 'basis' => '', 'contact' => '', 'account' => '', 'task' => '', 'source' => '', 'reference' => '', 'email_source' => '', 'reason' => '', 'notes' => '', 'effective_at' => '', 'expires_at' => '', 'notice_version' => '', 'consent_method' => '', 'granted_at' => '', 'assignment_date' => '', 'authority_reference' => ''];
    }

    public function save(ManagePreferences $manage): void
    {
        $manage->request(auth()->user(), $this->customer()->public_id, $this->form, $this->requestKey);
        $this->resetForm();
        $this->resetPage();
        session()->flash('status', 'Email authority recorded. Pending restrictions suppress immediately; allowed requests need approval.');
    }

    public function review(string $id, string $decision, int $version, ManagePreferences $manage): void
    {
        $customer = $this->customer();
        abort_unless(Str::isUuid($id), 404);
        $pref = CommunicationPreference::query()->where('customer_id', $customer->id)->where('public_id', $id)->firstOrFail();
        $manage->review(auth()->user(), $pref, $version, $decision, $this->reviewReasons[$id] ?? '');
        unset($this->reviewReasons[$id]);
        session()->flash('status', 'Eligibility review recorded. Evidence and previous decisions are retained.');
    }

    public function render(TemplateContext $context): View
    {
        $customer = $this->customer();

        return view('livewire.admin.communications.preferences', ['customer' => $customer, 'contacts' => $customer->contacts()->where('kind_code', 'email')->whereNull('valid_to')->orderBy('id')->get(), 'accounts' => $context->accounts($customer)->limit(100)->get(), 'tasks' => Gate::allows('viewAny', Task::class) ? Task::query()->where('customer_id', $customer->id)->with('account')->orderByDesc('id')->limit(100)->get() : collect(), 'records' => CommunicationPreference::query()->where('customer_id', $customer->id)->with(['recorder', 'reviewer', 'account', 'contact'])->orderByDesc('id')->paginate(15), 'states' => config('communications.preference_states'), 'bases' => config('communications.basis_codes')]);
    }
}
