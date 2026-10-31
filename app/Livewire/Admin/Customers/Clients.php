<?php

namespace App\Livewire\Admin\Customers;

use App\Actions\Customers\SaveClient;
use App\Models\Client;
use App\Services\ClientAccess;
use App\Services\Customers\ClientWorkbook;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** M12 client onboarding, manually or from an Excel workbook. Customers, categories and assignments all belong to a client created here. */
#[Layout('layouts.admin')]
class Clients extends Component
{
    use WithFileUploads;

    public string $mode = 'manual';

    public array $data = ['code' => '', 'name' => '', 'category' => ''];

    /** @var TemporaryUploadedFile|null */
    public $workbook = null;

    /** @var array<int, array{code: string, name: string, category: string}> */
    #[Locked]
    public array $uploadRows = [];

    /** @var array<int, string> */
    #[Locked]
    public array $uploadErrors = [];

    public function save(SaveClient $save): void
    {
        $client = $save->handle(Auth::user(), $this->data);
        $this->reset('data');
        $this->resetValidation();
        session()->flash('status', "Client {$client->name} created. You can now create its assignments and upload its tasks.");
    }

    public function updatedWorkbook(): void
    {
        $this->reset('uploadRows', 'uploadErrors');
    }

    public function checkWorkbook(ClientWorkbook $reader, SaveClient $save): void
    {
        abort_unless(Auth::user()->isActive() && Auth::user()->can('scopes.manage'), 403);
        $this->reset('uploadRows', 'uploadErrors');
        $this->validate(['workbook' => ['required', 'file', 'extensions:xlsx,xls', 'max:'.config('imports.max_kilobytes')]]);
        try {
            $rows = $reader->rows($this->workbook->getRealPath());
        } catch (RuntimeException $error) {
            $this->addError('workbook', $this->readFailure($error->getMessage()));

            return;
        } catch (\Throwable) {
            $this->addError('workbook', 'This file could not be read as an Excel workbook. Save it as .xlsx and try again.');

            return;
        }
        if ($rows === []) {
            $this->addError('workbook', 'The first sheet has headers but no client rows.');

            return;
        }
        $this->uploadRows = $rows;
        $this->uploadErrors = $save->rowErrors($rows);
    }

    public function importWorkbook(SaveClient $save): void
    {
        $clients = $save->handleMany(Auth::user(), $this->uploadRows);
        $this->reset('workbook', 'uploadRows', 'uploadErrors');
        session()->flash('status', "{$clients->count()} clients created from the workbook. You can now create their assignments and upload their tasks.");
    }

    public function downloadTemplate(ClientWorkbook $workbook): StreamedResponse
    {
        abort_unless(Auth::user()->isActive() && Auth::user()->can('scopes.manage'), 403);
        $contents = $workbook->template();

        return response()->streamDownload(function () use ($contents): void {
            echo $contents;
        }, 'client-upload-template.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    public function render(ClientAccess $access): View
    {
        abort_unless(Auth::user()->isActive() && Auth::user()->can('scopes.manage'), 403);

        return view('livewire.admin.customers.clients', [
            'clients' => $access->clients(Auth::user())->withCount(['assignments'])->orderBy('name')->get(),
            'categories' => Client::query()->whereNotNull('category')->distinct()->orderBy('category')->pluck('category'),
        ]);
    }

    private function readFailure(string $code): string
    {
        return match ($code) {
            'scanner_unavailable', 'scanner_error', 'scanner_write_failed', 'scan_read_failed' => 'The malware scanner is unavailable, so the file was not opened. Start the ClamAV service and try again.',
            'malware_detected' => 'The malware scanner rejected this file. It was not opened.',
            'missing_headers' => 'Row 1 of the first sheet must contain "Client code" and "Client name" columns. Download the template to see the layout.',
            'formulas_not_allowed' => 'Formulas are not accepted. Paste the values only and upload again.',
            'macros_not_allowed', 'unsafe_archive', 'external_links_not_allowed', 'embedded_content_not_allowed', 'unsafe_legacy_workbook' => 'This workbook contains macros, links or embedded content, which are not accepted.',
            'row_limit', 'workbook_limit', 'sheet_limit' => 'This workbook is larger than allowed: at most '.number_format(config('imports.max_rows')).' rows.',
            default => 'This file could not be read as an Excel workbook. Save it as .xlsx and try again.',
        };
    }
}
