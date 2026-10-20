<?php

namespace App\Services\Communications;

use App\Models\IntegrationAccount;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/** AWS SNS canonical signature contract. No arbitrary URLs, redirects or subscription requests. */
class VerifySnsMessage
{
    public function verify(IntegrationAccount $account, array $message): string
    {
        $region = $account->capabilities['region'] ?? config('communications.ses_region');
        abort_unless(is_string($region) && preg_match('/^[a-z]{2}-[a-z-]+-[0-9]+$/D', $region), 403);
        $topic = $account->capabilities['sns_topic_arn'] ?? '';
        abort_unless(($account->capabilities['sns_policy_verified'] ?? false) === true, 403);
        abort_unless(is_string($topic) && preg_match('/^arn:aws:sns:'.preg_quote($region, '/').':[0-9]{12}:[A-Za-z0-9_-]+$/D', $topic) && in_array($topic, config('communications.ses_topics'), true), 403);
        foreach (['Type', 'Message', 'MessageId', 'Timestamp', 'TopicArn', 'SignatureVersion', 'Signature', 'SigningCertURL'] as $key) {
            abort_unless(isset($message[$key]) && is_string($message[$key]) && $message[$key] !== '', 400);
        }
        abort_unless($message['TopicArn'] === $topic && in_array($message['Type'], ['Notification', 'SubscriptionConfirmation', 'UnsubscribeConfirmation'], true), 403);
        abort_unless(in_array($message['SignatureVersion'], ['1', '2'], true), 401);
        abort_unless(Str::isUuid($message['MessageId']) && preg_match('/^\d{4}-\d{2}-\d{2}T/', $message['Timestamp']), 400);
        try {
            $timestamp = CarbonImmutable::parse($message['Timestamp']);
        } catch (\Throwable) {
            abort(400);
        }
        abort_if($timestamp->greaterThan(now()->addMinutes(5)), 400);
        $url = $message['SigningCertURL'];
        $parts = parse_url($url);
        abort_unless($parts && ($parts['scheme'] ?? '') === 'https' && ($parts['host'] ?? '') === 'sns.'.$region.'.amazonaws.com' && (! isset($parts['port']) || $parts['port'] === 443) && ! isset($parts['user'],$parts['pass']) && ! isset($parts['query']) && ! isset($parts['fragment']) && preg_match('~^/SimpleNotificationService-[A-Za-z0-9]+\.pem$~D', $parts['path'] ?? ''), 401);
        abort_if(isset($parts['user']) || isset($parts['pass']), 401);
        $fields = $message['Type'] === 'Notification' ? ['Message', 'MessageId', 'Subject', 'Timestamp', 'TopicArn', 'Type'] : ['Message', 'MessageId', 'SubscribeURL', 'Timestamp', 'Token', 'TopicArn', 'Type'];
        $canonical = '';
        foreach ($fields as $key) {
            if ($key === 'Subject' && ! array_key_exists($key, $message)) {
                continue;
            }
            abort_unless(isset($message[$key]) && is_string($message[$key]), 400);
            $canonical .= $key."\n".$message[$key]."\n";
        }
        $signature = base64_decode($message['Signature'], true);
        abort_unless($signature !== false, 401);
        abort_if(strlen($message['MessageId']) > 200 || strlen($message['Signature']) > 4096 || strlen($url) > 2048, 400);
        $certificate = Cache::remember('ses-sns-certificate:'.hash('sha256', $url), 3600, function () use ($url): string {
            try {
                $response = Http::connectTimeout(3)->timeout(8)->withOptions(['allow_redirects' => false, 'stream' => true])->get($url);
                abort_unless($response->status() === 200, 503);
                $body = $response->toPsrResponse()->getBody()->read(65537);
                abort_if(strlen($body) > 65536, 503);

                return $body;
            } catch (ConnectionException) {
                abort(503);
            }
        });
        $parsed = @openssl_x509_parse($certificate);
        $key = @openssl_pkey_get_public($certificate);
        $details = $key ? openssl_pkey_get_details($key) : false;
        abort_unless($parsed && $key && $details && $details['type'] === OPENSSL_KEYTYPE_RSA && $details['bits'] >= 2048 && $timestamp->getTimestamp() >= ($parsed['validFrom_time_t'] ?? PHP_INT_MAX) && $timestamp->getTimestamp() <= ($parsed['validTo_time_t'] ?? 0), 401);
        abort_unless(openssl_verify($canonical, $signature, $key, $message['SignatureVersion'] === '2' ? OPENSSL_ALGO_SHA256 : OPENSSL_ALGO_SHA1) === 1, 401);

        return hash('sha256', $canonical);
    }
}
