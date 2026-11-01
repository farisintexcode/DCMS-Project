<?php

namespace App\Actions\Communications;

use App\Models\Communication;
use App\Models\CommunicationAttempt;
use App\Models\CommunicationBatch;
use App\Models\CommunicationEvent;
use App\Models\OutboxEvent;
use App\Models\User;
use App\Services\AuditWriter;
use App\Services\Communications\EmailCapacity;
use App\Services\Communications\EmailTransport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** M14/UI67–68: exact confirmation, durable attempts, rolling reservations and safe ambiguity. */
class DeliverEmail
{
    public function __construct(private PreviewRecipients $previews, private EmailCapacity $capacity, private EmailTransport $transport, private AuditWriter $audit) {}

    public function confirm(User $actor, CommunicationBatch $target, int $version, string $digest): CommunicationBatch
    {
        return DB::transaction(function () use ($actor, $target, $version, $digest): CommunicationBatch {
            $this->capacity->lock();
            $batch = CommunicationBatch::query()->lockForUpdate()->findOrFail($target->id);
            $actor = $actor->fresh();
            Gate::forUser($actor)->authorize('confirm', $batch);
            if (! hash_equals($batch->preview_digest, $digest)) {
                throw ValidationException::withMessages(['confirmation' => 'The reviewed digest does not match this preview.']);
            }
            if ($batch->confirmed_at) {
                abort_unless($batch->confirmed_by === $actor->id, 403);

                return $batch;
            }
            if ($batch->status !== 'previewed' || $batch->lock_version !== $version || ! $this->previews->freshness($actor, $batch)['fresh']) {
                throw ValidationException::withMessages(['confirmation' => 'Preview changed or is stale. Build and review a new preview.']);
            }
            $account = $this->capacity->account();
            if (! $account) {
                throw ValidationException::withMessages(['confirmation' => 'SES account, sender, quota and activation checks are incomplete. Sending is disabled.']);
            }
            $messages = $batch->communications()->where('delivery_status', 'draft')->lockForUpdate()->get();
            if ($messages->isEmpty()) {
                throw ValidationException::withMessages(['confirmation' => 'There are no eligible recipients to confirm.']);
            }
            foreach ($messages as $message) {
                if (! $this->capacity->sandboxAllows($message->recipient_ciphertext)) {
                    throw ValidationException::withMessages(['confirmation' => 'A recipient is outside the approved sandbox allowlist. Sending is blocked.']);
                }
                $message->forceFill(['delivery_status' => 'queued', 'sent_by' => $actor->id, 'integration_account_id' => $account->id, 'next_attempt_at' => now()])->save();
                $this->event($message, 'queued');
                $outbox = new OutboxEvent;
                $outbox->forceFill(['event_key' => (string) Str::uuid7(), 'event_type' => 'email.deliver', 'aggregate_type' => 'communication', 'aggregate_id' => $message->id, 'payload' => [], 'available_at' => now()])->save();
            }
            $batch->forceFill(['status' => 'queued', 'integration_account_id' => $account->id, 'confirmed_by' => $actor->id, 'confirmed_at' => now(), 'lock_version' => $version + 1])->save();
            $this->audit->record($actor, 'communication.batch_confirmed', $batch->public_id, ['status' => 'queued'], module: 'M14', entityType: 'communication_batch');

            return $batch;
        }, 3);
    }

    /** Return seconds until retry, or null when terminal/already claimed. */
    public function process(int $messageId): ?int
    {
        $claim = DB::transaction(function () use ($messageId): array {
            $this->capacity->lock();
            $message = Communication::query()->with(['batch', 'batch.template', 'batch.requester'])->findOrFail($messageId);
            $batch = CommunicationBatch::query()->lockForUpdate()->findOrFail($message->batch_id);
            $message->refresh();
            if ($message->delivery_status !== 'queued' || $batch->status === 'cancelled') {
                return [];
            }
            if (CommunicationAttempt::query()->where('communication_id', $message->id)->whereIn('state', ['pending', 'unknown', 'accepted'])->exists()) {
                return [];
            }
            if ($message->next_attempt_at?->isFuture()) {
                return ['wait' => max(1, (int) ceil(now()->diffInSeconds($message->next_attempt_at)))];
            }
            $actor = User::query()->find($batch->confirmed_by);
            if (! $actor || ! Gate::forUser($actor)->allows('confirm', $batch) || ! $this->previews->recipientIsCurrent($actor, $message) || ! $this->capacity->sandboxAllows($message->recipient_ciphertext)) {
                $message->forceFill(['delivery_status' => 'suppressed', 'failure_code' => 'current_authority_or_source_changed', 'next_attempt_at' => null])->save();
                $this->event($message, 'suppressed');
                $this->refreshBatch($batch);

                return [];
            }
            $account = $this->capacity->account();
            if (! $account || $account->id !== $batch->integration_account_id) {
                return $this->pause($batch, $message, 'provider_unavailable', 60);
            }
            $remaining = $this->capacity->remaining($account);
            if (min($remaining['app_remaining'], $remaining['provider_remaining'], $remaining['rate_remaining']) < 1) {
                return $this->pause($batch, $message, 'quota_or_rate_limit', $remaining['rate_remaining'] < 1 ? 1 : 60);
            }
            $number = CommunicationAttempt::query()->where('communication_id', $message->id)->count() + 1;
            if (CommunicationAttempt::query()->where('communication_id', $message->id)->where('error_code', 'transport_retryable_failure')->count() >= config('communications.max_attempts')) {
                $message->forceFill(['delivery_status' => 'failed', 'failure_code' => 'retry_limit_reached', 'next_attempt_at' => null])->save();
                $this->event($message, 'failed');
                $this->refreshBatch($batch);

                return [];
            }
            $attempt = new CommunicationAttempt;
            $attempt->forceFill(['communication_id' => $message->id, 'integration_account_id' => $account->id, 'attempt_number' => $number, 'request_key' => (string) Str::uuid7(), 'started_at' => now(), 'state' => 'pending'])->save();
            $this->capacity->reserve($attempt);
            $this->event($message, 'processing', $attempt);
            if ($batch->status !== 'sending') {
                $batch->forceFill(['status' => 'sending', 'lock_version' => $batch->lock_version + 1])->save();
            }

            return compact('message', 'attempt', 'account');
        }, 3);
        if (isset($claim['wait'])) {
            return $claim['wait'];
        }
        if (! $claim) {
            return null;
        }
        try {
            $result = $this->transport->deliver($claim['account'], $claim['message'], $claim['attempt']);
        } catch (\Throwable) {
            $result = ['state' => 'unknown'];
        }
        $state = $result['state'] ?? 'unknown';
        if (! in_array($state, ['accepted', 'retryable_failure', 'permanent_failure', 'unknown', 'deferred'], true)) {
            $state = 'unknown';
        }
        if (isset($result['reference']) && (! is_string($result['reference']) || strlen($result['reference']) > 200)) {
            $state = 'unknown';
            unset($result['reference']);
        }

        return $this->finish($claim['attempt']->id, $state, $result['reference'] ?? null);
    }

    private function finish(int $attemptId, string $state, ?string $reference = null): ?int
    {
        return DB::transaction(function () use ($attemptId, $state, $reference): ?int {
            $this->capacity->lock();
            $attempt = CommunicationAttempt::query()->lockForUpdate()->findOrFail($attemptId);
            if ($attempt->state !== 'pending') {
                return null;
            }
            $message = Communication::query()->lockForUpdate()->findOrFail($attempt->communication_id);
            $batch = CommunicationBatch::query()->lockForUpdate()->findOrFail($message->batch_id);
            if ($message->last_receipt_event_id !== null) {
                $attempt->forceFill(['state' => $state === 'accepted' ? 'accepted' : 'unknown', 'finished_at' => now(), 'provider_reference' => $reference, 'error_code' => $state === 'accepted' ? null : 'transport_response_after_receipt'])->save();
                $this->event($message, 'transport_response_after_receipt', $attempt);

                return null;
            }
            $failures = CommunicationAttempt::query()->where('communication_id', $message->id)->where('error_code', 'transport_retryable_failure')->count();
            $retry = $state === 'deferred' || ($state === 'retryable_failure' && $failures + 1 < config('communications.max_attempts'));
            $delay = $state === 'deferred' ? 60 : config('communications.retry_delays')[min($failures, count(config('communications.retry_delays')) - 1)];
            $attempt->forceFill(['state' => $state === 'deferred' ? 'retryable_failure' : $state, 'finished_at' => now(), 'provider_reference' => $reference, 'error_code' => $state === 'accepted' ? null : 'transport_'.$state, 'retry_after' => $retry ? now()->addSeconds($delay) : null])->save();
            $this->capacity->settle($attempt, $state === 'accepted' ? 'consumed' : ($state === 'unknown' ? 'unknown' : 'released'));
            $message->forceFill(['delivery_status' => $state === 'accepted' ? 'accepted' : ($state === 'unknown' ? 'unknown' : ($retry ? 'queued' : 'failed')), 'provider_message_id' => $state === 'accepted' ? $reference : null, 'sent_at' => $state === 'accepted' ? now() : null, 'failure_code' => $state === 'accepted' ? null : 'transport_'.$state, 'next_attempt_at' => $retry ? now()->addSeconds($delay) : null])->save();
            $this->event($message, $state, $attempt);
            $this->refreshBatch($batch);
            if ($state === 'deferred' && $batch->status !== 'cancelled' && $batch->status !== 'paused') {
                $batch->forceFill(['status' => 'paused', 'lock_version' => $batch->lock_version + 1])->save();
            }

            return $retry ? $delay : null;
        }, 3);
    }

    private function pause(CommunicationBatch $batch, Communication $message, string $reason, int $wait): array
    {
        $message->forceFill(['next_attempt_at' => now()->addSeconds($wait), 'failure_code' => $reason])->save();
        if ($batch->status !== 'paused') {
            $batch->forceFill(['status' => 'paused', 'lock_version' => $batch->lock_version + 1])->save();
        }

        return ['wait' => $wait];
    }

    public function recover(): int
    {
        $count = 0;
        foreach (CommunicationAttempt::query()->where('state', 'pending')->where('started_at', '<=', now()->subSeconds(config('communications.attempt_timeout')))->orderBy('id')->limit(100)->pluck('id') as $id) {
            $this->finish($id, 'unknown');
            $count++;
        }

        return $count;
    }

    public function cancel(User $actor, CommunicationBatch $target, int $version): void
    {
        DB::transaction(function () use ($actor, $target, $version): void {
            $this->capacity->lock();
            $batch = CommunicationBatch::query()->lockForUpdate()->findOrFail($target->id);
            Gate::forUser($actor->fresh())->authorize('cancel', $batch);
            if ($batch->lock_version !== $version) {
                throw ValidationException::withMessages(['revision' => 'Batch changed. Reload before cancelling.']);
            }
            foreach ($batch->communications()->where('delivery_status', 'queued')->whereDoesntHave('attempts', fn ($q) => $q->whereIn('state', ['pending', 'unknown', 'accepted']))->get() as $message) {
                $message->forceFill(['delivery_status' => 'cancelled', 'next_attempt_at' => null])->save();
                $this->event($message, 'cancelled');
            }
            $batch->forceFill(['status' => 'cancelled', 'lock_version' => $version + 1])->save();
            $this->audit->record($actor, 'communication.batch_cancelled', $batch->public_id, ['status' => 'cancelled'], module: 'M14', entityType: 'communication_batch');
        }, 3);
    }

    public function resume(User $actor, CommunicationBatch $target, int $version): void
    {
        DB::transaction(function () use ($actor, $target, $version): void {
            $this->capacity->lock();
            $batch = CommunicationBatch::query()->lockForUpdate()->findOrFail($target->id);
            Gate::forUser($actor->fresh())->authorize('confirm', $batch);
            if ($batch->status !== 'paused' || $batch->lock_version !== $version) {
                throw ValidationException::withMessages(['revision' => 'Only the current paused batch may resume.']);
            }
            foreach ($batch->communications()->where('delivery_status', 'queued')->whereDoesntHave('attempts', fn ($q) => $q->whereIn('state', ['pending', 'unknown', 'accepted']))->get() as $message) {
                $message->forceFill(['next_attempt_at' => now()])->save();
            }
            $batch->forceFill(['status' => 'queued', 'lock_version' => $version + 1])->save();
            $this->audit->record($actor, 'communication.batch_resumed', $batch->public_id, ['status' => 'queued'], module: 'M14', entityType: 'communication_batch');
        }, 3);
    }

    private function refreshBatch(CommunicationBatch $batch): void
    {
        if ($batch->status === 'cancelled') {
            return;
        }
        $states = $batch->communications()->pluck('delivery_status');
        $status = $states->contains('queued') ? 'sending' : ($states->contains(fn ($s) => in_array($s, ['failed', 'unknown', 'suppressed'], true)) ? 'completed_with_errors' : 'completed');
        if ($status !== $batch->status) {
            $batch->forceFill(['status' => $status, 'lock_version' => $batch->lock_version + 1])->save();
        }
    }

    private function event(Communication $message, string $code, ?CommunicationAttempt $attempt = null): void
    {
        $event = new CommunicationEvent;
        $event->forceFill(['communication_id' => $message->id, 'attempt_id' => $attempt?->id, 'event_code' => $code, 'occurred_at' => now(), 'received_at' => now(), 'details' => []])->save();
    }
}
