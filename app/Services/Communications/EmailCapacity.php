<?php

namespace App\Services\Communications;

use App\Models\CommunicationAttempt;
use App\Models\CommunicationQuotaReservation;
use App\Models\IntegrationAccount;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** UI67 rolling capacity; admission is serialized across all email workers/accounts. */
class EmailCapacity
{
    public function __construct(private EmailTransport $transport) {}

    public function account(): ?IntegrationAccount
    {
        if (! config('communications.delivery_enabled')) {
            return null;
        }
        $account = IntegrationAccount::query()->where('public_id', config('communications.email_account'))->where('channel', 'email')->where('status', 'enabled')->whereNotNull('capability_verified_at')->first();
        if (! $account || ! $this->transport->supports($account)) {
            return null;
        }
        $c = $account->capabilities;
        foreach (['quota_limit', 'quota_window_seconds', 'rate_per_second'] as $key) {
            if (! is_int($c[$key] ?? null) || $c[$key] < 1) {
                return null;
            }
        }
        foreach (['account_confirmed', 'sender_verified', 'service_email_approved', 'dkim_verified', 'spf_verified', 'dmarc_verified'] as $key) {
            if (($c[$key] ?? false) !== true) {
                return null;
            }
        }
        if (app()->isProduction()) {
            // Production requires real SES/SNS/DNS acceptance and explicit sign-off, never a UI toggle.
            return null;
        }
        if (! is_int(config('communications.daily_limit')) || config('communications.daily_limit') < 1 || ! is_int(config('communications.rate_limit')) || config('communications.rate_limit') < 1) {
            return null;
        }

        return $account;
    }

    public function sandboxAllows(string $recipient): bool
    {
        return app()->isProduction() && ! config('communications.sandbox_enabled') || in_array(mb_strtolower(trim($recipient)), array_map(fn ($email) => mb_strtolower(trim($email)), config('communications.sandbox_recipients')), true);
    }

    public function lock(): void
    {
        DB::select('SELECT pg_advisory_xact_lock(?, ?)', [14068, 0]);
    }

    /** @return array{app_remaining:int,provider_remaining:int,rate_remaining:int,resume_at:CarbonImmutable} */
    public function remaining(IntegrationAccount $account): array
    {
        $now = CarbonImmutable::now();
        $usage = function (string $bucket, int $window) use ($now): int {
            return CommunicationQuotaReservation::query()->where('bucket_key', $bucket)->where(fn ($q) => $q->whereIn('state', ['reserved', 'unknown'])->orWhere(fn ($q) => $q->where('state', 'consumed')->where('accepted_at', '>', $now->subSeconds($window)->format('Y-m-d H:i:s.u'))))->count();
        };
        $rate = function (string $bucket) use ($now): int {
            return CommunicationQuotaReservation::query()->where('bucket_key', $bucket)->where('reserved_at', '>', $now->subSecond()->format('Y-m-d H:i:s.u'))->count();
        };

        return ['app_remaining' => max(0, config('communications.daily_limit') - $usage('email:application', 86400)), 'provider_remaining' => max(0, $account->capabilities['quota_limit'] - $usage('email:provider:'.$account->id, $account->capabilities['quota_window_seconds'])), 'rate_remaining' => max(0, min(config('communications.rate_limit') - $rate('email:application'), $account->capabilities['rate_per_second'] - $rate('email:provider:'.$account->id))), 'resume_at' => $now->addMinute()];
    }

    public function reserve(CommunicationAttempt $attempt): void
    {
        foreach (['email:application' => null, 'email:provider:'.$attempt->integration_account_id => $attempt->integration_account_id] as $bucket => $account) {
            $row = new CommunicationQuotaReservation;
            $row->forceFill(['attempt_id' => $attempt->id, 'bucket_key' => $bucket, 'integration_account_id' => $account, 'reserved_at' => now(), 'state' => 'reserved'])->save();
        }
    }

    public function settle(CommunicationAttempt $attempt, string $state): void
    {
        foreach (CommunicationQuotaReservation::query()->where('attempt_id', $attempt->id)->where('state', 'reserved')->lockForUpdate()->get() as $row) {
            $row->forceFill(['state' => $state, 'accepted_at' => $state === 'consumed' ? now() : null, 'expires_at' => $state === 'released' ? now() : null])->save();
        }
    }
}
