<?php

namespace App\Livewire\Admin\Imports;

use App\Actions\Imports\ImportWorkflow;
use App\Models\ImportBatch;
use App\Models\ImportTemplate;
use App\Services\ClientAccess;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

#[Layout('layouts.admin')]
class Index extends Component
{
    use WithFileUploads,WithPagination;

    public array $data = ['client_id' => '', 'kind' => 'customer', 'template_id' => '', 'assignment_batch_id' => null];

    public $file;

    public string $status = '';

    public string $client = '';

    public string $kind = '';

    public string $from = '';

    public string $until = '';

    public function updated(string $property): void
    {
        $this->resetPage();
        if ($property === 'data.kind') {
            $this->data['template_id'] = '';
        }
    }

    public function upload(ImportWorkflow $workflow): void
    {
        $this->validate(['file' => ['required', 'file', 'max:'.config('imports.max_kilobytes')]]);
        try {
            $batch = $workflow->upload(Auth::user(), $this->data, $this->file);
        } catch (ValidationException $error) {
            throw $error;
        } catch (\RuntimeException) {
            $this->addError('file', 'Private storage is unavailable. Check S3 configuration before retrying.');

            return;
        }
        $this->redirectRoute('admin.imports.review', ['import' => $batch], navigate: false);
    }

    public function render(ClientAccess $access): View
    {
        Gate::authorize('viewAny', ImportBatch::class);
        $query = $access->scope(ImportBatch::query(), Auth::user())->with(['client', 'template']);
        if ($this->status !== '') {
            $query->where('status', mb_substr($this->status, 0, 32));
        }
        if ($this->kind !== '') {
            $query->where('kind', mb_substr($this->kind, 0, 32));
        }
        if ($this->client !== '') {
            ctype_digit($this->client) ? $query->where('client_id', $this->client) : $query->whereRaw('false');
        }
        $dates = Validator::make(['from' => $this->from ?: null, 'until' => $this->until ?: null], ['from' => ['nullable', 'date_format:Y-m-d'], 'until' => ['nullable', 'date_format:Y-m-d']]);
        if ($dates->fails()) {
            $query->whereRaw('false');
        } else {
            $query->when($this->from !== '', fn ($q) => $q->where('created_at', '>=', $this->from.' 00:00:00+08'))->when($this->until !== '', fn ($q) => $q->where('created_at', '<', Carbon::parse($this->until, 'Asia/Kuala_Lumpur')->addDay()->utc()));
        }

        return view('livewire.admin.imports.index', ['imports' => $query->latest('id')->paginate(25), 'clients' => $access->clients(Auth::user())->get(), 'templates' => ImportTemplate::query()->where('is_active', true)->where('kind', $this->data['kind'])->get()]);
    }
}
