<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExportJob extends Model
{
    use HasPublicId;

    protected $hidden = ['snapshot_ciphertext', 'snapshot_digest'];

    protected function casts(): array
    {
        return ['criteria' => 'array', 'snapshot_ciphertext' => 'encrypted:array', 'snapshot_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime', 'row_count' => 'integer'];
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function artifact(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'artifact_document_id');
    }
}
