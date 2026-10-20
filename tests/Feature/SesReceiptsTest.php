<?php

use App\Actions\Communications\ApplySesReceipt;
use App\Actions\Communications\DeliverEmail;
use App\Actions\Communications\ManagePreferences;
use App\Actions\Communications\ReceiveSesNotification;
use App\Jobs\ProcessSesReceipt;
use App\Livewire\Admin\Communications\Receipts;
use App\Models\Communication;
use App\Models\CommunicationAttempt;
use App\Models\CommunicationEvent;
use App\Models\CommunicationPreference;
use App\Models\CommunicationQuotaReservation;
use App\Models\IntegrationAccount;
use App\Models\IntegrationEvent;
use App\Models\OutboxEvent;
use App\Services\Communications\EmailEligibility;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(LazilyRefreshDatabase::class);
beforeEach(function (): void {
    $this->withoutVite();
    $this->seed(DatabaseSeeder::class);
    Cache::flush();
});
function receiptKeys(): array
{
    static $keys = null;
    if ($keys === null) {
        $path = dirname(PHP_BINARY).'/extras/ssl/openssl.cnf';
        $options = ['digest_alg' => 'sha256'];
        if (is_file($path)) {
            $options['config'] = $path;
        }
        $private = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048, ...$options]);
        $csr = openssl_csr_new(['commonName' => 'Synthetic SNS signature fixture'], $private, $options);
        $cert = openssl_csr_sign($csr, null, $private, 365, $options);
        openssl_x509_export($cert, $pem);
        $keys = [$private, $pem];
    }

    return $keys;
}
function receiptFixture(string $state = 'accepted'): array
{
    [$actor,$batch,$account,$transport] = deliveryFixture();
    queueDelivery($actor, $batch);
    $transport->result = $state === 'unknown' ? 'exception' : 'accepted';
    app(DeliverEmail::class)->process($batch->communications()->firstOrFail()->id);
    $account->forceFill(['provider_code' => 'amazon_ses', 'sender_reference_ciphertext' => 'collections@example.test', 'capabilities' => [...$account->capabilities, 'region' => 'ap-southeast-1', 'aws_account_id' => '123456789012', 'sns_policy_verified' => true, 'sns_topic_arn' => 'arn:aws:sns:ap-southeast-1:123456789012:debtops-test']])->save();
    config(['communications.ses_receipts_enabled' => true, 'communications.ses_topics' => [$account->capabilities['sns_topic_arn']]]);

    return [$actor, $batch, $account, $transport];
}
function receiptPayload(Communication $message, string $type = 'Delivery', array $extra = []): array
{
    $attempt = $message->attempts()->firstOrFail();
    $section = match ($type) {
        'Delivery' => 'delivery','Bounce' => 'bounce','Complaint' => 'complaint','Send' => 'send',default => 'reject'
    };

    return array_replace_recursive(['eventType' => $type, 'mail' => ['timestamp' => $attempt->started_at->toIso8601String(), 'messageId' => $message->provider_message_id ?? 'ses-unknown-'.$attempt->request_key, 'sendingAccountId' => '123456789012', 'source' => 'collections@example.test', 'destination' => [$message->recipient_ciphertext], 'tags' => ['debtops_attempt' => [$attempt->request_key]], 'headers' => [['name' => 'Private', 'value' => 'NEVER PERSIST RAW HEADER']]], $section => ['timestamp' => now()->toIso8601String()]], $extra);
}
function signedReceipt(IntegrationAccount $account, array $payload, array $extra = []): array
{
    [$private,$pem] = receiptKeys();
    $url = 'https://sns.ap-southeast-1.amazonaws.com/SimpleNotificationService-testcert.pem';
    Http::fake([$url => Http::response($pem, 200)]);
    $m = array_replace(['Type' => 'Notification', 'Message' => json_encode($payload, JSON_THROW_ON_ERROR), 'MessageId' => (string) Str::uuid(), 'Timestamp' => now()->toIso8601String(), 'TopicArn' => $account->capabilities['sns_topic_arn'], 'SignatureVersion' => '2', 'SigningCertURL' => $url], $extra);
    $fields = $m['Type'] === 'Notification' ? ['Message', 'MessageId', 'Subject', 'Timestamp', 'TopicArn', 'Type'] : ['Message', 'MessageId', 'SubscribeURL', 'Timestamp', 'Token', 'TopicArn', 'Type'];
    $canonical = '';
    foreach ($fields as $field) {
        if (! array_key_exists($field, $m)) {
            continue;
        } $canonical .= $field."\n".$m[$field]."\n";
    }
    openssl_sign($canonical, $signature, $private, $m['SignatureVersion'] === '1' ? OPENSSL_ALGO_SHA1 : OPENSSL_ALGO_SHA256);
    $m['Signature'] = base64_encode($signature);

    return $m;
}
function ingestReceipt(IntegrationAccount $account, array $payload, array $extra = []): IntegrationEvent
{
    return app(ReceiveSesNotification::class)->receive($account, signedReceipt($account, $payload, $extra));
}

test('signed SNS notification endpoint durably accepts and deduplicates exact verified bytes', function (string $version): void {
    [, $batch,$account] = receiptFixture();
    $message = $batch->communications()->firstOrFail();
    $body = signedReceipt($account, receiptPayload($message), ['SignatureVersion' => $version, 'Subject' => "Synthetic receipt\nUnicode ✓"]);
    $this->call('POST', route('integrations.ses.notifications', $account->public_id), [], [], [], ['CONTENT_TYPE' => 'text/plain'], json_encode($body))->assertStatus(202)->assertExactJson(['status' => 'received']);
    $this->call('POST', route('integrations.ses.notifications', $account->public_id), [], [], [], ['CONTENT_TYPE' => 'text/plain'], json_encode($body))->assertStatus(202);
    expect(IntegrationEvent::query()->count())->toBe(1)->and(OutboxEvent::query()->where('event_type', 'ses.receipt')->count())->toBe(1);
    $event = IntegrationEvent::query()->firstOrFail();
    expect($event->payload_ciphertext['mail'])->not->toHaveKey('headers')->and(DB::table('integration_events')->value('payload_ciphertext'))->not->toContain($message->recipient_ciphertext, 'NEVER PERSIST RAW HEADER');
})->with(['1', '2']);

test('listener rejects unregistered topics hostile certificate URLs tampering and future messages before persistence', function (string $case): void {
    [, $batch,$account] = receiptFixture();
    $payload = receiptPayload($batch->communications()->firstOrFail());
    $extra = match ($case) {
        'topic' => ['TopicArn' => 'arn:aws:sns:ap-southeast-1:999999999999:other'],'host' => ['SigningCertURL' => 'https://169.254.169.254/latest/meta-data/'],'userinfo' => ['SigningCertURL' => 'https://sns.ap-southeast-1.amazonaws.com@evil.example/SimpleNotificationService-test.pem'],'redirect' => ['SigningCertURL' => 'https://sns.ap-southeast-1.amazonaws.com/SimpleNotificationService-testcert.pem?redirect=elsewhere'],'future' => ['Timestamp' => now()->addHour()->toIso8601String()],default => []
    };
    $body = signedReceipt($account, $payload, $extra);
    if ($case === 'tamper') {
        $body['Message'] .= ' ';
    }
    $this->call('POST', route('integrations.ses.notifications', $account->public_id), [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($body))->assertStatus($case === 'topic' ? 403 : ($case === 'future' ? 400 : 401));
    expect(IntegrationEvent::query()->count())->toBe(0);
    if (! in_array($case, ['tamper'], true)) {
        Http::assertNothingSent();
    }
})->with(['topic', 'host', 'userinfo', 'redirect', 'future', 'tamper']);

test('disabled and oversized listeners do not create inbox work', function (): void {
    [,, $account] = receiptFixture();
    config(['communications.ses_receipts_enabled' => false]);
    $this->postJson(route('integrations.ses.notifications', $account->public_id), [])->assertNotFound();
    config(['communications.ses_receipts_enabled' => true]);
    $this->call('POST', route('integrations.ses.notifications', $account->public_id), [], [], [], ['CONTENT_TYPE' => 'text/plain'], str_repeat('x', 262145))->assertStatus(413);
    expect(IntegrationEvent::query()->count())->toBe(0);
    Http::assertNothingSent();
});

test('signed subscription control is retained without following subscription URLs', function (): void {
    [,, $account] = receiptFixture();
    $m = signedReceipt($account, [], ['Type' => 'SubscriptionConfirmation', 'Message' => 'Confirm a synthetic subscription', 'SubscribeURL' => 'https://evil.example/do-not-fetch', 'Token' => 'PRIVATE-CONTROL-TOKEN']);
    $event = app(ReceiveSesNotification::class)->receive($account, $m);
    expect($event->state)->toBe('needs_resolution')->and($event->payload_ciphertext)->not->toHaveKey('Token')->and(OutboxEvent::query()->where('event_type', 'ses.receipt')->count())->toBe(0);
    Http::assertSentCount(1);
});

test('delivery is receipt-derived and out-of-order acceptance does not regress it', function (): void {
    [, $batch,$account] = receiptFixture();
    $message = $batch->communications()->firstOrFail();
    $delivery = ingestReceipt($account, receiptPayload($message));
    app(ApplySesReceipt::class)->apply($delivery->id);
    $send = ingestReceipt($account, receiptPayload($message, 'Send'));
    app(ApplySesReceipt::class)->apply($send->id);
    expect($message->refresh()->delivery_status)->toBe('delivered')->and($message->delivered_at)->not->toBeNull()->and($send->refresh()->state)->toBe('processed')->and(CommunicationEvent::query()->whereNotNull('integration_event_id')->count())->toBe(2);
    expect(fn () => DB::transaction(fn () => DB::table('integration_events')->where('id', $delivery->id)->update(['payload_ciphertext' => 'forged'])))->toThrow(QueryException::class);
});

test('hard bounce and complaint suppress immediately with system evidence and no fabricated recorder', function (string $type, string $expected): void {
    [$actor,$batch,$account] = receiptFixture();
    $message = $batch->communications()->with('contact')->firstOrFail();
    $extra = $type === 'Bounce' ? ['bounce' => ['bounceType' => 'Permanent', 'feedbackId' => 'feedback-test', 'bouncedRecipients' => [['emailAddress' => $message->recipient_ciphertext]]]] : ['complaint' => ['feedbackId' => 'feedback-test']];
    $event = ingestReceipt($account, receiptPayload($message, $type, $extra));
    app(ApplySesReceipt::class)->apply($event->id);
    $pref = CommunicationPreference::query()->where('source_integration_event_id', $event->id)->firstOrFail();
    expect($pref->recorded_by)->toBeNull()->and($pref->review_status)->toBe('pending')->and($pref->state)->toBe($expected)->and(app(EmailEligibility::class)->restriction($message->contact))->toBe($expected);
    $this->actingAs($actor)->get(route('admin.communications.preferences', $message->customer))->assertOk()->assertSee('Amazon SES (verified receipt)');
    $duplicate = ingestReceipt($account, receiptPayload($message, $type, $extra));
    app(ApplySesReceipt::class)->apply($duplicate->id);
    expect(CommunicationPreference::query()->whereNotNull('source_integration_event_id')->count())->toBe(1)->and($duplicate->refresh()->resolution_code)->toBe('duplicate_provider_event');
})->with([['Bounce', 'hard_bounced'], ['Complaint', 'under_review']]);

test('soft bounce does not become a hard suppression or authorize another send', function (): void {
    [, $batch,$account,$transport] = receiptFixture();
    $message = $batch->communications()->firstOrFail();
    $event = ingestReceipt($account, receiptPayload($message, 'Bounce', ['bounce' => ['bounceType' => 'Transient']]));
    app(ApplySesReceipt::class)->apply($event->id);
    app(DeliverEmail::class)->process($message->id);
    expect($message->refresh()->delivery_status)->toBe('soft_bounced')->and(CommunicationPreference::query()->whereNotNull('source_integration_event_id')->count())->toBe(0)->and($transport->calls)->toBe(1);
});

test('authenticated attempt tags reconcile held unknown quota without rewriting original ambiguity', function (): void {
    [, $batch,$account] = receiptFixture('unknown');
    $message = $batch->communications()->firstOrFail();
    $event = ingestReceipt($account, receiptPayload($message));
    app(ApplySesReceipt::class)->apply($event->id);
    expect($message->refresh()->delivery_status)->toBe('delivered')->and(CommunicationAttempt::query()->firstOrFail()->state)->toBe('unknown')->and(CommunicationQuotaReservation::query()->where('state', 'consumed')->where('resolution_event_id', $event->id)->count())->toBe(2);
});

test('unmatched or contradictory context is never attached by customer email', function (string $case): void {
    [, $batch,$account] = receiptFixture();
    $message = $batch->communications()->firstOrFail();
    $extra = match ($case) {
        'destination' => ['mail' => ['destination' => ['someone-else@example.test']]],'account' => ['mail' => ['sendingAccountId' => '999999999999']],'source' => ['mail' => ['source' => 'wrong@example.test']],'resource' => ['mail' => ['messageId' => 'unmapped-resource', 'tags' => ['debtops_attempt' => []]]],default => []
    };
    $payload = receiptPayload($message, 'Delivery', $extra);
    if ($case === 'resource') {
        $payload['mail']['tags'] = [];
    }
    $event = ingestReceipt($account, $payload);
    expect(app(ApplySesReceipt::class)->apply($event->id))->toBeFalse();
    expect($event->refresh()->state)->toBe('needs_resolution')->and($message->refresh()->delivery_status)->toBe('accepted')->and(CommunicationEvent::query()->whereNotNull('integration_event_id')->count())->toBe(0);
})->with(['destination', 'account', 'source', 'resource']);

test('operator inbox is permission protected and retries never override receipt mapping', function (): void {
    [$actor,$batch,$account] = receiptFixture();
    $payload = receiptPayload($batch->communications()->firstOrFail());
    $payload['mail']['messageId'] = 'unknown';
    unset($payload['mail']['tags']);
    $event = ingestReceipt($account, $payload);
    app(ApplySesReceipt::class)->apply($event->id);
    $this->get(route('admin.communications.receipts'))->assertRedirect(route('login'));
    $this->actingAs($actor)->get(route('admin.communications.receipts'))->assertForbidden();
    $actor->givePermissionTo('integrations.manage');
    Livewire::test(Receipts::class)->call('retry', $event->public_id)->assertHasNoErrors()->assertSee('Unmatched Provider Resource');
    expect($event->refresh()->state)->toBe('needs_resolution');
});

test('receipt dispatcher publishes Redis work and replayed jobs cannot duplicate normalized receipts', function (): void {
    [, $batch,$account] = receiptFixture();
    $event = ingestReceipt($account, receiptPayload($batch->communications()->firstOrFail()));
    Queue::fake();
    $this->artisan('communications:dispatch-receipts')->assertSuccessful();
    Queue::assertPushed(ProcessSesReceipt::class);
    $outbox = OutboxEvent::query()->where('event_type', 'ses.receipt')->firstOrFail();
    $job = new ProcessSesReceipt($outbox->id);
    $job->handle(app(ApplySesReceipt::class));
    $job->handle(app(ApplySesReceipt::class));
    expect(CommunicationEvent::query()->where('integration_event_id', $event->id)->count())->toBe(1);
});

test('a verified delivery before the sending response wins without releasing quota or overwriting history', function (): void {
    [$actor,$batch,$account,$transport] = deliveryFixture();
    queueDelivery($actor, $batch);
    $message = $batch->communications()->firstOrFail();
    $transport->callback = function (CommunicationAttempt $attempt) use ($account, $message): array {
        $account->forceFill(['provider_code' => 'amazon_ses', 'sender_reference_ciphertext' => 'collections@example.test', 'capabilities' => [...$account->capabilities, 'region' => 'ap-southeast-1', 'aws_account_id' => '123456789012', 'sns_policy_verified' => true, 'sns_topic_arn' => 'arn:aws:sns:ap-southeast-1:123456789012:debtops-test']])->save();
        config(['communications.ses_receipts_enabled' => true, 'communications.ses_topics' => [$account->capabilities['sns_topic_arn']]]);
        $event = ingestReceipt($account, receiptPayload($message));
        app(ApplySesReceipt::class)->apply($event->id);

        return ['state' => 'unknown'];
    };
    app(DeliverEmail::class)->process($message->id);
    expect($message->refresh()->delivery_status)->toBe('delivered')->and(CommunicationQuotaReservation::query()->where('state', 'consumed')->count())->toBe(2)->and(CommunicationAttempt::query()->firstOrFail()->state)->toBe('unknown');
});

test('a later tagless receipt reuses prior authenticated correlation after a transport timeout', function (): void {
    [, $batch,$account] = receiptFixture('unknown');
    $message = $batch->communications()->firstOrFail();
    $first = ingestReceipt($account, receiptPayload($message));
    app(ApplySesReceipt::class)->apply($first->id);
    $payload = receiptPayload($message->refresh(), 'Complaint');
    unset($payload['mail']['tags']);
    $second = ingestReceipt($account, $payload);
    app(ApplySesReceipt::class)->apply($second->id);
    expect($second->refresh()->state)->toBe('processed')->and($message->refresh()->delivery_status)->toBe('complained');
});

test('certificate redirects invalid certificates and oversized certificates are rejected without persistence', function (string $bad): void {
    [, $batch,$account] = receiptFixture();
    $body = signedReceipt($account, receiptPayload($batch->communications()->firstOrFail()));
    Http::swap(new Factory);
    Http::fake(['*' => match ($bad) {
        'redirect' => Http::response('', 302, ['Location' => 'http://169.254.169.254/latest/meta-data/']),'large' => Http::response(str_repeat('x', 65537), 200),default => Http::response('not a certificate', 200)
    }]);
    $this->call('POST', route('integrations.ses.notifications', $account->public_id), [], [], [], ['CONTENT_TYPE' => 'text/plain'], json_encode($body))->assertStatus($bad === 'invalid' ? 401 : 503);
    expect(IntegrationEvent::query()->count())->toBe(0);
    Http::assertSentCount(1);
})->with(['redirect', 'large', 'invalid']);

test('a conflicting signed replay key cannot overwrite the first verified event', function (): void {
    [, $batch,$account] = receiptFixture();
    $payload = receiptPayload($batch->communications()->firstOrFail());
    $m = signedReceipt($account, $payload);
    app(ReceiveSesNotification::class)->receive($account, $m);
    $payload['eventType'] = 'Complaint';
    $changed = signedReceipt($account, $payload, ['MessageId' => $m['MessageId']]);
    $this->call('POST', route('integrations.ses.notifications', $account->public_id), [], [], [], ['CONTENT_TYPE' => 'text/plain'], json_encode($changed))->assertStatus(409);
    expect(IntegrationEvent::query()->count())->toBe(1);
});

test('unverified topic publishing policy and production plain HTTP cannot activate receipt processing', function (): void {
    [, $batch,$account] = receiptFixture();
    $body = signedReceipt($account, receiptPayload($batch->communications()->firstOrFail()));
    $account->forceFill(['capabilities' => array_replace($account->capabilities, ['sns_policy_verified' => false])])->save();
    $this->call('POST', route('integrations.ses.notifications', $account->public_id), [], [], [], ['CONTENT_TYPE' => 'text/plain'], json_encode($body))->assertForbidden();
    Http::assertNothingSent();
    $account->forceFill(['capabilities' => array_replace($account->capabilities, ['sns_policy_verified' => true])])->save();
    app()->detectEnvironment(fn () => 'production');
    $this->call('POST', route('integrations.ses.notifications', $account->public_id), [], [], [], ['CONTENT_TYPE' => 'text/plain'], json_encode($body))->assertStatus(400);
    Http::assertNothingSent();
});

test('receipt provenance cannot be bypassed to rewrite delivery content or release unknown reservations', function (): void {
    [, $batch,$account] = receiptFixture('unknown');
    $message = $batch->communications()->firstOrFail();
    expect(fn () => DB::transaction(fn () => DB::table('communication_quota_reservations')->where('state', 'unknown')->update(['state' => 'released', 'expires_at' => now()])))->toThrow(QueryException::class);
    $event = ingestReceipt($account, receiptPayload($message));
    app(ApplySesReceipt::class)->apply($event->id);
    expect(fn () => DB::transaction(fn () => DB::table('communications')->where('id', $message->id)->update(['delivered_at' => now()->addHour()])))->toThrow(QueryException::class);
    expect(fn () => DB::transaction(fn () => DB::table('communications')->where('id', $message->id)->update(['body_ciphertext' => 'forged body'])))->toThrow(QueryException::class);
    expect(fn () => DB::transaction(fn () => CommunicationPreference::factory()->create(['recorded_by' => null])))->toThrow(QueryException::class);
});

test('provider restrictions are independently reviewed and restoring eligibility requires existing override controls', function (): void {
    [$actor,$batch,$account] = receiptFixture();
    $message = $batch->communications()->with('contact')->firstOrFail();
    $event = ingestReceipt($account, receiptPayload($message, 'Bounce', ['bounce' => ['bounceType' => 'Permanent']]));
    app(ApplySesReceipt::class)->apply($event->id);
    $pref = CommunicationPreference::query()->where('source_integration_event_id', $event->id)->firstOrFail();
    app(ManagePreferences::class)->review($actor, $pref, 0, 'approved', 'Synthetic reviewed provider evidence');
    expect($pref->refresh()->recorded_by)->toBeNull()->and($pref->reviewed_by)->toBe($actor->id)->and($pref->is_current)->toBeTrue();
    $actor->givePermissionTo(['communication-permission.update', 'communication-permission.override']);
    $selection = $message->context_snapshot_ciphertext['selection'];
    $manager = app(ManagePreferences::class);
    $new = $manager->request($actor, $message->customer->public_id, ['state' => 'allowed', 'basis' => 'EXISTING_ACCOUNT_RELATIONSHIP', 'contact' => $message->contact->public_id, 'account' => $selection['account'], 'source' => 'Synthetic corrected provider evidence', 'reference' => 'OVERRIDE-REVIEW', 'email_source' => 'Reviewed customer record', 'reason' => 'Synthetic documented review of incorrect bounce'], (string) Str::uuid7());
    $manager->review($actor, $new, 0, 'approved', 'Synthetic authorized override');
    expect(app(EmailEligibility::class)->restriction($message->contact))->toBeNull()->and($pref->refresh()->is_current)->toBeFalse();
});
