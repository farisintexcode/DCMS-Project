<?php

namespace Database\Factories;

use App\Models\ImportBatch;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class DocumentFactory extends Factory
{
    public function definition(): array
    {
        return ['owner_type' => 'import', 'owner_id' => ImportBatch::factory(), 'uploaded_by' => User::factory(), 'disk' => 's3', 'bucket' => 'synthetic-imports', 'object_key' => 'imports/quarantine/'.fake()->uuid().'.encrypted', 'original_filename' => 'synthetic.xlsx', 'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'size_bytes' => 100, 'sha256' => hash('sha256', 'synthetic'), 'document_type_code' => 'workbook', 'scan_status' => 'pending'];
    }
}
