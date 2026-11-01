<?php

use App\Actions\Communications\ManagePreferences;
use App\Actions\Communications\ManageTemplates;
use App\Actions\Communications\PreviewRecipients;
use App\Livewire\Admin\Communications\Batches\Show;
use App\Livewire\Admin\Communications\Preferences;
use App\Livewire\Admin\Communications\Recipients;
use App\Models\AuditLog;
use App\Models\CommunicationBatch;
use App\Models\CommunicationPreference;
use App\Models\CommunicationTemplate;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Task;
use App\Models\User;
use App\Services\Communications\EmailEligibility;
use App\Services\Customers\CustomerSearch;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(LazilyRefreshDatabase::class);
beforeEach(function (): void {
    $this->withoutVite();
    $this->seed(DatabaseSeeder::class);
});
function recipientFixture(): array
{
    $task = Task::factory()->create()->refresh();
    $actor = User::factory()->create();
    $actor->assignRole('Admin');
    $actor->givePermissionTo(['templates.approve', 'communication-permission.create', 'communication-permission.update', 'communication-permission.block', 'communication-permission.approve', 'communication-permission.override']);
    $email = 'recipient@example.test';
    $contact = Contact::factory()->create(['debtor_id' => $task->customer_id, 'kind_code' => 'email', 'value_ciphertext' => $email, 'value_fingerprint' => app(CustomerSearch::class)->token('contact:email', $email)]);

    return [$actor, $task, $contact];
}
function permissionInput(Task $task, Contact $contact, array $extra = []): array
{
    return array_replace(['state' => 'allowed', 'basis' => 'EXISTING_ACCOUNT_RELATIONSHIP', 'contact' => $contact->public_id, 'account' => $task->account->public_id, 'source' => 'Synthetic reviewed customer record', 'reference' => 'TEST-AUTHORITY', 'email_source' => 'Synthetic customer contact', 'reason' => 'Synthetic service-account authority'], $extra);
}
function allowedRecipient(User $actor, Task $task, Contact $contact): CommunicationPreference
{
    $pref = app(ManagePreferences::class)->request($actor, $task->customer->public_id, permissionInput($task, $contact), (string) Str::uuid7());

    return app(ManagePreferences::class)->review($actor, $pref, 0, 'approved', 'Synthetic approved service evidence');
}
function recipientTemplate(User $actor): CommunicationTemplate
{
    $manager = app(ManageTemplates::class);
    $source = CommunicationTemplate::query()->where('email_slot', 1)->where('status', 'approved')->firstOrFail();
    $draft = $manager->createVersion($actor, $source);
    $draft = $manager->save($actor, $draft, 0, ['name' => 'Synthetic service notice', 'subject' => 'Notice {{account_number}}', 'body' => 'Hello {{customer_name}} from {{company_name}}.', 'required' => ['customer_name', 'account_number', 'company_name']]);
    $pending = $manager->submit($actor, $draft, 1);

    return $manager->approve($actor, $pending, 2);
}
function recipientSelection(Task $task, Contact $contact, CommunicationTemplate $template, array $extra = []): array
{
    return array_replace(['template' => $template->public_id, 'mode' => 'single', 'rows' => [['contact' => $contact->public_id, 'account' => $task->account->public_id]]], $extra);
}

test('recipient workspace enforces administrative access scope and reserved policy grants', function (): void {
    [$actor,$task] = $fixture = recipientFixture();
    $this->get(route('admin.communications.recipients'))->assertRedirect(route('login'));
    $this->actingAs($actor)->get(route('admin.communications.recipients'))->assertOk()->assertSee('Email recipients');
    $this->get(route('admin.communications.preferences', $task->customer))->assertOk();
    $other = User::factory()->create();
    $other->assignRole('Admin');
    expect($other->can('communication-permission.approve'))->toBeFalse();
    $this->actingAs($other)->get(route('admin.communications.preferences', $task->customer))->assertForbidden();
});

test('allowed evidence remains pending until approved and stores encrypted provenance and redacted audit', function (): void {
    [$actor,$task,$contact] = recipientFixture();
    $key = (string) Str::uuid7();
    $manager = app(ManagePreferences::class);
    $pref = $manager->request($actor, $task->customer->public_id, permissionInput($task, $contact), $key);
    expect($pref->is_current)->toBeFalse()->and($pref->review_status)->toBe('pending')->and(app(EmailEligibility::class)->evaluate($contact, $task->debt_account_id, null)['code'])->toBe('preference_unknown');
    expect($manager->request($actor, $task->customer->public_id, permissionInput($task, $contact), $key)->id)->toBe($pref->id);
    $approved = $manager->review($actor, $pref, 0, 'approved', 'Synthetic reviewed approval');
    expect($approved->is_current)->toBeTrue()->and(app(EmailEligibility::class)->evaluate($contact, $task->debt_account_id, null)['code'])->toBeNull();
    expect(DB::table('communication_preferences')->where('id', $pref->id)->value('evidence_ciphertext'))->not->toContain('recipient@example.test');
    expect(AuditLog::query()->where('entity_type', 'communication_preference')->get()->toJson())->not->toContain('recipient@example.test', 'TEST-AUTHORITY', 'Synthetic service-account authority');
});

test('stop requests suppress immediately and changing blocked authority requires override', function (): void {
    [$actor,$task,$contact] = recipientFixture();
    allowedRecipient($actor, $task, $contact);
    $actor->revokePermissionTo('communication-permission.block');
    $manager = app(ManagePreferences::class);
    $stop = $manager->request($actor, $task->customer->public_id, permissionInput($task, $contact, ['state' => 'withdrawn']), (string) Str::uuid7());
    expect($stop->review_status)->toBe('pending')->and(app(EmailEligibility::class)->evaluate($contact, $task->debt_account_id, null)['code'])->toBe('restricted_withdrawn');
    $actor->revokePermissionTo('communication-permission.override');
    expect(fn () => $manager->review($actor, $stop, 0, 'rejected', 'Do not ignore stop requests'))->toThrow(HttpException::class);
    $manager->review($actor, $stop, 0, 'approved', 'Customer request honored');
    $allow = $manager->request($actor, $task->customer->public_id, permissionInput($task, $contact), (string) Str::uuid7());
    expect(fn () => $manager->review($actor, $allow, 0, 'approved', 'Correction requires override'))->toThrow(HttpException::class);
    $actor->givePermissionTo('communication-permission.override');
    $manager->review($actor, $allow, 0, 'approved', 'Reviewed incorrect restriction with authority');
    expect(app(EmailEligibility::class)->evaluate($contact, $task->debt_account_id, null)['code'])->toBeNull();
});

test('explicit consent and collection authority require their evidence before review', function (array $extra): void {
    [$actor,$task,$contact] = recipientFixture();
    expect(fn () => app(ManagePreferences::class)->request($actor, $task->customer->public_id, permissionInput($task, $contact, $extra), (string) Str::uuid7()))->toThrow(ValidationException::class);
})->with([[['basis' => 'EXPLICIT_SERVICE_CONSENT']], [['basis' => 'AUTHORIZED_COLLECTION']], [['source' => '   ']], [['state' => 'allowed', 'account' => '']]]);

test('preview freezes personalized recipient data reconciles counts and never sends email', function (): void {
    Mail::fake();
    [$actor,$task,$contact] = recipientFixture();
    allowedRecipient($actor, $task, $contact);
    $template = recipientTemplate($actor);
    $preview = app(PreviewRecipients::class);
    $key = (string) Str::uuid7();
    $input = recipientSelection($task, $contact, $template);
    $batch = $preview->create($actor, $input, $key);
    expect($batch->status)->toBe('previewed')->and($batch->counts['selected'])->toBe(1)->and($batch->counts['eligible'])->toBe(1)->and($batch->counts['excluded'])->toBe(0)->and($batch->confirmed_at)->toBeNull();
    expect($preview->create($actor, $input, $key)->id)->toBe($batch->id)->and(CommunicationBatch::query()->count())->toBe(1);
    $message = $batch->communications()->firstOrFail();
    expect($message->delivery_status)->toBe('draft')->and($message->sent_at)->toBeNull()->and($message->body_ciphertext)->toContain($task->customer->display_name_ciphertext);
    expect(DB::table('communications')->value('recipient_ciphertext'))->not->toContain('recipient@example.test');
    expect($message->toArray())->not->toHaveKey('body_ciphertext');
    expect($preview->freshness($actor, $batch)['fresh'])->toBeTrue();
    Mail::assertNothingSent();
});

test('missing financial data and unknown authority are retained as exclusions without guessed amounts', function (): void {
    [$actor,$task,$contact] = recipientFixture();
    $template = CommunicationTemplate::query()->where('email_slot', 1)->where('status', 'approved')->firstOrFail();
    $batch = app(PreviewRecipients::class)->create($actor, recipientSelection($task, $contact, $template), (string) Str::uuid7());
    expect($batch->counts['eligible'])->toBe(0)->and($batch->counts['excluded'])->toBe(1);
    $snapshot = $batch->communications()->firstOrFail()->context_snapshot_ciphertext;
    expect($snapshot['issues'])->toContain('preference_unknown', 'required_source_data')->and($snapshot['values']['outstanding_amount'])->toBeNull()->and($snapshot['values']['due_date'])->toBeNull();
});

test('duplicate contacts dedupe by destination and shared customer contexts exclude the whole group', function (): void {
    [$actor,$task,$contact] = recipientFixture();
    allowedRecipient($actor, $task, $contact);
    $template = recipientTemplate($actor);
    $duplicate = Contact::factory()->create(['debtor_id' => $task->customer_id, 'kind_code' => 'email', 'value_ciphertext' => $contact->value_ciphertext, 'value_fingerprint' => $contact->value_fingerprint, 'is_primary' => false]);
    $input = recipientSelection($task, $contact, $template, ['mode' => 'bulk', 'rows' => [['contact' => $contact->public_id, 'account' => $task->account->public_id], ['contact' => $duplicate->public_id, 'account' => $task->account->public_id]]]);
    $batch = app(PreviewRecipients::class)->create($actor, $input, (string) Str::uuid7());
    expect($batch->counts['duplicate'])->toBe(1)->and($batch->counts['selected'])->toBe(2);
    $foreignTask = Task::factory()->create(['assignment_batch_id' => $task->assignment_batch_id, 'debt_account_id' => $task->debt_account_id]);
    $shared = Contact::factory()->create(['debtor_id' => $foreignTask->customer_id, 'kind_code' => 'email', 'value_ciphertext' => $contact->value_ciphertext, 'value_fingerprint' => $contact->value_fingerprint]);
    $input['rows'][1] = ['contact' => $shared->public_id, 'account' => $task->account->public_id];
    $conflict = app(PreviewRecipients::class)->create($actor, $input, (string) Str::uuid7());
    expect($conflict->counts['conflict'])->toBe(2)->and($conflict->counts['eligible'])->toBe(0);
});

test('new restriction makes a preview stale while original content and decisions remain immutable', function (): void {
    [$actor,$task,$contact] = recipientFixture();
    $pref = allowedRecipient($actor, $task, $contact);
    $template = recipientTemplate($actor);
    $preview = app(PreviewRecipients::class);
    $batch = $preview->create($actor, recipientSelection($task, $contact, $template), (string) Str::uuid7());
    $message = $batch->communications()->firstOrFail();
    app(ManagePreferences::class)->request($actor, $task->customer->public_id, permissionInput($task, $contact, ['state' => 'blocked']), (string) Str::uuid7());
    expect($preview->freshness($actor, $batch)['fresh'])->toBeFalse();
    expect(fn () => DB::transaction(fn () => DB::table('communications')->where('id', $message->id)->update(['body_ciphertext' => 'Overwritten'])))->toThrow(QueryException::class);
    expect(fn () => DB::transaction(fn () => DB::table('communication_preferences')->where('id', $pref->id)->delete()))->toThrow(QueryException::class);
    $preview->cancel($actor, $batch, 1);
    expect($batch->refresh()->status)->toBe('cancelled')->and($message->refresh()->delivery_status)->toBe('cancelled')->and($message->body_ciphertext)->toContain($task->customer->display_name_ciphertext);
});

test('unknown contacts cannot create previews', function (): void {
    [$actor,$task,$contact] = recipientFixture();
    $template = recipientTemplate($actor);
    $input = recipientSelection($task, $contact, $template, ['rows' => [['contact' => (string) Str::uuid7()]]]);
    expect(fn () => app(PreviewRecipients::class)->create($actor, $input, (string) Str::uuid7()))->toThrow(HttpException::class);
});

test('permission lineage and conversation contacts cannot cross customer boundaries', function (): void {
    [$actor, $task, $contact] = recipientFixture();
    $pref = allowedRecipient($actor, $task, $contact);
    $foreign = Contact::factory()->create();
    expect(fn () => DB::transaction(fn () => Conversation::factory()->create(['customer_id' => $task->customer_id, 'contact_id' => $foreign->id])))->toThrow(QueryException::class);
    expect(fn () => DB::transaction(fn () => CommunicationPreference::factory()->create(['supersedes_id' => $pref->id])))->toThrow(QueryException::class);
});

test('recipient components expose validation and preserve the reviewed preview workflow', function (): void {
    [$actor, $task, $contact] = recipientFixture();
    $this->actingAs($actor);
    Livewire\Livewire::test(Preferences::class, ['customer' => $task->customer])
        ->set('form', permissionInput($task, $contact))->call('save')->assertHasNoErrors()->assertSee('Pending');
    $pref = CommunicationPreference::query()->firstOrFail();
    Livewire\Livewire::test(Preferences::class, ['customer' => $task->customer])
        ->set('reviewReasons.'.$pref->public_id, 'Synthetic reviewed evidence')->call('review', $pref->public_id, 'approved', 0)->assertHasNoErrors();
    $template = recipientTemplate($actor);
    Livewire\Livewire::test(Recipients::class)
        ->set('template', $template->public_id)
        ->set('selected', [$contact->public_id])->set('contexts', [$contact->public_id => ['account' => $task->account->public_id]])
        ->call('savePreview')->assertHasNoErrors()->assertRedirect();
    $batch = CommunicationBatch::query()->firstOrFail();
    Livewire\Livewire::test(Show::class, ['batch' => $batch])
        ->call('recheck')->assertSee('Sources still match this preview.')->call('cancel', 1)->assertHasNoErrors()->assertSee('Cancelled');
    Livewire\Livewire::test(Recipients::class)
        ->set('selected', [$contact->public_id])->set('contexts', [$contact->public_id => 'tampered'])
        ->call('savePreview')->assertHasErrors(['contexts.'.$contact->public_id]);
});
