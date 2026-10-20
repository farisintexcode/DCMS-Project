<?php

use App\Actions\Communications\DeliverEmail;
use App\Actions\Communications\ManagePreferences;
use App\Actions\Communications\ManageTemplates;
use App\Actions\Communications\PreviewRecipients;
use App\Models\Communication;
use App\Models\CommunicationAttempt;
use App\Models\CommunicationBatch;
use App\Models\CommunicationTemplate;
use App\Models\Contact;
use App\Models\DebtAccount;
use App\Models\IntegrationAccount;
use App\Models\Task;
use App\Models\User;
use App\Models\UserClientScope;
use App\Services\Communications\EmailTransport;
use App\Services\Customers\CustomerSearch;
use Illuminate\Support\Str;

class SyntheticEmailTransport extends EmailTransport
{
    public int $calls = 0;

    public string $result = 'accepted';

    public ?Closure $callback = null;

    public function supports(IntegrationAccount $account): bool
    {
        return $account->provider_code === 'synthetic';
    }

    public function deliver(IntegrationAccount $account, Communication $message, CommunicationAttempt $attempt): array
    {
        $this->calls++;
        if ($this->callback) {
            return ($this->callback)($attempt);
        }
        if ($this->result === 'exception') {
            throw new RuntimeException('Private provider error must not be logged');
        }

        return ['state' => $this->result, 'reference' => 'synthetic-'.$attempt->request_key];
    }
}
function deliveryFixture(int $recipients = 1): array
{
    $task = Task::factory()->create()->refresh();
    $actor = User::factory()->create();
    $actor->assignRole('Admin');
    $actor->givePermissionTo(['templates.approve', 'communication-permission.create', 'communication-permission.approve']);
    $scope = new UserClientScope;
    $scope->forceFill(['user_id' => $actor->id, 'client_id' => $task->batch->client_id, 'granted_by' => $actor->id, 'valid_from' => now()->subMinute(), 'reason' => 'Synthetic delivery portfolio'])->save();
    $source = CommunicationTemplate::query()->where('email_slot', 1)->where('status', 'approved')->firstOrFail();
    $manager = app(ManageTemplates::class);
    $draft = $manager->createVersion($actor, $source);
    $draft = $manager->save($actor, $draft, 0, ['name' => 'Synthetic service delivery', 'subject' => 'Account {{account_number}}', 'body' => 'Hello {{customer_name}} from {{company_name}}.', 'required' => ['customer_name', 'account_number', 'company_name']]);
    $pending = $manager->submit($actor, $draft, 1);
    $template = $manager->approve($actor, $pending, 2);
    $rows = [];
    $allowlist = [];
    for ($i = 0; $i < $recipients; $i++) {
        $current = $i === 0 ? $task : Task::factory()->create(['assignment_batch_id' => $task->assignment_batch_id, 'debt_account_id' => DebtAccount::factory()->create(['client_id' => $task->batch->client_id])->id]);
        $email = 'delivery-'.$i.'@example.test';
        $allowlist[] = $email;
        $contact = Contact::factory()->create(['debtor_id' => $current->customer_id, 'kind_code' => 'email', 'value_ciphertext' => $email, 'value_fingerprint' => app(CustomerSearch::class)->token('contact:email', $email)]);
        $prefs = app(ManagePreferences::class);
        $pref = $prefs->request($actor, $current->customer->public_id, ['state' => 'allowed', 'basis' => 'EXISTING_ACCOUNT_RELATIONSHIP', 'contact' => $contact->public_id, 'account' => $current->account->public_id, 'source' => 'Synthetic authorized account', 'reference' => 'TEST-EMAIL', 'email_source' => 'Synthetic client record', 'reason' => 'Synthetic service relationship'], (string) Str::uuid7());
        $prefs->review($actor, $pref, 0, 'approved', 'Synthetic checked evidence');
        $rows[] = ['contact' => $contact->public_id, 'account' => $current->account->public_id];
    }
    $batch = app(PreviewRecipients::class)->create($actor, ['client' => $task->batch->client->public_id, 'template' => $template->public_id, 'mode' => $recipients === 1 ? 'single' : 'bulk', 'rows' => $rows], (string) Str::uuid7());
    $account = IntegrationAccount::factory()->create(['status' => 'enabled', 'capability_verified_at' => now(), 'capabilities' => ['account_confirmed' => true, 'sender_verified' => true, 'service_email_approved' => true, 'dkim_verified' => true, 'spf_verified' => true, 'dmarc_verified' => true, 'quota_limit' => 10, 'quota_window_seconds' => 86400, 'rate_per_second' => 2]]);
    $transport = new SyntheticEmailTransport;
    app()->instance(EmailTransport::class, $transport);
    config(['communications.delivery_enabled' => true, 'communications.email_account' => $account->public_id, 'communications.sandbox_recipients' => $allowlist]);

    return [$actor, $batch, $account, $transport];
}
function queueDelivery(User $actor, CommunicationBatch $batch): CommunicationBatch
{
    return app(DeliverEmail::class)->confirm($actor, $batch, $batch->lock_version, $batch->preview_digest);
}
