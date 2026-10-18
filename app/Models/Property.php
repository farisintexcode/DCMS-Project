<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** M05 / Database.md: canonical account, task and allocation entities. */
class Property extends Model
{
    use HasFactory,HasPublicId;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $hidden = ['source_snapshot_ciphertext', 'address_lines_ciphertext', 'special_instruction_ciphertext', 'task_remarks_ciphertext', 'admin_remarks_ciphertext'];

    protected function casts(): array
    {
        return ['address_lines_ciphertext' => 'encrypted'];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(DebtAccount::class);
    }
}
