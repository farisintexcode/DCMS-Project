<?php

namespace App\Actions\Calls;

use App\Models\Client;
use App\Models\Debtor;
use App\Models\FollowUp;
use App\Models\User;
use App\Services\AuditWriter;
use App\Services\Calls\CallAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** M11: terminal decision over own call reminder; original context never changes. */
class ResolveFollowUp
{
    public function __construct(private CallAccess $access, private AuditWriter $audit) {}

    public function handle(User $actor, FollowUp $target, int $version, string $decision, string $remarks): FollowUp
    {
        Validator::make(compact('decision', 'remarks'), ['decision' => ['required', 'in:done,cancelled'], 'remarks' => ['required', 'string', 'max:2000']])->validate();
        if (trim($remarks) === '') {
            throw ValidationException::withMessages(['remarks' => 'Resolution remarks are required.']);
        }

        return DB::transaction(function () use ($actor, $target, $version, $decision, $remarks): FollowUp {
            $customer = Debtor::query()->findOrFail($target->customer_id);
            Client::query()->lockForUpdate()->findOrFail($customer->client_id);
            $actor = $actor->fresh();
            $this->access->customer($actor, $customer->id);
            Debtor::query()->whereKey($customer->id)->lockForUpdate()->firstOrFail();
            $follow = FollowUp::query()->with('activity')->lockForUpdate()->findOrFail($target->id);
            abort_unless($follow->assigned_to === $actor->id && $follow->activity?->activity_type_code === 'call', 403);
            if ($follow->status !== 'pending') {
                if ($follow->status === $decision && $follow->resolved_by === $actor->id) {
                    return $follow;
                } throw ValidationException::withMessages(['followUp' => 'This reminder already has a recorded decision.']);
            }
            if ($follow->lock_version !== $version) {
                throw ValidationException::withMessages(['followUp' => 'This reminder changed. Refresh before resolving it.']);
            }
            $instant = now();
            $follow->forceFill(['status' => $decision, 'completed_at' => $decision === 'done' ? $instant : null, 'resolved_by' => $actor->id, 'resolved_at' => $instant, 'resolution_ciphertext' => trim($remarks), 'lock_version' => $version + 1])->save();
            $this->audit->record($actor, 'call.follow_up_'.$decision, $follow->public_id, ['status' => $decision], module: 'M11', entityType: 'follow_up');

            return $follow;
        }, 3);
    }
}
