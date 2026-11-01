<?php

namespace App\Livewire\Admin\Imports;

use App\Actions\Imports\ImportWorkflow;
use App\Models\ColumnAlias;
use App\Models\ImportBatch;
use App\Models\ImportField;
use App\Models\ImportTemplate;
use App\Services\AuditWriter;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.admin')]
class Registry extends Component
{
    public string $kind = 'customer';

    public string $field = '';

    public string $alias = '';

    public function addAlias(AuditWriter $audit): void
    {
        Gate::authorize('viewAny', ImportBatch::class);
        abort_unless(Auth::user()->can('templates.manage') && Auth::user()->can('imports.map'), 403);
        $this->validate(['kind' => ['required', 'in:customer,assignment_batch'], 'field' => ['required', 'integer'], 'alias' => ['required', 'string', 'max:160']]);
        DB::transaction(function () use ($audit): void {
            $field = ImportField::query()->where('module', $this->kind)->where('is_active', true)->findOrFail($this->field);
            $normalized = ImportWorkflow::normalize($this->alias);
            Validator::make(['normalized_alias' => $normalized], ['normalized_alias' => ['required', 'string', 'max:160', Rule::unique('column_aliases')->where('module', $this->kind)->where('is_active', true)]])->validate();
            $alias = new ColumnAlias;
            $alias->forceFill(['module' => $this->kind, 'field_id' => $field->id, 'alias' => trim($this->alias), 'normalized_alias' => $normalized])->save();
            $audit->record(Auth::user(), 'import_alias_created', $alias->public_id, ['status' => 'active'], module: 'M04', entityType: 'column_alias');
        });
        $this->alias = '';
        session()->flash('success', 'Alias published. Existing reviewed mappings remain pinned.');
    }

    public function deactivateAlias(string $id, AuditWriter $audit): void
    {
        Gate::authorize('viewAny', ImportBatch::class);
        abort_unless(Auth::user()->can('templates.manage') && Auth::user()->can('imports.map'), 403);
        DB::transaction(function () use ($id, $audit): void {
            $alias = ColumnAlias::query()->where('public_id', $id)->lockForUpdate()->firstOrFail();
            $alias->is_active = false;
            $alias->save();
            $audit->record(Auth::user(), 'import_alias_deactivated', $alias->public_id, ['status' => 'inactive'], module: 'M04', entityType: 'column_alias');
        });
    }

    public function newTemplateVersion(string $id, AuditWriter $audit): void
    {
        Gate::authorize('viewAny', ImportBatch::class);
        abort_unless(Auth::user()->can('templates.manage'), 403);
        DB::transaction(function () use ($id, $audit): void {
            $source = ImportTemplate::query()->where('public_id', $id)->lockForUpdate()->firstOrFail();
            $version = (int) ImportTemplate::query()->where('code', $source->code)->max('version') + 1;
            $template = $source->replicate(['public_id']);
            $template->version = $version;
            $template->is_active = false;
            $template->created_by = Auth::id();
            $template->save();
            $audit->record(Auth::user(), 'import_template_version_created', $template->public_id, ['status' => 'draft'], module: 'M04', entityType: 'import_template');
        });
        session()->flash('success', 'A compatible draft version was created. A separately authorized approver can publish it.');
    }

    public function toggleTemplate(string $id, AuditWriter $audit): void
    {
        Gate::authorize('viewAny', ImportBatch::class);
        abort_unless(Auth::user()->can('templates.approve'), 403);
        DB::transaction(function () use ($id, $audit): void {
            $template = ImportTemplate::query()->where('public_id', $id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($template->kind, ['customer', 'assignment_batch'], true), 422);
            $template->is_active = ! $template->is_active;
            $template->save();
            $audit->record(Auth::user(), 'import_template_publication_changed', $template->public_id, ['status' => $template->is_active ? 'active' : 'inactive'], module: 'M04', entityType: 'import_template');
        });
    }

    public function render(): View
    {
        Gate::authorize('viewAny', ImportBatch::class);

        return view('livewire.admin.imports.registry', ['fields' => ImportField::query()->where('module', $this->kind)->where('is_active', true)->get(), 'aliases' => ColumnAlias::query()->where('module', $this->kind)->orderByDesc('id')->limit(100)->get(), 'templates' => ImportTemplate::query()->orderBy('code')->orderByDesc('version')->get()]);
    }
}
