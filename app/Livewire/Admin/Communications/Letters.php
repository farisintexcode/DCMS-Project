<?php

namespace App\Livewire\Admin\Communications;

use App\Actions\Communications\ManageLetters;
use App\Models\CommunicationTemplate;
use App\Models\Task;
use App\Services\Communications\LetterAccess;
use App\Services\Communications\TemplateContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\MultipleRecordsFoundException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

/** M14/UI64–67: scoped letters, exact saved preview, queued generation and history. */
#[Layout('layouts.admin')]
class Letters extends Component
{
    use WithPagination;

    public string $customerReference = '';

    public string $customerId = '';

    public string $accountId = '';

    public string $taskId = '';

    public string $templateId = '';

    #[Locked]
    public string $requestKey;

    #[Locked]
    public ?int $previewId = null;

    #[Locked]
    public string $digest = '';

    public function mount(TemplateContext $context, LetterAccess $access): void
    {
        $access->authorize(auth()->user());
        $this->requestKey = (string) Str::uuid7();
        $customer = request()->query('customer');
        if (is_string($customer) && Str::isUuid($customer)) {
            $this->customerId = $context->customer(auth()->user(), $customer)->public_id;
        }
        $template = request()->query('template');
        if (is_string($template) && Str::isUuid($template)) {
            $this->templateId = $template;
        }
        $task = request()->query('task');
        if ($this->customerId !== '' && is_string($task) && Str::isUuid($task)) {
            $context->values(auth()->user(), $this->customerId, taskId: $task);
            $this->taskId = $task;
        }
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['customerId', 'accountId', 'taskId', 'templateId'], true)) {
            $this->resetPreview();
        }
    }

    public function resetPreview(): void
    {
        $this->previewId = null;
        $this->digest = '';
        $this->requestKey = (string) Str::uuid7();
        $this->resetValidation();
    }

    public function selectCustomer(TemplateContext $context): void
    {
        $this->resetPreview();
        $this->customerId = $this->accountId = $this->taskId = '';
        try {
            $this->customerId = $context->find(auth()->user(), $this->customerReference)->public_id;
        } catch (ModelNotFoundException|MultipleRecordsFoundException) {
            throw ValidationException::withMessages(['customerReference' => 'No unique accessible customer matches. Open letters from the customer workspace if references are shared.']);
        }
    }

    public function preview(ManageLetters $letters): void
    {
        $letter = $letters->preview(auth()->user(), $this->templateId, ['customer' => $this->customerId, 'account' => $this->accountId, 'task' => $this->taskId], $this->requestKey);
        $this->previewId = $letter->id;
        $this->digest = $letters->digest($letter);
    }

    public function confirm(ManageLetters $letters): void
    {
        abort_unless($this->previewId, 404);
        $letters->confirm(auth()->user(), $this->previewId, $this->digest);
        session()->flash('status', 'Letter queued for private PDF generation.');
    }

    public function viewLetter(string $publicId, LetterAccess $access, ManageLetters $letters): void
    {
        $letter = $access->query(auth()->user())->where('public_id', $publicId)->firstOrFail();
        $letter = $access->letter(auth()->user(), $letter->id);
        $this->previewId = $letter->id;
        $this->digest = $letters->digest($letter);
    }

    public function render(TemplateContext $context, LetterAccess $access): View
    {
        $actor = auth()->user();
        $access->authorize($actor);
        $customer = $this->customerId !== '' ? $context->customer($actor, $this->customerId) : null;
        $preview = $this->previewId ? $access->letter($actor, $this->previewId) : null;
        $history = $access->query($actor)->when($customer, fn ($q) => $q->where('customer_id', $customer->id))->with(['customer', 'template'])->orderByDesc('id')->paginate(10);
        $download = $preview?->delivery_status === 'generated' ? URL::temporarySignedRoute('admin.communications.letters.download', now()->addMinutes(5), ['letter' => $preview->public_id, 'user' => $actor->id]) : null;

        return view('livewire.admin.communications.letters', ['customer' => $customer, 'preview' => $preview, 'download' => $download, 'history' => $history, 'canGenerate' => $actor->can('communications.compose') && $actor->can('communications.generate_letter'),
            'templates' => CommunicationTemplate::query()->where('channel', 'email')->where('status', 'approved')->orderBy('email_slot')->get(),
            'accounts' => $customer ? $context->accounts($customer)->limit(100)->get() : collect(),
            'tasks' => $customer && Gate::allows('viewAny', Task::class) ? Task::query()->where('customer_id', $customer->id)->with('account')->orderByDesc('id')->limit(100)->get() : collect()]);
    }
}
