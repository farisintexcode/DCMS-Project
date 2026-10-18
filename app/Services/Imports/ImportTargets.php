<?php

namespace App\Services\Imports;

use App\Actions\Assignments\SaveAssignmentBatch;
use App\Actions\Customers\SaveCustomer;
use App\Models\AssignmentBatch;
use App\Models\Debtor;
use App\Models\ImportBatch;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** M04 → M13/M03: exact portfolio identity, existing writers, optimistic concurrency. */
class ImportTargets
{
    public function prepare(User $actor, ImportBatch $batch, array $values): array
    {
        $column = $batch->kind === 'customer' ? 'customer_reference' : 'external_reference';
        $reference = trim($values[$column] ?? '');
        if ($reference === '') {
            throw ValidationException::withMessages([$column => 'required']);
        }
        $query = $batch->kind === 'customer' ? Debtor::query() : AssignmentBatch::query();
        $target = $query->where('client_id', $batch->client_id)->where($column, $reference)->first();
        $revision = $target ? ($target instanceof Debtor ? $target->revision() : $target->lock_version) : null;
        $payload = ['input' => $values + ['client_id' => $batch->client_id], 'target_id' => $target?->id, 'revision' => $revision, 'operation' => $target ? 'update' : 'create', 'changes' => []];
        foreach ($values as $field => $value) {
            $before = $target?->getAttribute($field === 'name' && $batch->kind === 'customer' ? 'display_name_ciphertext' : $field);
            $payload['changes'][$field] = ['before' => $this->mask($before), 'after' => $this->mask($value)];
        }
        // Exercise the real writer under a savepoint; no customer, batch or audit survives preview.
        $level = DB::transactionLevel();
        DB::beginTransaction();
        try {
            $this->write($actor, $batch, $payload);
        } finally {
            DB::rollBack($level);
        }

        return $payload;
    }

    public function write(User $actor, ImportBatch $batch, array $payload): Debtor|AssignmentBatch
    {
        $target = null;
        $reference = $batch->kind === 'customer' ? 'customer_reference' : 'external_reference';
        $query = $batch->kind === 'customer' ? Debtor::query() : AssignmentBatch::query();
        $current = $query->where('client_id', $batch->client_id)->where($reference, trim($payload['input'][$reference]))->first();
        if ($payload['target_id'] === null && $current !== null) {
            throw ValidationException::withMessages(['revision' => 'target_changed']);
        }
        if ($payload['target_id'] !== null) {
            if (! $current || $current->id !== $payload['target_id']) {
                throw ValidationException::withMessages(['revision' => 'target_changed']);
            }
            $target = $current;
        }
        if ($batch->kind === 'customer') {
            return app(SaveCustomer::class)->handle($actor, $payload['input'], $target, $payload['revision']);
        }

        return app(SaveAssignmentBatch::class)->handle($actor, $payload['input'], $target, $payload['revision']);
    }

    public function mask(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }
        $text = is_scalar($value) ? (string) $value : '';

        return mb_strlen($text) > 4 ? mb_substr($text, 0, 2).'•••'.mb_substr($text, -2) : '•••';
    }
}
