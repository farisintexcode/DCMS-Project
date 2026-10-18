<?php

namespace Database\Seeders;

use App\Models\ImportField;
use App\Models\ImportTemplate;
use App\Models\ReferenceValue;
use Illuminate\Database\Seeder;

/** M04: initial contracts reuse the currently implemented M13 and M03 writers. */
class ImportRegistrySeeder extends Seeder
{
    public function run(): void
    {
        foreach (['workbook' => 'Workbook', 'report' => 'Report'] as $code => $label) {
            ReferenceValue::query()->firstOrCreate(['category' => 'document_type', 'code' => $code], ['label' => $label]);
        }
        $contracts = [
            'customer' => ['customer_reference', 'party_type', 'name', 'status', 'identity'],
            'assignment_batch' => ['external_reference', 'name', 'type_code', 'start_date', 'due_date', 'description', 'dca_code', 'dca_name', 'la_name', 'state_code'],
        ];
        foreach ($contracts as $kind => $fields) {
            $required = $kind === 'customer' ? ['customer_reference', 'party_type', 'name', 'status'] : ['external_reference', 'name', 'type_code', 'start_date', 'due_date'];
            $ids = [];
            foreach ($fields as $field) {
                $record = ImportField::query()->firstOrCreate(['module' => $kind, 'canonical_field' => $field, 'version' => 1], ['data_type' => str_ends_with($field, '_date') ? 'date' : 'string', 'validation_schema' => ['required' => in_array($field, $required, true), 'transforms' => ['exact', 'trim']]]);
                $ids[] = $record->id;
            }
            ImportTemplate::query()->firstOrCreate(['code' => $kind.'-v1', 'version' => 1], ['kind' => $kind, 'is_active' => true, 'schema_definition' => ['field_ids' => $ids, 'required' => $required, 'identity' => $required[0], 'update' => 'mapped-fields-only', 'transforms' => ['exact', 'trim']]]);
        }
    }
}
