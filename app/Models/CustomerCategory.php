<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CustomerCategory extends Model
{
    use HasFactory, HasPublicId;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function categoryAssignments(): HasMany
    {
        return $this->hasMany(CustomerCategoryAssignment::class, 'category_id');
    }

    public function revision(): string
    {
        return hash('sha256', json_encode([$this->id, $this->code, $this->name, $this->description, $this->is_active, $this->updated_at?->format('Y-m-d H:i:s.uP')], JSON_THROW_ON_ERROR));
    }
}
