<?php

use App\Actions\Communications\ManageLetters;
use App\Actions\Communications\ManageTemplates;
use App\Jobs\GenerateLetter;
use App\Livewire\Admin\Communications\Letters;
use App\Models\Communication;
use App\Models\CommunicationAttempt;
use App\Models\CommunicationEvent;
use App\Models\CommunicationTemplate;
use App\Models\Debtor;
use App\Models\Document;
use App\Models\OutboxEvent;
use App\Services\Communications\LetterPdf;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(LazilyRefreshDatabase::class);
beforeEach(function (): void {
    $this->withoutVite();
    $this->seed(DatabaseSeeder::class);
    config(['filesystems.disks.s3.bucket' => 'synthetic-private']);
});

function letterFixture(): array
{
    $actor = staffManager();
    $actor->givePermissionTo(['communications.generate_letter', 'templates.approve']);
    $customer = Debtor::factory()->create();
    $source = CommunicationTemplate::query()->where('email_slot', 1)->where('status', 'approved')->firstOrFail();
    $manage = app(ManageTemplates::class);
    $draft = $manage->createVersion($actor, $source);
    $draft = $manage->save($actor, $draft, 0, ['name' => 'Synthetic letter wording', 'subject' => 'Notice for {{customer_name}}', 'body' => "Dear {{customer_name}},\nPlease contact {{company_name}}.", 'required' => ['customer_name', 'company_name']]);
    $template = $manage->approve($actor, $manage->submit($actor, $draft, 1), 2);
    $selection = ['customer' => $customer->public_id, 'account' => '', 'task' => ''];

    return [$actor, $customer, $template, $selection];
}

function letterPreview(array $fixture): Communication
{
    return app(ManageLetters::class)->preview($fixture[0], $fixture[2]->public_id, $fixture[3], (string) Str::uuid7());
}

function letterQueue(array $fixture): Communication
{
    $letters = app(ManageLetters::class);
    $letter = letterPreview($fixture);

    return $letters->confirm($fixture[0], $letter->id, $letters->digest($letter));
}

test('letters require authentication and separate generation permission', function (): void {
    $this->get(route('admin.communications.letters'))->assertRedirect(route('login'));
    $fixture = letterFixture();
    [$actor, $customer, $template] = $fixture;
    $this->actingAs($actor)->get(route('admin.communications.letters'))->assertOk()->assertSee('Private letters');
    $actor->revokePermissionTo('communications.generate_letter');
    Livewire::actingAs($actor)->test(Letters::class)->set('customerId', $customer->public_id)->set('templateId', $template->public_id)->call('preview')->assertForbidden();
    expect(Communication::query()->count())->toBe(0);
    $actor->givePermissionTo('communications.generate_letter');
    $fixture[3]['customer'] = (string) Str::uuid7();
    expect(fn () => letterPreview($fixture))->toThrow(ModelNotFoundException::class);
});

test('default letters block missing financial source values without creating artifacts', function (): void {
    $fixture = letterFixture();
    $fixture[2] = CommunicationTemplate::query()->where('email_slot', 2)->where('status', 'approved')->firstOrFail();
    expect(fn () => letterPreview($fixture))->toThrow(ValidationException::class, 'outstanding_amount');
    expect(Communication::query()->count())->toBe(0)->and(Document::query()->count())->toBe(0)->and(OutboxEvent::query()->where('event_type', 'letter.generate')->count())->toBe(0);
});

test('letter preview is encrypted idempotent and confirmation queues exactly one durable work item', function (): void {
    $fixture = letterFixture();
    $letters = app(ManageLetters::class);
    $key = (string) Str::uuid7();
    $letter = $letters->preview($fixture[0], $fixture[2]->public_id, $fixture[3], $key);
    expect($letters->preview($fixture[0], $fixture[2]->public_id, $fixture[3], $key)->id)->toBe($letter->id);
    expect(DB::table('communications')->where('id', $letter->id)->value('body_ciphertext'))->not->toContain('Please contact');
    expect($letter->delivery_status)->toBe('draft');
    $digest = $letters->digest($letter);
    $letters->confirm($fixture[0], $letter->id, $digest);
    $letters->confirm($fixture[0], $letter->id, $digest);
    expect($letter->refresh()->delivery_status)->toBe('queued')->and(OutboxEvent::query()->where('event_type', 'letter.generate')->count())->toBe(1)->and(CommunicationAttempt::query()->count())->toBe(0);
});

test('confirmation rejects changed content digest or customer source revision', function (): void {
    $fixture = letterFixture();
    $letters = app(ManageLetters::class);
    $letter = letterPreview($fixture);
    expect(fn () => $letters->confirm($fixture[0], $letter->id, str_repeat('0', 64)))->toThrow(ValidationException::class, 'preview changed');
    $fixture[1]->display_name_ciphertext = 'Changed source identity';
    $fixture[1]->save();
    expect(fn () => $letters->confirm($fixture[0], $letter->id, $letters->digest($letter)))->toThrow(ValidationException::class, 'data changed');
    expect($letter->refresh()->delivery_status)->toBe('draft');
});

test('pdf generation persists one encrypted private artifact and does not create email delivery facts', function (): void {
    Storage::fake('s3');
    $fixture = letterFixture();
    $letter = letterQueue($fixture);
    $letters = app(ManageLetters::class);
    $letters->generate($letter->id);
    $letters->generate($letter->id);
    $document = $letter->documents()->sole();
    expect($letters->bytes($document))->toStartWith('%PDF-')->and(Storage::disk('s3')->get($document->object_key))->not->toStartWith('%PDF-');
    expect($letter->refresh()->delivery_status)->toBe('generated')->and($letter->sent_at)->toBeNull()->and($letter->sent_by)->toBeNull()->and($letter->integration_account_id)->toBeNull();
    expect(CommunicationEvent::query()->where('event_code', 'letter_generated')->count())->toBe(1)->and(CommunicationAttempt::query()->count())->toBe(0);
    expect($document->owner->id)->toBe($letter->id);
});

test('worker cancels queued letters when requesters lose access or templates are archived', function (string $condition): void {
    Storage::fake('s3');
    $fixture = letterFixture();
    $letter = letterQueue($fixture);
    if ($condition === 'permission') {
        $fixture[0]->syncRoles([]);
    } else {
        app(ManageTemplates::class)->archive($fixture[0], $fixture[2], $fixture[2]->lock_version);
    }
    app(ManageLetters::class)->generate($letter->id);
    expect($letter->refresh()->delivery_status)->toBe('cancelled')->and(Document::query()->count())->toBe(0);
    Storage::disk('s3')->assertDirectoryEmpty('letters');
})->with(['permission', 'template']);

test('failed generation is retryable without replacing content or hiding failure history', function (): void {
    Storage::fake('s3');
    $fixture = letterFixture();
    $letter = letterQueue($fixture);
    $this->mock(LetterPdf::class)->shouldReceive('render')->once()->andThrow(new RuntimeException('Private customer detail'));
    $letters = app(ManageLetters::class);
    expect(fn () => $letters->generate($letter->id))->toThrow(RuntimeException::class, 'temporarily unavailable');
    $letters->terminal($letter->id);
    expect($letter->refresh()->failure_code)->toBe('generation_unavailable');
    $letters->confirm($fixture[0], $letter->id, $letters->digest($letter));
    expect($letter->refresh()->delivery_status)->toBe('queued')->and(CommunicationEvent::query()->where('event_code', 'letter_failed')->count())->toBe(1)->and(OutboxEvent::query()->where('event_type', 'letter.generate')->count())->toBe(2);
});

test('signed downloads enforce user binding expiry and private artifact integrity', function (): void {
    Storage::fake('s3');
    $fixture = letterFixture();
    $letter = letterQueue($fixture);
    app(ManageLetters::class)->generate($letter->id);
    $url = URL::temporarySignedRoute('admin.communications.letters.download', now()->addMinutes(5), ['letter' => $letter->public_id, 'user' => $fixture[0]->id]);
    $this->actingAs($fixture[0])->get($url)->assertOk()->assertHeader('Content-Type', 'application/pdf')->assertHeader('Cache-Control', 'no-store, private');
    $this->get(route('admin.communications.letters.download', $letter))->assertForbidden();
    $this->actingAs(staffManager())->get($url)->assertForbidden();
    $this->actingAs($fixture[0]);
    $document = $letter->documents()->sole();
    Storage::disk('s3')->put($document->object_key, Crypt::encryptString('%PDF-tampered'));
    $this->get($url)->assertStatus(503);
    $this->travel(6)->minutes();
    $this->get($url)->assertForbidden();
});

test('database guards prevent rewriting frozen letters and manufacturing generated state', function (): void {
    $fixture = letterFixture();
    $letter = letterPreview($fixture);
    expect(fn () => DB::transaction(fn () => $letter->forceFill(['body_ciphertext' => 'Rewritten'])->save()))->toThrow(QueryException::class);
    $letter->refresh();
    app(ManageLetters::class)->confirm($fixture[0], $letter->id, app(ManageLetters::class)->digest($letter));
    expect(fn () => DB::transaction(fn () => $letter->refresh()->forceFill(['delivery_status' => 'generated'])->save()))->toThrow(QueryException::class);
});

test('dispatcher and job replay preserve exactly one artifact after durable publication', function (): void {
    Storage::fake('s3');
    Queue::fake([GenerateLetter::class]);
    $fixture = letterFixture();
    $letter = letterQueue($fixture);
    $this->artisan('communications:dispatch-letters')->assertSuccessful();
    Queue::assertPushed(GenerateLetter::class, 1);
    $event = OutboxEvent::query()->where('event_type', 'letter.generate')->sole();
    expect($event->dispatched_at)->not->toBeNull();
    $job = new GenerateLetter($event->id);
    $job->handle(app(ManageLetters::class));
    $job->handle(app(ManageLetters::class));
    expect($letter->documents()->count())->toBe(1);
});

test('letter PDF treats template text as literal content and paginates long Unicode correspondence', function (): void {
    $fixture = letterFixture();
    $manager = app(ManageTemplates::class);
    $draft = $manager->createVersion($fixture[0], $fixture[2]);
    $body = "Dear {{customer_name}},\n\n".str_repeat("Account review — café, payment enquiries and supporting documents.\n", 105)."\n<script>alert('literal')</script> <img src=\"https://example.invalid/private\">\nRegards, {{company_name}}";
    $saved = $manager->save($fixture[0], $draft, 0, ['name' => 'Synthetic multipage letter', 'subject' => 'Unicode correspondence — {{customer_name}}', 'body' => $body, 'required' => ['customer_name', 'company_name']]);
    $fixture[2] = $manager->approve($fixture[0], $manager->submit($fixture[0], $saved, 1), 2);
    $letter = letterPreview($fixture);
    $html = view('livewire.admin.communications.letter-pdf', ['letter' => $letter, 'logo' => 'data:image/png;base64,'])->render();
    expect($html)->toContain('&lt;script&gt;')->not->toContain('<script>');
    $bytes = app(LetterPdf::class)->render($letter);
    expect($bytes)->toStartWith('%PDF-');
    if (is_dir(base_path('.local-tools'))) {
        file_put_contents(base_path('.local-tools/letters-synthetic-multipage.pdf'), $bytes);
    }
});

test('private storage failure never marks a letter generated or exposes provider details', function (): void {
    $fixture = letterFixture();
    $letter = letterQueue($fixture);
    $this->mock(LetterPdf::class)->shouldReceive('render')->once()->andReturn('%PDF-synthetic-storage-test');
    Storage::shouldReceive('disk')->with('s3')->once()->andReturn(Mockery::mock()->shouldReceive('put')->once()->andReturn(false)->getMock());
    expect(fn () => app(ManageLetters::class)->generate($letter->id))->toThrow(RuntimeException::class, 'temporarily unavailable');
    expect($letter->refresh()->delivery_status)->toBe('queued')->and($letter->documents()->count())->toBe(0);
    $event = OutboxEvent::query()->where('event_type', 'letter.generate')->sole();
    (new GenerateLetter($event->id))->failed(new RuntimeException('Private provider detail'));
    expect($letter->refresh()->delivery_status)->toBe('failed')->and($letter->failure_code)->toBe('generation_unavailable');
});

test('letter envelopes cannot be changed into email envelopes to bypass retention', function (): void {
    $fixture = letterFixture();
    $letter = letterPreview($fixture);
    expect(fn () => DB::transaction(fn () => $letter->forceFill(['channel' => 'email', 'requested_by' => null, 'body_ciphertext' => 'Replaced content'])->save()))->toThrow(QueryException::class);
});

test('worker cancels stale source and old failure callbacks cannot fail a newer retry', function (): void {
    $fixture = letterFixture();
    $letter = letterQueue($fixture);
    $oldEvent = OutboxEvent::query()->where('event_type', 'letter.generate')->sole();
    $letters = app(ManageLetters::class);
    $letters->terminal($letter->id);
    $letters->confirm($fixture[0], $letter->id, $letters->digest($letter));
    (new GenerateLetter($oldEvent->id))->failed(new RuntimeException('Old worker timeout'));
    expect($letter->refresh()->delivery_status)->toBe('queued');
    $fixture[1]->display_name_ciphertext = 'Updated after confirmation';
    $fixture[1]->save();
    $letters->generate($letter->id);
    expect($letter->refresh()->delivery_status)->toBe('cancelled')->and($letter->documents()->count())->toBe(0);
});
