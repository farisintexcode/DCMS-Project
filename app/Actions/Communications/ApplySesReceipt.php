<?php

namespace App\Actions\Communications;

use App\Models\Client;
use App\Models\Communication;
use App\Models\CommunicationAttempt;
use App\Models\CommunicationBatch;
use App\Models\CommunicationEvent;
use App\Models\CommunicationPreference;
use App\Models\CommunicationQuotaReservation;
use App\Models\DebtAccount;
use App\Models\IntegrationEvent;
use App\Models\OutboxEvent;
use App\Services\AuditWriter;
use App\Services\Communications\EmailCapacity;
use App\Services\Communications\EmailEligibility;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** M14/UI68 authenticated SES reducer. No lookup by guessed customer/address; no resend. */
class ApplySesReceipt
{
    public function __construct(private EmailCapacity $capacity, private EmailEligibility $email, private AuditWriter $audit) {}

    public function apply(int $eventId): bool
    {
        return DB::transaction(function () use ($eventId): bool {
            $this->capacity->lock();
            $event = IntegrationEvent::query()->with('account')->lockForUpdate()->findOrFail($eventId);
            if (in_array($event->state, ['processed', 'failed'], true)) {
                return true;
            }
            $p = $event->payload_ciphertext;
            $type = $p['event_type'] ?? '';
            $map = ['Send' => ['accepted', 'send'], 'Delivery' => ['delivered', 'delivery'], 'Bounce' => ['soft_bounced', 'bounce'], 'Complaint' => ['complained', 'complaint'], 'Reject' => ['failed', 'reject'], 'Rendering Failure' => ['failed', 'failure'], 'DeliveryDelay' => ['soft_bounced', 'deliveryDelay']];
            if (! is_string($type) || ! isset($map[$type])) {
                return $this->complete($event, 'ignored_unsupported_event');
            }
            $mail = $p['mail'] ?? [];
            if (! is_string($mail['messageId'] ?? null) || $mail['messageId'] === '' || strlen($mail['messageId']) > 200 || preg_match('/[\x00-\x20\x7f]/', $mail['messageId']) || ! is_array($mail['destination'] ?? null) || count($mail['destination']) !== 1 || ! is_string($mail['destination'][0] ?? null)) {
                return $this->defer($event, 'malformed_message_context', true);
            }
            $source = $mail['source'] ?? null;
            if (is_string($source) && preg_match('/^(?:Nexora|DebtOps) <([^<>]+)>$/D', $source, $m)) {
                $source = $m[1];
            }
            if (! is_string($source) || $this->email->canonical($source) !== $this->email->canonical($event->account->sender_reference_ciphertext ?? '') || ($mail['sendingAccountId'] ?? null) !== ($event->account->capabilities['aws_account_id'] ?? null) || ! preg_match('/^[0-9]{12}$/D', $mail['sendingAccountId'] ?? '')) {
                return $this->defer($event, 'sender_account_mismatch');
            }
            $tag = $p['attempt_tag'] ?? [];
            if (! is_array($tag) || ! array_is_list($tag) || count($tag) > 1 || (isset($tag[0]) && (! is_string($tag[0]) || ! Str::isUuid($tag[0])))) {
                return $this->defer($event, 'invalid_attempt_tag', true);
            }
            $byReference = Communication::query()->where('integration_account_id', $event->integration_account_id)->where('provider_message_id', $mail['messageId'])->first();
            $attempt = isset($tag[0]) ? CommunicationAttempt::query()->where('integration_account_id', $event->integration_account_id)->where('request_key', $tag[0])->first() : null;
            if ($byReference && $attempt && $attempt->communication_id !== $byReference->id) {
                return $this->defer($event, 'conflicting_attempt_reference');
            }
            if (! $attempt && $byReference) {
                $attempt = CommunicationAttempt::query()->where('communication_id', $byReference->id)->where('integration_account_id', $event->integration_account_id)->where('provider_reference', $mail['messageId'])->first();
            }
            if (! $attempt && $byReference && ! isset($tag[0])) {
                $linked = CommunicationEvent::query()->where('communication_id', $byReference->id)->whereHas('integrationEvent', fn ($q) => $q->where('integration_account_id', $event->integration_account_id)->where('provider_resource_id', $mail['messageId']))->value('attempt_id');
                $attempt = $linked ? CommunicationAttempt::query()->find($linked) : null;
            }
            if (! $attempt) {
                return $this->defer($event, 'unmatched_provider_resource');
            }
            $message = Communication::query()->with(['batch', 'task'])->findOrFail($attempt->communication_id);
            if (($message->provider_message_id !== null && $message->provider_message_id !== $mail['messageId']) || ($attempt->provider_reference !== null && $attempt->provider_reference !== $mail['messageId']) || ! in_array($attempt->state, ['pending', 'accepted', 'unknown'], true)) {
                return $this->defer($event, 'inconsistent_attempt_context');
            }
            if ($this->email->canonical($mail['destination'][0]) !== $message->recipient_ciphertext || $this->email->fingerprint($mail['destination'][0]) !== $message->recipient_fingerprint) {
                return $this->defer($event, 'recipient_context_mismatch');
            }
            [$state,$section] = $map[$type];
            foreach (['recipients', 'bouncedRecipients', 'complainedRecipients'] as $field) {
                if (isset($p[$section][$field])) {
                    if (! is_array($p[$section][$field]) || count($p[$section][$field]) !== 1) {
                        return $this->defer($event, 'receipt_recipient_mismatch');
                    }
                    $recipient = $p[$section][$field][0];
                    $address = is_string($recipient) ? $recipient : ($recipient['emailAddress'] ?? null);
                    if (! is_string($address) || $this->email->canonical($address) !== $message->recipient_ciphertext) {
                        return $this->defer($event, 'receipt_recipient_mismatch');
                    }
                }
            }
            try {
                if (! is_string($mail['timestamp'] ?? null) || ! preg_match('/^\d{4}-\d{2}-\d{2}T/', $mail['timestamp'])) {
                    return $this->defer($event, 'invalid_provider_time', true);
                }
                $accepted = CarbonImmutable::parse($mail['timestamp'])->utc();
                $occurred = CarbonImmutable::parse($p[$section]['timestamp'] ?? $mail['timestamp'])->utc();
            } catch (\Throwable) {
                return $this->defer($event, 'invalid_provider_time', true);
            }
            if ($accepted->greaterThan(now()->addMinutes(5)) || $occurred->greaterThan(now()->addMinutes(5)) || $accepted->lessThan($attempt->started_at->subMinutes(5))) {
                return $this->defer($event, 'provider_time_outside_attempt');
            }
            if ($type === 'Bounce') {
                $state = match ($p['bounce']['bounceType'] ?? '') {
                    'Permanent' => 'hard_bounced','Transient' => 'soft_bounced', default => 'unknown'
                };
            }
            Client::query()->whereKey($message->batch->client_id)->lockForUpdate()->firstOrFail();
            $message->refresh();
            $normalized = new CommunicationEvent;
            $code = $type === 'DeliveryDelay' ? 'delivery_delayed' : ($state === 'unknown' ? 'bounce_under_review' : $state);
            $dedup = hash('sha256', json_encode([$event->integration_account_id, $mail['messageId'], $code, $p[$section]['feedbackId'] ?? $occurred->toIso8601String()], JSON_THROW_ON_ERROR));
            if (CommunicationEvent::query()->where('communication_id', $message->id)->where('provider_dedup_key', $dedup)->exists()) {
                return $this->complete($event, 'duplicate_provider_event');
            }
            $normalized->forceFill(['communication_id' => $message->id, 'attempt_id' => $attempt->id, 'integration_event_id' => $event->id, 'provider_dedup_key' => $dedup, 'event_code' => $code, 'occurred_at' => $occurred, 'received_at' => now(), 'details' => []])->save();
            $ranks = ['draft' => 0, 'queued' => 1, 'cancelled' => 2, 'suppressed' => 3, 'unknown' => 4, 'accepted' => 5, 'soft_bounced' => 6, 'failed' => 7, 'delivered' => 8, 'hard_bounced' => 9, 'complained' => 10];
            $next = $ranks[$state] > ($ranks[$message->delivery_status] ?? 0) ? $state : $message->delivery_status;
            $updates = ['delivery_status' => $next, 'provider_message_id' => $mail['messageId'], 'sent_at' => $message->sent_at ?? $accepted, 'last_receipt_event_id' => $event->id, 'next_attempt_at' => null, 'failure_code' => in_array($next, ['accepted', 'delivered'], true) ? null : 'ses_'.$next];
            if ($type === 'Delivery') {
                $updates['delivered_at'] = $message->delivered_at ?? $occurred;
            }
            if ($type === 'Bounce') {
                $updates['bounced_at'] = $message->bounced_at ?? $occurred;
            }
            if ($type === 'Complaint') {
                $updates['complained_at'] = $message->complained_at ?? $occurred;
            }
            $message->forceFill($updates)->save();
            foreach (CommunicationQuotaReservation::query()->where('attempt_id', $attempt->id)->whereIn('state', ['reserved', 'unknown'])->lockForUpdate()->get() as $quota) {
                $quota->forceFill(['state' => 'consumed', 'accepted_at' => $accepted, 'resolution_event_id' => $event->id])->save();
            }
            if (in_array($state, ['hard_bounced', 'complained', 'unknown'], true)) {
                $this->restrict($event, $message, $attempt, $state === 'hard_bounced' ? 'hard_bounced' : 'under_review');
            }
            $batch = CommunicationBatch::query()->lockForUpdate()->findOrFail($message->batch_id);
            if (in_array($batch->status, ['queued', 'sending', 'paused'], true) && ! $batch->communications()->where('delivery_status', 'queued')->exists()) {
                $error = $batch->communications()->whereIn('delivery_status', ['failed', 'unknown', 'soft_bounced', 'hard_bounced', 'complained', 'suppressed'])->exists();
                $batch->forceFill(['status' => $error ? 'completed_with_errors' : 'completed', 'lock_version' => $batch->lock_version + 1])->save();
            }

            return $this->complete($event, 'applied');
        }, 3);
    }

    private function restrict(IntegrationEvent $event, Communication $message, CommunicationAttempt $attempt, string $state): void
    {
        $selection = $message->context_snapshot_ciphertext['selection'];
        $account = $message->task?->debt_account_id ?? DebtAccount::query()->where('public_id', $selection['account'] ?? '')->value('id');
        $current = CommunicationPreference::query()->where('customer_id', $message->customer_id)->where('contact_id', $message->contact_id)->where('debt_account_id', $account)->where('is_current', true)->first();
        $pref = new CommunicationPreference;
        $pref->forceFill(['client_id' => $message->batch->client_id, 'customer_id' => $message->customer_id, 'contact_id' => $message->contact_id, 'debt_account_id' => $account, 'task_id' => $message->task_id, 'state' => $state, 'basis' => null, 'source_integration_event_id' => $event->id, 'evidence_ciphertext' => ['source' => 'Authenticated Amazon SES receipt', 'reference' => $event->public_id, 'email' => $message->recipient_ciphertext, 'email_source' => 'Frozen outgoing envelope', 'attempt' => $attempt->public_id], 'reason_ciphertext' => 'Verified provider feedback requires suppression and review.', 'effective_at' => now(), 'recorded_by' => null, 'review_status' => 'pending', 'is_current' => false, 'supersedes_id' => $current?->id, 'request_key' => (string) Str::uuid7()])->save();
        $this->audit->record(null, 'communication.provider_restriction', $pref->public_id, ['status' => $state, 'evidence_fingerprint' => $event->payload_digest], module: 'M14', entityType: 'communication_preference', previous: ['status' => $current?->state ?? 'unknown']);
    }

    private function complete(IntegrationEvent $event, string $code): bool
    {
        $event->forceFill(['state' => 'processed', 'processed_at' => now(), 'resolution_code' => $code])->save();

        return true;
    }

    private function defer(IntegrationEvent $event, string $code, bool $terminal = false): bool
    {
        $event->forceFill(['state' => $terminal ? 'failed' : 'needs_resolution', 'resolution_code' => $code])->save();
        if (! $terminal) {
            OutboxEvent::query()->where('event_type', 'ses.receipt')->where('aggregate_id', $event->id)->update(['dispatched_at' => null, 'available_at' => now()->addMinutes(5)]);
        }

        return $terminal;
    }
}
