<?php

namespace Database\Factories;

use App\Models\ImportBatch;
use Illuminate\Database\Eloquent\Factories\Factory;

class ImportRowFactory extends Factory
{
    public function definition(): array
    {
        return ['import_batch_id' => ImportBatch::factory(), 'sheet_name' => 'Intake', 'row_number' => 1, 'raw_payload_ciphertext' => ['000001', 'person', 'Synthetic Customer', 'active'], 'state' => 'staged'];
    }
}
