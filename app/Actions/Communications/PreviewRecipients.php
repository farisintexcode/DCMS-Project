<?php

namespace App\Actions\Communications;

use App\Models\Client;
use App\Models\Communication;
use App\Models\CommunicationBatch;
use App\Models\CommunicationPreference;
use App\Models\CommunicationTemplate;
use App\Models\User;
use App\Services\AuditWriter;
use App\Services\Communications\EmailEligibility;
use App\Services\Communications\RecipientAccess;
use App\Services\Communications\TemplateContext;
use App\Services\Communications\TemplateRenderer;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** UI66–68: immutable encrypted previews only. No send/queue/provider side effects. */
class PreviewRecipients
{
    public function __construct(private RecipientAccess $access, private TemplateContext $context, private TemplateRenderer $renderer, private EmailEligibility $eligibility, private AuditWriter $audit) {}

    private function input(array $input): array
    {
        $data = Validator::make($input, ['client' => ['required', 'uuid'], 'template' => ['required', 'uuid'], 'mode' => ['required', 'in:single,bulk'], 'rows' => ['required', 'array', 'list', 'min:1', 'max:'.config('communications.preview_max_recipients')], 'rows.*' => ['array:contact,account,task'], 'rows.*.contact' => ['required', 'uuid'], 'rows.*.account' => ['nullable', 'uuid'], 'rows.*.task' => ['nullable', 'uuid']])->validate();
        if ($data['mode'] === 'single' && count($data['rows']) !== 1) {
            throw ValidationException::withMessages(['rows' => 'Individual previews contain exactly one explicit contact.']);
        }
        $data['rows'] = array_map(fn ($r) => ['contact' => $r['contact'], 'account' => $r['account'] ?? '', 'task' => $r['task'] ?? ''], $data['rows']);
        usort($data['rows'], fn ($a, $b) => strcmp(json_encode($a), json_encode($b)));

        return $data;
    }

    private function plan(User $actor, array $data): array
    {
        $this->access->compose($actor, $data['mode'] === 'bulk');
        $client = $this->access->client($actor, $data['client']);
        $template = CommunicationTemplate::query()->where('public_id', $data['template'])->where('channel', 'email')->where('status', 'approved')->first();
        if (! $template) {
            throw ValidationException::withMessages(['template' => 'Select a currently approved template version.']);
        }
        $contacts = $this->access->contacts($actor)->whereHas('debtor', fn ($q) => $q->where('client_id', $client->id))->whereIn('public_id', array_column($data['rows'], 'contact'))->with('debtor.client')->get()->keyBy('public_id');
        $rows = [];
        foreach ($data['rows'] as $selection) {
            $contact = $contacts->get($selection['contact']);
            abort_unless($contact, 404);
            $source = $this->context->values($actor, $contact->debtor->public_id, $selection['account'], $selection['task']);
            $decision = $this->eligibility->evaluate($contact, $source['account']?->id, $source['task']?->id);
            $issues = $decision['code'] ? [$decision['code']] : [];
            $rendered = null;
            $renderErrors = [];
            try {
                $rendered = $this->renderer->render($template, $source['values']);
            } catch (ValidationException $e) {
                $issues[] = 'required_source_data';
                $renderErrors = collect($e->errors())->flatten()->all();
            }
            if ($contact->debtor->status !== 'active') {
                $issues[] = 'inactive_customer';
            }
            $category = $issues === [] ? 'eligible' : (array_intersect($issues, ['invalid_email', 'retired_contact', 'missing_account_relationship', 'required_source_data', 'inactive_customer']) ? 'invalid' : 'suppressed');
            $email = $this->eligibility->canonical($contact->value_ciphertext);
            $fingerprint = $this->eligibility->fingerprint($email);
            $preferenceVersions = CommunicationPreference::query()->where(fn ($q) => $q->where('customer_id', $contact->debtor_id)->orWhereHas('contact', fn ($q) => $q->where('value_fingerprint', $fingerprint))->orWhere(fn ($q) => $q->whereNull('contact_id')->whereHas('customer.contacts', fn ($q) => $q->where('value_fingerprint', $fingerprint))))->orderBy('id')->get(['id', 'lock_version', 'review_status', 'is_current', 'effective_at', 'expires_at'])->toArray();
            $snapshot = ['selection' => $selection, 'customer_revision' => $source['customer']->revision(), 'account_revision' => $source['account']?->revision(), 'task_version' => $source['task']?->lock_version, 'template_version' => $template->version, 'values' => $source['values'], 'sources' => $source['sources'], 'preference' => $decision['preference'], 'preference_versions' => $preferenceVersions, 'issues' => $issues, 'render_errors' => $renderErrors, 'classification' => $category];
            $rows[] = ['customer_id' => $contact->debtor_id, 'contact_id' => $contact->id, 'task_id' => $source['task']?->id, 'recipient' => $email, 'fingerprint' => $fingerprint, 'subject' => $rendered['subject'] ?? null, 'body' => $rendered['body'] ?? null, 'snapshot' => $snapshot, 'context_key' => hash('sha256', json_encode([$contact->debtor_id, $source['account']?->id, $source['task']?->id, $rendered], JSON_THROW_ON_ERROR))];
        }
        $groups = [];
        foreach ($rows as $i => $row) {
            if (filter_var($row['recipient'], FILTER_VALIDATE_EMAIL)) {
                $groups[$row['fingerprint']][] = $i;
            }
        }
        foreach ($groups as $indexes) {
            if (count($indexes) < 2) {
                continue;
            } $keys = array_unique(array_map(fn ($i) => $rows[$i]['context_key'], $indexes));
            foreach ($indexes as $position => $i) {
                if (count($keys) > 1) {
                    $rows[$i]['snapshot']['classification'] = 'conflict';
                    $rows[$i]['snapshot']['issues'][] = 'shared_destination_context';
                } elseif ($position > 0) {
                    $rows[$i]['snapshot']['classification'] = 'duplicate';
                    $rows[$i]['snapshot']['issues'][] = 'duplicate_destination';
                }
            }
        }
        $counts = ['selected' => count($rows), 'eligible' => 0, 'invalid' => 0, 'suppressed' => 0, 'duplicate' => 0, 'conflict' => 0];
        foreach ($rows as $row) {
            $counts[$row['snapshot']['classification']]++;
        } $counts['excluded'] = $counts['selected'] - $counts['eligible'];

        return ['client' => $client, 'template' => $template, 'rows' => $rows, 'counts' => $counts, 'digest' => hash('sha256', json_encode([$data, $rows], JSON_THROW_ON_ERROR))];
    }

    public function create(User $actor, array $input, string $key): CommunicationBatch
    {
        $data = $this->input($input);
        Validator::make(['key' => $key], ['key' => ['required', 'uuid']])->validate();

        return DB::transaction(function () use ($actor, $data, $key): CommunicationBatch {
            $actor = $actor->fresh();
            $this->access->compose($actor, $data['mode'] === 'bulk');
            $client = $this->access->client($actor, $data['client']);
            Client::query()->whereKey($client->id)->lockForUpdate()->firstOrFail();
            $prior = CommunicationBatch::query()->where('idempotency_key', $key)->first();
            if ($prior) {
                abort_unless($prior->requested_by === $actor->id && $prior->client_id === $client->id && $this->input($prior->selection_criteria) === $data, 403);

                return $prior;
            }
            CommunicationTemplate::query()->where('public_id', $data['template'])->lockForUpdate()->firstOrFail();
            $plan = $this->plan($actor, $data);
            $batch = new CommunicationBatch;
            $batch->forceFill(['client_id' => $client->id, 'template_id' => $plan['template']->id, 'requested_by' => $actor->id, 'selection_criteria' => $data, 'counts' => (object) [], 'idempotency_key' => $key])->save();
            foreach ($plan['rows'] as $row) {
                $message = new Communication;
                $category = $row['snapshot']['classification'];
                $issues = $row['snapshot']['issues'];
                $message->forceFill(['customer_id' => $row['customer_id'], 'task_id' => $row['task_id'], 'contact_id' => $row['contact_id'], 'batch_id' => $batch->id, 'template_id' => $plan['template']->id, 'channel' => 'email', 'direction' => 'outgoing', 'recipient_ciphertext' => $row['recipient'], 'recipient_fingerprint' => $row['fingerprint'], 'subject_ciphertext' => $row['subject'], 'body_ciphertext' => $row['body'], 'context_snapshot_ciphertext' => $row['snapshot'], 'delivery_status' => $category === 'eligible' ? 'draft' : 'suppressed', 'failure_code' => $category === 'eligible' ? null : ($category === 'conflict' ? 'shared_destination_context' : ($category === 'duplicate' ? 'duplicate_destination' : $issues[0])), 'idempotency_key' => (string) Str::uuid7()])->save();
            }
            $this->access->compose($actor->fresh(), $data['mode'] === 'bulk');
            $this->access->client($actor->fresh(), $data['client']);
            $batch->forceFill(['status' => 'previewed', 'counts' => $plan['counts'], 'preview_digest' => $plan['digest'], 'lock_version' => 1])->save();
            $this->audit->record($actor, 'communication.preview_saved', $batch->public_id, ['status' => 'previewed'], module: 'M14', entityType: 'communication_batch');

            return $batch;
        }, 3);
    }

    public function freshness(User $actor, CommunicationBatch $batch): array
    {
        Gate::forUser($actor)->authorize('view', $batch);
        if (! $actor->can('communications.compose') || ($batch->selection_criteria['mode'] === 'bulk' && ! $actor->can('communications.bulk_send'))) {
            return ['fresh' => false, 'reason' => 'Compose permission is required to recheck source eligibility.'];
        }
        try {
            $plan = $this->plan($actor, $this->input($batch->selection_criteria));

            return ['fresh' => hash_equals($batch->preview_digest, $plan['digest']), 'reason' => 'A changed source, scope, template or preference requires a new preview.'];
        } catch (ModelNotFoundException|ValidationException|AuthorizationException|HttpException) {
            return ['fresh' => false, 'reason' => 'The selected source, template or current authorization is no longer available.'];
        }
    }

    public function recipientIsCurrent(User $actor, Communication $message): bool
    {
        try {
            $message->loadMissing(['batch.client', 'batch.template']);
            $batch = $message->batch;
            $selection = $message->context_snapshot_ciphertext['selection'];
            $plan = $this->plan($actor, $this->input(['client' => $batch->client->public_id, 'template' => $batch->template->public_id, 'mode' => $batch->selection_criteria['mode'], 'rows' => [$selection]]));
            $row = $plan['rows'][0];

            return $row['snapshot'] == $message->context_snapshot_ciphertext && $row['snapshot']['classification'] === 'eligible' && $row['recipient'] === $message->recipient_ciphertext && $row['subject'] === $message->subject_ciphertext && $row['body'] === $message->body_ciphertext;
        } catch (ModelNotFoundException|ValidationException|AuthorizationException|HttpException) {
            return false;
        }
    }

    public function cancel(User $actor, CommunicationBatch $target, int $version): CommunicationBatch
    {
        return DB::transaction(function () use ($actor, $target, $version): CommunicationBatch {
            Client::query()->whereKey($target->client_id)->lockForUpdate()->firstOrFail();
            $actor = $actor->fresh();
            $batch = CommunicationBatch::query()->lockForUpdate()->findOrFail($target->id);
            Gate::forUser($actor)->authorize('cancel', $batch);
            if ($batch->status !== 'previewed' || $batch->confirmed_at !== null) {
                throw ValidationException::withMessages(['revision' => 'Confirmed queue work must use delivery cancellation.']);
            }
            if ($batch->lock_version !== $version) {
                throw ValidationException::withMessages(['revision' => 'This preview changed. Reload it.']);
            }
            $batch->communications()->where('delivery_status', 'draft')->get()->each(fn ($message) => $message->forceFill(['delivery_status' => 'cancelled'])->save());
            $batch->forceFill(['status' => 'cancelled', 'lock_version' => $version + 1])->save();
            $this->audit->record($actor, 'communication.preview_cancelled', $batch->public_id, ['status' => 'cancelled'], module: 'M14', entityType: 'communication_batch');

            return $batch;
        }, 3);
    }
}
