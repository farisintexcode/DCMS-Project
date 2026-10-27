<?php

namespace App\Services\Communications;

use App\Models\Communication;
use App\Models\CommunicationAttempt;
use App\Models\IntegrationAccount;
use Aws\Exception\AwsException;
use Aws\SesV2\SesV2Client;

/** M14 owner-approved SES v2. SDK retries disabled: SendEmail has no idempotency token. */
class EmailTransport
{
    public function supports(IntegrationAccount $account): bool
    {
        return $account->provider_code === 'amazon_ses' && class_exists(SesV2Client::class) && filter_var($account->sender_reference_ciphertext, FILTER_VALIDATE_EMAIL) !== false;
    }

    /** @return array{state:string,reference?:string} */
    public function deliver(IntegrationAccount $account, Communication $message, CommunicationAttempt $attempt): array
    {
        if (! $this->supports($account)) {
            throw new \LogicException('Verified SES account required.');
        }
        $client = $this->client($account);
        try {
            $current = $client->getAccount();
            $quota = $current['SendQuota'] ?? [];
            if (($current['SendingEnabled'] ?? false) !== true || (app()->isProduction() && ($current['ProductionAccessEnabled'] ?? false) !== true) || ($quota['Max24HourSend'] ?? 0) - ($quota['SentLast24Hours'] ?? 0) < 1 || ($quota['MaxSendRate'] ?? 0) < 1 || $account->capabilities['rate_per_second'] > ($quota['MaxSendRate'] ?? 0) || $account->capabilities['quota_limit'] > ($quota['Max24HourSend'] ?? 0)) {
                return ['state' => 'deferred'];
            }
        } catch (\Throwable) {
            return ['state' => 'deferred'];
        }
        $payload = ['FromEmailAddress' => 'Nexora <'.$account->sender_reference_ciphertext.'>', 'Destination' => ['ToAddresses' => [$message->recipient_ciphertext]], 'Content' => ['Simple' => ['Subject' => ['Data' => $message->subject_ciphertext, 'Charset' => 'UTF-8'], 'Body' => ['Text' => ['Data' => $message->body_ciphertext, 'Charset' => 'UTF-8']]]], 'EmailTags' => [['Name' => 'debtops_attempt', 'Value' => $attempt->request_key]]];
        $configuration = $account->capabilities['configuration_set'] ?? config('communications.ses_configuration_set');
        if (is_string($configuration) && preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $configuration)) {
            $payload['ConfigurationSetName'] = $configuration;
        }
        if (filter_var($account->capabilities['reply_to'] ?? '', FILTER_VALIDATE_EMAIL)) {
            $payload['ReplyToAddresses'] = [$account->capabilities['reply_to']];
        }
        try {
            $result = $client->sendEmail($payload);

            return empty($result['MessageId']) ? ['state' => 'unknown'] : ['state' => 'accepted', 'reference' => $result['MessageId']];
        } catch (AwsException $error) {
            if (in_array($error->getAwsErrorCode(), ['TooManyRequestsException', 'LimitExceededException'], true)) {
                return ['state' => 'deferred'];
            }
            if (in_array($error->getAwsErrorCode(), ['BadRequestException', 'MessageRejected', 'MailFromDomainNotVerifiedException', 'AccountSuspendedException', 'SendingPausedException'], true)) {
                return ['state' => 'permanent_failure'];
            }

            return ['state' => 'unknown'];
        } catch (\Throwable) {
            return ['state' => 'unknown'];
        }
    }

    protected function client(IntegrationAccount $account): SesV2Client
    {
        return new SesV2Client(['version' => 'latest', 'region' => $account->capabilities['region'] ?? config('communications.ses_region'), 'retries' => 0, 'http' => ['connect_timeout' => 5, 'timeout' => 30]]);
    }
}
