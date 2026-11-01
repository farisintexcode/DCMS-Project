<?php

namespace App\Services\Communications;

use App\Models\AccountParty;
use App\Models\CommunicationPreference;
use App\Models\Contact;
use App\Models\Task;
use App\Services\Customers\CustomerSearch;

class EmailEligibility
{
    public const Restrictive = ['blocked', 'withdrawn', 'invalid_address', 'hard_bounced', 'under_review'];

    public function __construct(private CustomerSearch $search) {}

    public function canonical(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    public function fingerprint(string $email): string
    {
        return $this->search->token('contact:email', $this->canonical($email));
    }

    public function restriction(Contact $contact): ?string
    {
        $token = $this->fingerprint($contact->value_ciphertext);
        $row = CommunicationPreference::query()->where('channel', 'email')->where('purpose', 'service_collection')->whereIn('state', self::Restrictive)
            ->where(fn ($q) => $q->where('review_status', 'pending')->orWhere(fn ($q) => $q->where('review_status', 'approved')->where('is_current', true)->where('effective_at', '<=', now()->format('Y-m-d H:i:s.u'))->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()->format('Y-m-d H:i:s.u')))))
            ->where(fn ($q) => $q->where('customer_id', $contact->debtor_id)->where(fn ($q) => $q->whereNull('contact_id')->orWhere('contact_id', $contact->id))->orWhereHas('contact', fn ($q) => $q->where('kind_code', 'email')->where('value_fingerprint', $token))
                ->orWhere(fn ($q) => $q->whereNull('contact_id')->whereHas('customer.contacts', fn ($q) => $q->where('kind_code', 'email')->where('value_fingerprint', $token))))->orderBy('id')->first();

        return $row?->state;
    }

    public function evaluate(Contact $contact, ?int $accountId, ?int $taskId): array
    {
        $email = $this->canonical($contact->value_ciphertext);
        if (! filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 254 || preg_match('/[\r\n\x00]/', $email)) {
            return ['code' => 'invalid_email', 'preference' => null];
        }
        if ($contact->valid_from?->isFuture() || ($contact->valid_to !== null && $contact->valid_to->lessThanOrEqualTo(now()))) {
            return ['code' => 'retired_contact', 'preference' => null];
        }
        if ($restriction = $this->restriction($contact)) {
            return ['code' => 'restricted_'.$restriction, 'preference' => null];
        }
        if (! $accountId || ! AccountParty::query()->where('debtor_id', $contact->debtor_id)->where('debt_account_id', $accountId)->whereNull('valid_to')->exists()) {
            return ['code' => 'missing_account_relationship', 'preference' => null];
        }
        $pref = CommunicationPreference::query()->where('customer_id', $contact->debtor_id)->where('debt_account_id', $accountId)->where('channel', 'email')->where('purpose', 'service_collection')->where('is_current', true)->where('review_status', 'approved')->where('effective_at', '<=', now()->format('Y-m-d H:i:s.u'))->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()->format('Y-m-d H:i:s.u')))->where(fn ($q) => $q->whereNull('contact_id')->orWhere('contact_id', $contact->id))->orderByRaw('contact_id IS NULL')->orderByDesc('id')->first();
        if (! $pref || $pref->state !== 'allowed') {
            return ['code' => 'preference_unknown', 'preference' => $pref?->public_id];
        }
        $evidence = $pref->evidence_ciphertext;
        if (! in_array($pref->basis, config('communications.basis_codes'), true) || empty($evidence['source']) || empty($evidence['reference']) || empty($evidence['email_source']) || $this->canonical($evidence['email'] ?? '') !== $email) {
            return ['code' => 'incomplete_basis_evidence', 'preference' => $pref->public_id];
        }
        if ($pref->basis === 'AUTHORIZED_COLLECTION' && (! $pref->task_id || ($taskId !== null && $pref->task_id !== $taskId) || ! Task::query()->whereKey($pref->task_id)->where('customer_id', $contact->debtor_id)->where('debt_account_id', $accountId)->where('work_status', '<>', 'cancelled')->whereHas('batch', fn ($q) => $q->where('status', 'active'))->exists())) {
            return ['code' => 'missing_authorized_case', 'preference' => $pref->public_id];
        }

        return ['code' => null, 'preference' => $pref->public_id];
    }
}
