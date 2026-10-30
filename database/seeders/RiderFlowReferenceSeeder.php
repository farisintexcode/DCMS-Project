<?php

namespace Database\Seeders;

use App\Models\ReferenceValue;
use Illuminate\Database\Seeder;

class RiderFlowReferenceSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $sets = [
            'dr_code' => ['DR01' => 'Payment Made After DCA Visit', 'DR02' => 'Promised To Pay', 'DR03' => 'Refuse To Pay', 'DR04' => 'Unable To Pay', 'DR05' => 'Vacant Premise', 'DR06' => 'Vacant Land'],
            'business_nature' => ['NB01' => 'Hardware Shop', 'NB02' => 'Tailor Shop', 'NB03' => 'Laundry / Dry Cleaning', 'NB04' => 'Malay Restaurant', 'NB05' => 'Chinese Restaurant', 'NB06' => 'Indian Restaurant'],
        ];
        foreach ($sets as $category => $codes) {
            foreach ($codes as $code => $label) {
                ReferenceValue::query()->firstOrCreate(['category' => $category, 'code' => $code], ['label' => $label, 'metadata' => ['source' => 'nexora_rider_detailed_flow.md', 'scope' => 'documented examples only']]);
            }
        }
    }
}
