<?php

use App\Actions\Communications\DeliverEmail;
use App\Actions\Communications\ManagePreferences;
use App\Actions\Communications\PreviewRecipients;
use App\Jobs\DeliverServiceEmail;
use App\Livewire\Admin\Communications\Batches\Show;
use App\Models\Communication;
use App\Models\CommunicationAttempt;
use App\Models\CommunicationEvent;
use App\Models\CommunicationQuotaReservation;
use App\Models\IntegrationAccount;
use App\Models\OutboxEvent;
use App\Models\ProcessedEvent;
use App\Services\Communications\EmailCapacity;
use App\Services\Communications\EmailTransport;
use Aws\MockHandler;
use Aws\Result;
use Aws\SesV2\SesV2Client;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(LazilyRefreshDatabase::class);
beforeEach(function (): void {
    $this->withoutVite();
    $this->seed(DatabaseSeeder::class);
});

test('confirmation is exact replay safe durable and does not call the provider from HTTP', function (): void {
    [$actor,$batch,,$transport] = deliveryFixture();
    $this->actingAs($actor);
    expect(fn () => app(DeliverEmail::class)->confirm($actor, $batch, 1, str_repeat('0', 64)))->toThrow(ValidationException::class);
    Livewire::test(Show::class, ['batch' => $batch])->call('confirm', 1, $batch->preview_digest)->assertHasNoErrors()->assertSee('Refresh delivery history');
    $confirmed = $batch->refresh();
    queueDelivery($actor, $confirmed);
    expect($confirmed->status)->toBe('queued')->and($confirmed->confirmed_by)->toBe($actor->id)->and(OutboxEvent::query()->where('event_type', 'email.deliver')->count())->toBe(1)->and($transport->calls)->toBe(0);
});

test('disabled verification and sandbox controls block confirmation without durable send side effects', function (string $gate): void {
    [$actor,$batch,$account] = deliveryFixture();
    if ($gate === 'sandbox') {
        config(['communications.sandbox_recipients' => [], 'communications.sandbox_enabled' => false]);
    } elseif ($gate === 'disabled') {
        config(['communications.delivery_enabled' => false]);
    } else {
        $account->forceFill(['capability_verified_at' => null, 'status' => 'disabled'])->save();
    }
    expect(fn () => queueDelivery($actor, $batch))->toThrow(ValidationException::class);
    expect($batch->refresh()->confirmed_at)->toBeNull()->and(OutboxEvent::query()->where('event_type', 'email.deliver')->count())->toBe(0);
})->with(['sandbox', 'disabled', 'unverified']);

test('acceptance retains attempts and quota and never claims delivery or resends a duplicate job', function (): void {
    [$actor,$batch,,$transport] = deliveryFixture();
    $queued = queueDelivery($actor, $batch);
    $message = $queued->communications()->firstOrFail();
    $level = DB::transactionLevel();
    $transport->callback = function (CommunicationAttempt $attempt) use ($level): array {
        expect(DB::transactionLevel())->toBe($level)->and($attempt->state)->toBe('pending')->and(CommunicationQuotaReservation::query()->where('attempt_id', $attempt->id)->where('state', 'reserved')->count())->toBe(2);

        return ['state' => 'accepted', 'reference' => 'synthetic-ses-message'];
    };
    app(DeliverEmail::class)->process($message->id);
    app(DeliverEmail::class)->process($message->id);
    expect($transport->calls)->toBe(1)->and($message->refresh()->delivery_status)->toBe('accepted')->and($message->sent_at)->not->toBeNull()->and($batch->refresh()->status)->toBe('completed')->and(CommunicationQuotaReservation::query()->where('state', 'consumed')->count())->toBe(2)->and(CommunicationEvent::query()->where('event_code', 'delivered')->exists())->toBeFalse();
});

test('rolling business quota survives midnight and only releases accepted usage after 24 hours', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-17 15:59:30 UTC'));
    [$actor,$batch,$account,$transport] = deliveryFixture(2);
    config(['communications.daily_limit' => 1]);
    queueDelivery($actor, $batch);
    $messages = $batch->communications()->orderBy('id')->get();
    app(DeliverEmail::class)->process($messages[0]->id);
    $this->travel(2)->minutes();
    expect(app(DeliverEmail::class)->process($messages[1]->id))->toBe(60)->and($transport->calls)->toBe(1)->and($batch->refresh()->status)->toBe('paused');
    expect(app(EmailCapacity::class)->remaining($account)['app_remaining'])->toBe(0);
    config(['communications.daily_limit' => 5000]);
    expect(app(EmailCapacity::class)->remaining($account)['app_remaining'])->toBe(4999);
    config(['communications.daily_limit' => 1]);
    $this->travel(24)->hours();
    app(DeliverEmail::class)->process($messages[1]->id);
    expect($messages[1]->refresh()->delivery_status)->toBe('accepted')->and($transport->calls)->toBe(2);
});

test('provider capacity and global rate can independently lower the application limit', function (string $limit): void {
    [$actor,$batch,$account,$transport] = deliveryFixture(2);
    if ($limit === 'provider') {
        $account->forceFill(['capabilities' => array_replace($account->capabilities, ['quota_limit' => 1])])->save();
    } else {
        config(['communications.rate_limit' => 1]);
    }
    queueDelivery($actor, $batch);
    $messages = $batch->communications()->orderBy('id')->get();
    app(DeliverEmail::class)->process($messages[0]->id);
    expect(app(DeliverEmail::class)->process($messages[1]->id))->not->toBeNull()->and($transport->calls)->toBe(1)->and($messages[1]->refresh()->delivery_status)->toBe('queued');
})->with(['provider', 'rate']);

test('definitive transient failures release capacity retry with backoff and stop at the configured cap', function (): void {
    [$actor,$batch,,$transport] = deliveryFixture();
    queueDelivery($actor, $batch);
    $message = $batch->communications()->firstOrFail();
    $transport->result = 'retryable_failure';
    expect(app(DeliverEmail::class)->process($message->id))->toBe(30);
    expect(app(DeliverEmail::class)->process($message->id))->not->toBeNull()->and($transport->calls)->toBe(1);
    $this->travel(31)->seconds();
    expect(app(DeliverEmail::class)->process($message->id))->toBe(120);
    $this->travel(121)->seconds();
    app(DeliverEmail::class)->process($message->id);
    app(DeliverEmail::class)->process($message->id);
    expect($transport->calls)->toBe(3)->and($message->refresh()->delivery_status)->toBe('failed')->and(CommunicationQuotaReservation::query()->where('state', 'released')->count())->toBe(6);
});

test('ambiguous exceptions remain unknown across rollover and cannot be retried', function (): void {
    [$actor,$batch,$account,$transport] = deliveryFixture();
    queueDelivery($actor, $batch);
    $message = $batch->communications()->firstOrFail();
    $transport->result = 'exception';
    app(DeliverEmail::class)->process($message->id);
    $this->travel(25)->hours();
    app(DeliverEmail::class)->process($message->id);
    expect($message->refresh()->delivery_status)->toBe('unknown')->and($transport->calls)->toBe(1)->and(CommunicationQuotaReservation::query()->where('state', 'unknown')->count())->toBe(2)->and(app(EmailCapacity::class)->remaining($account)['provider_remaining'])->toBe(9);
    expect(CommunicationAttempt::query()->firstOrFail()->error_code)->toBe('transport_unknown');
});

test('interrupted persisted attempts recover to unknown without automatic resend', function (): void {
    [$actor,$batch,,$transport] = deliveryFixture();
    queueDelivery($actor, $batch);
    $message = $batch->communications()->firstOrFail();
    $transport->callback = function (): array {
        $this->travel(121)->seconds();
        app(DeliverEmail::class)->recover();

        return ['state' => 'accepted', 'reference' => 'late-result'];
    };
    app(DeliverEmail::class)->process($message->id);
    app(DeliverEmail::class)->process($message->id);
    expect($message->refresh()->delivery_status)->toBe('unknown')->and($transport->calls)->toBe(1)->and(CommunicationAttempt::query()->firstOrFail()->state)->toBe('unknown');
});

test('current permission or evidence changes suppress queued recipients before transport', function (): void {
    [$actor,$batch,,$transport] = deliveryFixture();
    queueDelivery($actor, $batch);
    $message = $batch->communications()->firstOrFail();
    $actor->revokePermissionTo('communications.send');
    $actor->getRoleNames();
    $actor->removeRole('Admin');
    app(DeliverEmail::class)->process($message->id);
    expect($message->refresh()->delivery_status)->toBe('suppressed')->and($transport->calls)->toBe(0)->and(CommunicationAttempt::query()->count())->toBe(0);
});

test('owner cancellation retains accepted rows and cancels only pending work', function (): void {
    [$actor,$batch,,$transport] = deliveryFixture(2);
    queueDelivery($actor, $batch);
    $messages = $batch->communications()->orderBy('id')->get();
    app(DeliverEmail::class)->process($messages[0]->id);
    app(DeliverEmail::class)->cancel($actor, $batch->refresh(), $batch->lock_version);
    app(DeliverEmail::class)->process($messages[1]->id);
    expect($messages[0]->refresh()->delivery_status)->toBe('accepted')->and($messages[1]->refresh()->delivery_status)->toBe('cancelled')->and($transport->calls)->toBe(1)->and($batch->refresh()->status)->toBe('cancelled');
    expect(fn () => DB::transaction(fn () => DB::table('communication_events')->delete()))->toThrow(QueryException::class);
    expect(fn () => DB::transaction(fn () => DB::table('communication_attempts')->update(['state' => 'unknown'])))->toThrow(QueryException::class);
});

test('dispatcher publishes only durable email work and jobs carry IDs on Redis', function (): void {
    [$actor,$batch] = deliveryFixture();
    queueDelivery($actor, $batch);
    Queue::fake();
    $this->artisan('communications:dispatch')->assertSuccessful();
    Queue::assertPushed(DeliverServiceEmail::class, fn ($job) => $job->connection === 'redis' && $job->queue === 'email');
    expect(OutboxEvent::query()->where('event_type', 'email.deliver')->firstOrFail()->dispatched_at)->not->toBeNull();
});

test('provider deferrals pause without exhausting the definitive failure retry budget', function (): void {
    [$actor,$batch,,$transport] = deliveryFixture();
    queueDelivery($actor, $batch);
    $message = $batch->communications()->firstOrFail();
    $transport->result = 'deferred';
    for ($i = 0; $i < 4; $i++) {
        expect(app(DeliverEmail::class)->process($message->id))->toBe(60);
        $this->travel(61)->seconds();
    }
    expect($message->refresh()->delivery_status)->toBe('queued')->and($batch->refresh()->status)->toBe('paused');
    app(DeliverEmail::class)->resume($actor, $batch, $batch->lock_version);
    $transport->result = 'accepted';
    app(DeliverEmail::class)->process($message->id);
    expect($message->refresh()->delivery_status)->toBe('accepted')->and($transport->calls)->toBe(5);
});

test('a stop request after confirmation suppresses the recipient at dispatch', function (): void {
    [$actor,$batch,,$transport] = deliveryFixture();
    queueDelivery($actor, $batch);
    $message = $batch->communications()->with('customer')->firstOrFail();
    app(ManagePreferences::class)->request($actor, $message->customer->public_id, ['state' => 'blocked', 'source' => 'Synthetic stop request', 'reference' => 'TEST-STOP', 'email_source' => 'Customer request', 'reason' => 'Please stop service emails'], (string) Str::uuid7());
    app(DeliverEmail::class)->process($message->id);
    expect($message->refresh()->delivery_status)->toBe('suppressed')->and($transport->calls)->toBe(0);
});

test('global capacity admission uses a PostgreSQL lock shared by independent connections', function (): void {
    config(['database.connections.email_capacity_probe' => config('database.connections.pgsql')]);
    app(EmailCapacity::class)->lock();
    try {
        $result = DB::connection('email_capacity_probe')->selectOne('SELECT pg_try_advisory_xact_lock(?, ?) AS acquired', [14068, 0]);
        expect($result->acquired)->toBeFalse();
    } finally {
        DB::purge('email_capacity_probe');
    }
});

test('durable publication failure retains sanitized work and a replayed job does not send twice', function (): void {
    [$actor,$batch,,$transport] = deliveryFixture();
    queueDelivery($actor, $batch);
    Queue::shouldReceive('connection')->andThrow(new RuntimeException('Sensitive infrastructure failure'));
    $this->artisan('communications:dispatch')->assertFailed();
    $event = OutboxEvent::query()->where('event_type', 'email.deliver')->firstOrFail();
    expect($event->dispatched_at)->toBeNull()->and($event->last_error)->not->toContain('Sensitive');
    $job = new DeliverServiceEmail($event->id);
    $job->handle(app(DeliverEmail::class));
    $job->handle(app(DeliverEmail::class));
    expect($transport->calls)->toBe(1)->and(ProcessedEvent::query()->where('event_key', $event->event_key)->where('consumer', 'm14-email')->count())->toBe(1);
});

test('production activation cannot bypass the pending receipt and approval gate', function (): void {
    [$actor,$batch] = deliveryFixture();
    app()->detectEnvironment(fn () => 'production');
    config(['communications.sandbox_enabled' => false]);
    expect(app(EmailCapacity::class)->account())->toBeNull();
    expect(fn () => queueDelivery($actor, $batch))->toThrow(ValidationException::class);
    expect(CommunicationAttempt::query()->count())->toBe(0);
});

test('SES adapter checks actual account entitlement and sends one private text recipient with SDK retries disabled', function (): void {
    [$actor,$batch,$account] = deliveryFixture();
    queueDelivery($actor, $batch);
    $message = $batch->communications()->firstOrFail();
    $account->forceFill(['provider_code' => 'amazon_ses', 'sender_reference_ciphertext' => 'collections@example.test'])->save();
    $handler = new MockHandler([new Result(['SendingEnabled' => true, 'SendQuota' => ['Max24HourSend' => 1000, 'SentLast24Hours' => 0, 'MaxSendRate' => 2]]), new Result(['MessageId' => 'test-ses-id'])]);
    $client = new SesV2Client(['version' => 'latest', 'region' => 'ap-southeast-1', 'retries' => 0, 'credentials' => ['key' => 'synthetic', 'secret' => 'synthetic'], 'handler' => $handler]);
    $adapter = new class($client) extends EmailTransport
    {
        public function __construct(private SesV2Client $sdk) {}

        protected function client(IntegrationAccount $account): SesV2Client
        {
            return $this->sdk;
        }
    };
    $attempt = CommunicationAttempt::factory()->create(['communication_id' => $message->id, 'integration_account_id' => $account->id]);
    expect($adapter->deliver($account, $message, $attempt))->toBe(['state' => 'accepted', 'reference' => 'test-ses-id']);
    $command = $handler->getLastCommand();
    expect($command->getName())->toBe('SendEmail')->and($command['Destination'])->toBe(['ToAddresses' => [$message->recipient_ciphertext]])->and($command['Content']['Simple']['Body']['Text']['Data'])->toBe($message->body_ciphertext);
    $handler->append(new Result(['SendingEnabled' => true, 'SendQuota' => ['Max24HourSend' => 1, 'SentLast24Hours' => 1, 'MaxSendRate' => 1]]));
    expect($adapter->deliver($account, $message, $attempt)['state'])->toBe('deferred')->and($handler->getLastCommand()->getName())->toBe('GetAccount');
});

test('retained history is scoped and accessible without conflating acceptance with delivery', function (): void {
    [$actor,$batch] = deliveryFixture();
    queueDelivery($actor, $batch);
    $message = $batch->communications()->firstOrFail();
    app(DeliverEmail::class)->process($message->id);
    $this->actingAs($actor);
    Livewire::test(Show::class, ['batch' => $batch->refresh()])->call('inspectHistory', $message->public_id)->assertSee('Recipient attempt & event history')->assertSee('Provider attempts')->assertSee('Accepted')->assertHasNoErrors();
    $other = Communication::factory()->create();
    Livewire::test(Show::class, ['batch' => $batch])->call('inspectHistory', $other->public_id)->assertNotFound();
    expect(fn () => app(PreviewRecipients::class)->cancel($actor, $batch, $batch->lock_version))->toThrow(AuthorizationException::class);
});
