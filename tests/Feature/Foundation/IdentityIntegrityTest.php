<?php

use App\Models\User;
use App\Services\AuditWriter;
use App\Services\SanitizeSentryEvent;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Sentry\Event;

uses(LazilyRefreshDatabase::class);

test('case-insensitive email uniqueness survives direct database writes', function (): void {
    $user = User::factory()->create(['email' => 'staff@example.test']);

    expect(fn () => DB::transaction(fn () => DB::table('users')->insert([
        'public_id' => (string) Str::uuid7(), 'name' => 'Duplicate', 'email' => 'STAFF@example.test', 'password' => $user->password,
    ])))->toThrow(QueryException::class);
});

test('audit cannot be modified through a query builder bypass', function (): void {
    $audit = app(AuditWriter::class)->record(null, 'login_failed', metadata: ['password' => 'never-log-me', 'status' => 'rejected']);

    expect(fn () => DB::transaction(fn () => DB::table('audit_logs')->where('id', $audit->id)->update(['action' => 'tampered'])))->toThrow(QueryException::class);

    expect($audit->fresh()->action)->toBe('login_failed');
    expect($audit->new_values_redacted)->toBe(['status' => 'rejected']);
});

test('sentry signal contains no request data or exception details', function (): void {
    $event = Event::createEvent();
    $event->setMessage('Secret customer details');
    $event->setRequest(['data' => 'private-body', 'headers' => ['Authorization' => 'secret-token']]);
    $event->setExtra(['recipient' => 'private@example.test']);

    $safe = SanitizeSentryEvent::handle($event);

    expect($safe->getId())->toBe($event->getId());
    expect($safe->getMessage())->not->toContain('Secret customer details');
    expect($safe->getRequest())->toBe([]);
    expect($safe->getExtra())->toBe([]);
    expect($safe->getExceptions())->toBe([]);
});
