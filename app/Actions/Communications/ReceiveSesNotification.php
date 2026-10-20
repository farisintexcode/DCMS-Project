<?php

namespace App\Actions\Communications;

use App\Models\IntegrationAccount;
use App\Models\IntegrationEvent;
use App\Models\OutboxEvent;
use App\Services\Communications\VerifySnsMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** M14 verified durable inbox. Only minimum SES fields survive; no email headers/body/token. */
class ReceiveSesNotification
{
    public function __construct(private VerifySnsMessage $signatures) {}

    public function receive(IntegrationAccount $account, array $message): IntegrationEvent
    {
        $digest = $this->signatures->verify($account, $message);
        $payload = ['type' => $message['Type'], 'verification' => ['sns_message_id' => $message['MessageId'], 'topic' => $message['TopicArn'], 'signed_at' => $message['Timestamp'], 'signature_version' => $message['SignatureVersion'], 'certificate_url' => $message['SigningCertURL']]];
        if ($message['Type'] === 'Notification') {
            try {
                $raw = json_decode($message['Message'], true, 16, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                abort(400);
            }
            abort_unless(is_array($raw) && is_array($raw['mail'] ?? null), 400);
            $payload['event_type'] = $raw['eventType'] ?? $raw['notificationType'] ?? '';
            $payload['mail'] = array_intersect_key($raw['mail'], array_flip(['timestamp', 'messageId', 'source', 'sendingAccountId', 'destination']));
            $payload['attempt_tag'] = $raw['mail']['tags']['debtops_attempt'] ?? [];
            foreach (['delivery', 'bounce', 'complaint', 'send', 'reject', 'failure', 'deliveryDelay'] as $key) {
                if (is_array($raw[$key] ?? null)) {
                    $payload[$key] = array_intersect_key($raw[$key], array_flip(['timestamp', 'recipients', 'bouncedRecipients', 'complainedRecipients', 'bounceType', 'feedbackId']));
                }
            }
            foreach (['bounce' => 'bouncedRecipients', 'complaint' => 'complainedRecipients'] as $key => $field) {
                if (isset($payload[$key][$field]) && is_array($payload[$key][$field])) {
                    $payload[$key][$field] = array_map(fn ($row) => is_array($row) ? array_intersect_key($row, ['emailAddress' => true]) : [], $payload[$key][$field]);
                }
            }
        }

        return DB::transaction(function () use ($account, $message, $digest, $payload): IntegrationEvent {
            $event = IntegrationEvent::unguarded(fn () => IntegrationEvent::query()->firstOrCreate(['integration_account_id' => $account->id, 'event_key' => $message['MessageId']], ['event_type' => $message['Type'] === 'Notification' ? 'ses.notification' : 'sns.control', 'provider_resource_id' => is_string($payload['mail']['messageId'] ?? null) ? substr($payload['mail']['messageId'], 0, 200) : null, 'payload_ciphertext' => $payload, 'payload_digest' => $digest, 'received_at' => now(), 'verified_at' => now(), 'state' => $message['Type'] === 'Notification' ? 'pending' : 'needs_resolution', 'resolution_code' => $message['Type'] === 'Notification' ? null : 'manual_subscription_control']));
            abort_unless(hash_equals($event->payload_digest, $digest), 409);
            if ($event->wasRecentlyCreated && $event->event_type === 'ses.notification') {
                $outbox = new OutboxEvent;
                $outbox->forceFill(['event_key' => (string) Str::uuid7(), 'event_type' => 'ses.receipt', 'aggregate_type' => 'integration_event', 'aggregate_id' => $event->id, 'payload' => [], 'available_at' => now()])->save();
            }

            return $event;
        }, 3);
    }
}
