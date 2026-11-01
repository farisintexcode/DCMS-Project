<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** M05 / Database.md: canonical account, task and allocation entities. */
class DebtAccount extends Model
{
    use HasFactory,HasPublicId;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $hidden = ['source_snapshot_ciphertext', 'address_lines_ciphertext', 'special_instruction_ciphertext', 'task_remarks_ciphertext', 'admin_remarks_ciphertext'];

    protected function casts(): array
    {
        return ['status' => 'string'];
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function parties(): HasMany
    {
        return $this->hasMany(AccountParty::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function revision(): string
    {
        return hash('sha256', json_encode([$this->getRawOriginal(), $this->property?->getRawOriginal(), $this->parties()->selectRaw('max(id) as latest,max(updated_at) as changed')->toBase()->first()], JSON_THROW_ON_ERROR));
    }
}
