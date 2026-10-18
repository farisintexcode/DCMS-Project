<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Client extends Model
{
    use HasFactory, HasPublicId;

    protected $fillable = ['code', 'name', 'default_currency'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function userScopes(): HasMany
    {
        return $this->hasMany(UserClientScope::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(AssignmentBatch::class);
    }
}
