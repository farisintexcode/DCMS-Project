<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerCategoryAssignment extends Model
{
    use HasFactory, HasPublicId;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return ['assigned_at' => 'immutable_datetime', 'revoked_at' => 'immutable_datetime'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Debtor::class, 'customer_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(CustomerCategory::class, 'category_id');
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }
}
