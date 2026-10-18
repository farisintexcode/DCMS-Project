<?php

namespace App\Livewire\Admin\Communications\Templates;

use App\Actions\Communications\ManageTemplates;
use App\Models\CommunicationTemplate;
use App\Models\Task;
use App\Services\AuditWriter;
use App\Services\Communications\TemplateContext;
use App\Services\Communications\TemplateRenderer;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\MultipleRecordsFoundException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.admin')]
class Editor extends Component
{
    use WithPagination;

    #[Locked]
    public int $templateId;

    #[Locked]
    public int $revision;

    public array $form = [];

    public string $customerReference = '';

    public string $customerId = '';

    public string $accountId = '';

    public string $taskId = '';

    public string $previewMode = '';

    public string $rejectionReason = '';

    public function mount(CommunicationTemplate $template, TemplateContext $context): void
    {
        Gate::authorize('view', $template);
        $this->templateId = $template->id;
        $this->fillForm($template);
        $customer = request()->query('customer');
        if (is_string($customer) && Str::isUuid($customer)) {
            $this->customerId = $context->customer(auth()->user(), $customer)->public_id;
        }
        $task = request()->query('task');
        if ($this->customerId !== '' && is_string($task) && Str::isUuid($task)) {
            $context->values(auth()->user(), $this->customerId, taskId: $task);
            $this->taskId = $task;
        }
    }

    private function template(): CommunicationTemplate
    {
        $template = CommunicationTemplate::query()->with(['creator', 'approver', 'submitter', 'rejecter'])->findOrFail($this->templateId);
        Gate::authorize('view', $template);

        return $template;
    }

    private function fillForm(CommunicationTemplate $template): void
    {
        $this->revision = $template->lock_version;
        $this->form = ['name' => $template->name, 'subject' => $template->subject_template ?? '', 'body' => $template->body_template, 'required' => collect($template->allowed_placeholders)->filter(fn ($v) => $v['required'] ?? false)->keys()->all()];
        $this->previewMode = '';
    }

    public function updated(string $property): void
    {
        if (str_starts_with($property, 'form.') || in_array($property, ['customerReference', 'accountId', 'taskId'], true)) {
            $this->previewMode = '';
        }
        if ($property === 'accountId') {
            $this->taskId = '';
        }
        if ($property === 'taskId') {
            $this->accountId = '';
        }
    }

    public function save(ManageTemplates $manage): void
    {
        $template = $manage->save(auth()->user(), $this->template(), $this->revision, $this->form);
        $this->fillForm($template);
        session()->flash('status', 'Draft saved. Preview and approval use this exact saved content.');
    }

    public function reloadSaved(): void
    {
        $this->resetValidation();
        $this->fillForm($this->template());
    }

    public function newVersion(ManageTemplates $manage): void
    {
        $draft = $manage->createVersion(auth()->user(), $this->template());
        $this->redirectRoute('admin.communications.templates.edit', ['template' => $draft->public_id]);
    }

    private function ensureSaved(): void
    {
        $template = $this->template();
        $saved = ['name' => $template->name, 'subject' => $template->subject_template ?? '', 'body' => $template->body_template, 'required' => collect($template->allowed_placeholders)->filter(fn ($value) => $value['required'] ?? false)->keys()->all()];
        $submitted = $this->form;
        if (! is_array($submitted['required'] ?? null)) {
            throw ValidationException::withMessages(['revision' => 'Save or reload your edits before submitting or approving the exact saved version.']);
        }
        sort($saved['required']);
        sort($submitted['required']);
        if ($submitted !== $saved) {
            throw ValidationException::withMessages(['revision' => 'Save or reload your edits before submitting or approving the exact saved version.']);
        }
    }

    public function submitForApproval(ManageTemplates $manage): void
    {
        $this->ensureSaved();
        $this->fillForm($manage->submit(auth()->user(), $this->template(), $this->revision));
        session()->flash('status', 'Template submitted for approval. Existing approved wording remains active.');
    }

    public function approve(ManageTemplates $manage): void
    {
        $this->ensureSaved();
        $this->fillForm($manage->approve(auth()->user(), $this->template(), $this->revision));
        session()->flash('status', 'Saved template version approved. Delivery requires reviewed recipients and verified provider settings.');
    }

    public function reject(ManageTemplates $manage): void
    {
        $this->fillForm($manage->reject(auth()->user(), $this->template(), $this->revision, $this->rejectionReason));
        $this->rejectionReason = '';
        session()->flash('status', 'Template rejected. The reason and reviewed wording are retained; create a new draft to revise it.');
    }

    public function archive(ManageTemplates $manage): void
    {
        $this->fillForm($manage->archive(auth()->user(), $this->template(), $this->revision));
        session()->flash('status', 'Template version archived. Content and approval history are retained.');
    }

    public function insertToken(string $token): void
    {
        Gate::authorize('update', $this->template());
        abort_unless(array_key_exists($token, config('communications.placeholders')), 422);
        if (mb_strlen($this->form['body']) > 19950) {
            throw ValidationException::withMessages(['body' => 'Body is too long for another placeholder.']);
        }
        $this->form['body'] .= ' {{'.$token.'}}';
        $this->previewMode = '';
    }

    public function selectCustomer(TemplateContext $context): void
    {
        $this->customerId = '';
        $this->accountId = '';
        $this->taskId = '';
        $this->previewMode = '';
        try {
            $customer = $context->find(auth()->user(), $this->customerReference);
        } catch (ModelNotFoundException|MultipleRecordsFoundException) {
            throw ValidationException::withMessages(['customerReference' => 'No unique accessible customer matches. Open the template workspace from the customer details to resolve shared references.']);
        }
        $this->customerId = $customer->public_id;
    }

    public function clearCustomer(): void
    {
        $this->customerId = '';
        $this->accountId = '';
        $this->taskId = '';
        $this->customerReference = '';
        $this->previewMode = '';
    }

    private function previewData(TemplateContext $context, TemplateRenderer $renderer, string $mode): array
    {
        $template = $this->template();
        if ($mode === 'sample') {
            $source = ['values' => ['customer_name' => 'Sample Customer', 'account_number' => 'SAMPLE-ACCOUNT', 'company_name' => 'Sample Commissioning Client', 'outstanding_amount' => 'MYR 125.00', 'due_date' => '31 Oct 2026'], 'sources' => []];
        } else {
            $source = $context->values(auth()->user(), $this->customerId, $this->accountId, $this->taskId);
        }

        return [...$renderer->render($template, $source['values']), 'sources' => $source['sources']];
    }

    public function preview(string $mode, TemplateContext $context, TemplateRenderer $renderer, AuditWriter $audit): void
    {
        abort_unless(in_array($mode, ['sample', 'customer'], true), 422);
        $this->previewMode = '';
        $this->previewData($context, $renderer, $mode);
        $this->previewMode = $mode;
        $audit->record(auth()->user(), 'template.previewed', $this->template()->public_id, ['status' => $mode], module: 'M14', entityType: 'communication_template');
    }

    public function render(TemplateContext $context, TemplateRenderer $renderer): View
    {
        $template = $this->template();
        $preview = null;
        $previewError = null;
        if ($this->previewMode !== '') {
            try {
                $preview = $this->previewData($context, $renderer, $this->previewMode);
            } catch (ValidationException $e) {
                $previewError = collect($e->errors())->flatten()->first();
            }
        }
        $customer = $this->customerId !== '' ? $context->customer(auth()->user(), $this->customerId) : null;
        $canCompose = auth()->user()->can('communications.compose') && auth()->user()->can('customers.view');

        return view('livewire.admin.communications.templates.editor', ['template' => $template, 'registry' => config('communications.placeholders'), 'preview' => $preview, 'previewError' => $previewError, 'customer' => $customer, 'canCompose' => $canCompose,
            'accounts' => $customer ? $context->accounts($customer)->limit(100)->get() : collect(),
            'tasks' => $customer && Gate::allows('viewAny', Task::class) ? Task::query()->where('customer_id', $customer->id)->with('account')->orderByDesc('id')->limit(100)->get() : collect(),
            'history' => CommunicationTemplate::query()->where('channel', 'email')->where('email_slot', $template->email_slot)->with(['creator', 'approver', 'submitter', 'rejecter'])->orderByDesc('version')->paginate(10, ['*'], 'versionsPage')]);
    }
}
