<?php

namespace Database\Seeders;

use App\Models\ReferenceValue;
use Illuminate\Database\Seeder;

class CallReferenceSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        ReferenceValue::query()->firstOrCreate(['category' => 'activity_type', 'code' => 'call'], ['label' => 'Manual outgoing call']);
        foreach (['answered' => 'Answered', 'no_answer' => 'No Answer', 'busy' => 'Busy', 'invalid_number' => 'Invalid Number', 'call_back_requested' => 'Call Back Requested', 'promise_to_pay' => 'Promise to Pay', 'disputed' => 'Disputed', 'other' => 'Other'] as $code => $label) {
            $row = ReferenceValue::query()->firstOrCreate(['category' => 'activity_outcome', 'code' => $code], ['label' => $label, 'metadata' => ['activity_types' => ['call']]]);
            if (! array_key_exists('activity_types', $row->metadata ?? [])) {
                $row->metadata = [...($row->metadata ?? []), 'activity_types' => $code === 'promise_to_pay' ? ['visit', 'call'] : ['call']];
                $row->save();
            }
        }
    }
}
