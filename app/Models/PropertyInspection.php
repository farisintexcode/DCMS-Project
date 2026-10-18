<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** M06 / Database.md canonical execution and submission history. */
class PropertyInspection extends Model
{
    use HasFactory, HasPublicId;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $hidden = ['shop_company_ciphertext', 'customer_reason_ciphertext', 'business_other_ciphertext'];

    protected function casts(): array
    {
        return ['observed_at' => 'immutable_datetime', 'source_values' => 'array', 'business_closed' => 'boolean', 'meter_connected' => 'boolean', 'shop_company_ciphertext' => 'encrypted', 'customer_reason_ciphertext' => 'encrypted', 'business_other_ciphertext' => 'encrypted'];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'task_id');
    }

    public function activity(): BelongsTo
    {
        return $this->belongsTo(CollectionActivity::class, 'activity_id');
    }
}
