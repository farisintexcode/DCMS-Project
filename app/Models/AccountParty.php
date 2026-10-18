<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** M05 / Database.md: canonical account, task and allocation entities. */
class AccountParty extends Model
{
    use HasFactory,HasPublicId;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $hidden = ['source_snapshot_ciphertext', 'address_lines_ciphertext', 'special_instruction_ciphertext', 'task_remarks_ciphertext', 'admin_remarks_ciphertext'];

    protected function casts(): array
    {
        return ['valid_from' => 'immutable_datetime', 'valid_to' => 'immutable_datetime'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(DebtAccount::class, 'debt_account_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Debtor::class, 'debtor_id');
    }
}
