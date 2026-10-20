<?php

namespace App\Actions\Communications;

use App\Models\Communication;
use App\Models\CommunicationEvent;
use App\Models\CommunicationTemplate;
use App\Models\Document;
use App\Models\OutboxEvent;
use App\Models\User;
use App\Services\AuditWriter;
use App\Services\Communications\LetterAccess;
use App\Services\Communications\LetterPdf;
use App\Services\Communications\TemplateContext;
use App\Services\Communications\TemplateRenderer;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/** Plan M14 / Module M14 / UI64–67 / communications + documents: private letters only. */
class ManageLetters
{
    public function __construct(private LetterAccess $access, private TemplateContext $context, private TemplateRenderer $renderer, private LetterPdf $pdf, private AuditWriter $audit) {}

    private function plan(User $actor, string $templateId, array $selection): array
    {
        $this->access->authorize($actor, true);
        $selection = Validator::make($selection, ['customer' => ['required', 'uuid'], 'account' => ['present', 'nullable', 'uuid'], 'task' => ['present', 'nullable', 'uuid']])->validate();
        $template = CommunicationTemplate::query()->where('public_id', $templateId)->where('channel', 'email')->where('status', 'approved')->first();
        if (! $template) {
            throw ValidationException::withMessages(['letter' => 'Select a currently approved template version.']);
        }
        $source = $this->context->values($actor, $selection['customer'], $selection['account'] ?? '', $selection['task'] ?? '');
        if ($source['customer']->status !== 'active') {
            throw ValidationException::withMessages(['letter' => 'Select an active customer.']);
        }
        $rendered = $this->renderer->render($template, $source['values']);
        if (preg_match('/\{\{|\}\}|\{!!/u', $rendered['subject'].$rendered['body'])) {
            throw ValidationException::withMessages(['letter' => 'Unresolved placeholder syntax remains in the rendered letter. Review the source data.']);
        }
        $snapshot = ['selection' => $selection, 'template_version' => $template->version, 'customer_revision' => $source['customer']->revision(), 'account_revision' => $source['account']?->revision(), 'task_version' => $source['task']?->lock_version, 'values' => $source['values'], 'sources' => $source['sources']];

        return ['template' => $template, 'customer' => $source['customer'], 'task' => $source['task'], 'snapshot' => $snapshot, 'subject' => $rendered['subject'], 'body' => $rendered['body']];
    }

    public function digest(Communication $letter): string
    {
        return hash('sha256', json_encode([$letter->public_id, $letter->template_id, $letter->requested_by, $letter->context_snapshot_ciphertext, $letter->subject_ciphertext, $letter->body_ciphertext], JSON_THROW_ON_ERROR));
    }

    public function preview(User $actor, string $template, array $selection, string $requestKey): Communication
    {
        $this->access->authorize($actor, true);
        Validator::make(['request' => $requestKey], ['request' => ['required', 'uuid']])->validate();

        return DB::transaction(function () use ($actor, $template, $selection, $requestKey): Communication {
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', ['letter-preview:'.$requestKey]);
            if ($existing = Communication::query()->where('idempotency_key', $requestKey)->first()) {
                $existing = $this->access->letter($actor, $existing->id, true);
                abort_unless($existing->requested_by === $actor->id && $existing->template->public_id === $template && $existing->context_snapshot_ciphertext['selection'] === $selection, 409);

                return $existing;
            }
            $plan = $this->plan($actor, $template, $selection);
            $letter = new Communication;
            $letter->forceFill(['customer_id' => $plan['customer']->id, 'task_id' => $plan['task']?->id, 'template_id' => $plan['template']->id, 'requested_by' => $actor->id, 'channel' => 'letter', 'direction' => 'outgoing', 'delivery_status' => 'draft', 'subject_ciphertext' => $plan['subject'], 'body_ciphertext' => $plan['body'], 'context_snapshot_ciphertext' => $plan['snapshot'], 'idempotency_key' => $requestKey])->save();
            $this->audit->record($actor, 'letter.previewed', $letter->public_id, ['status' => 'draft'], module: 'M14', entityType: 'communication');

            return $letter;
        });
    }

    public function confirm(User $actor, int $id, string $digest): Communication
    {
        return DB::transaction(function () use ($actor, $id, $digest): Communication {
            $letter = Communication::query()->lockForUpdate()->findOrFail($id);
            $this->access->letter($actor, $id, true);
            abort_unless($letter->requested_by === $actor->id, 403);
            if (! hash_equals($this->digest($letter), $digest)) {
                throw ValidationException::withMessages(['letter' => 'The letter preview changed. Review it again.']);
            }
            if (in_array($letter->delivery_status, ['queued', 'generated'], true)) {
                return $letter;
            }
            if (! in_array($letter->delivery_status, ['draft', 'failed'], true)) {
                throw ValidationException::withMessages(['letter' => 'This letter cannot be queued. Create a new preview.']);
            }
            $plan = $this->plan($actor, $letter->template->public_id, $letter->context_snapshot_ciphertext['selection']);
            if ($plan['snapshot'] !== $letter->context_snapshot_ciphertext || $plan['subject'] !== $letter->subject_ciphertext || $plan['body'] !== $letter->body_ciphertext) {
                throw ValidationException::withMessages(['letter' => 'Customer, account or task data changed. Create and review a new preview.']);
            }
            $retry = $letter->delivery_status === 'failed';
            $letter->forceFill(['delivery_status' => 'queued', 'failure_code' => null])->save();
            $event = new OutboxEvent;
            $event->forceFill(['event_key' => (string) Str::uuid7(), 'event_type' => 'letter.generate', 'aggregate_type' => 'communication', 'aggregate_id' => $letter->id, 'payload' => [], 'available_at' => now()])->save();
            $this->event($letter, $retry ? 'letter_retried' : 'letter_queued');
            $this->audit->record($actor, $retry ? 'letter.retried' : 'letter.confirmed', $letter->public_id, ['status' => 'queued'], module: 'M14', entityType: 'communication');

            return $letter;
        });
    }

    private function event(Communication $letter, string $code): void
    {
        $event = new CommunicationEvent;
        $event->forceFill(['communication_id' => $letter->id, 'event_code' => $code, 'occurred_at' => now(), 'received_at' => now(), 'details' => []])->save();
    }

    public function generate(int $id): void
    {
        $letter = Communication::query()->where('channel', 'letter')->findOrFail($id);
        if ($letter->delivery_status !== 'queued') {
            return;
        }
        if (! $this->canGenerate($letter)) {
            $this->terminal($id, 'cancelled', 'letter_context_changed');

            return;
        }
        $object = 'letters/'.$letter->public_id.'/'.Str::uuid7().'.pdf.enc';
        $stored = false;
        try {
            $bytes = $this->pdf->render($letter);
            if (! Storage::disk('s3')->put($object, Crypt::encryptString($bytes), ['visibility' => 'private'])) {
                throw new RuntimeException('letter_storage_unavailable');
            }
            $stored = true;
            $committed = DB::transaction(function () use ($id, $object, $bytes): bool {
                $letter = Communication::query()->lockForUpdate()->findOrFail($id);
                if ($letter->delivery_status !== 'queued') {
                    return false;
                }
                if (! $this->canGenerate($letter)) {
                    $letter->forceFill(['delivery_status' => 'cancelled', 'failure_code' => 'letter_context_changed'])->save();
                    $this->event($letter, 'letter_cancelled');

                    return false;
                }
                $document = new Document;
                $document->forceFill(['owner_type' => 'communication', 'owner_id' => $letter->id, 'uploaded_by' => $letter->requested_by, 'disk' => 's3', 'bucket' => config('filesystems.disks.s3.bucket'), 'object_key' => $object, 'original_filename' => 'letter-'.$letter->public_id.'.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes), 'document_type_code' => 'letter_pdf', 'scan_status' => 'clean'])->save();
                $letter->forceFill(['delivery_status' => 'generated', 'failure_code' => null])->save();
                $this->event($letter, 'letter_generated');
                $this->audit->record(User::query()->findOrFail($letter->requested_by), 'letter.generated', $letter->public_id, ['status' => 'generated'], module: 'M14', entityType: 'communication');

                return true;
            });
            if ($committed) {
                $stored = false;
            }
        } catch (\Throwable) {
            throw new RuntimeException('Private letter generation is temporarily unavailable.');
        } finally {
            if ($stored) {
                try {
                    Storage::disk('s3')->delete($object);
                } catch (\Throwable) {
                    Log::warning('letter_orphan_cleanup_required', ['communication_id' => $id]);
                }
            }
        }
    }

    private function canGenerate(Communication $letter): bool
    {
        try {
            $actor = User::query()->findOrFail($letter->requested_by);
            $this->access->letter($actor, $letter->id, true);
            $plan = $this->plan($actor, $letter->template->public_id, $letter->context_snapshot_ciphertext['selection']);

            return $plan['snapshot'] === $letter->context_snapshot_ciphertext && $plan['subject'] === $letter->subject_ciphertext && $plan['body'] === $letter->body_ciphertext;
        } catch (ValidationException|AuthorizationException|ModelNotFoundException|\Symfony\Component\HttpKernel\Exception\HttpException) {
            return false;
        }
    }

    public function terminal(int $id, string $status = 'failed', string $code = 'generation_unavailable', ?int $eventId = null): void
    {
        DB::transaction(function () use ($id, $status, $code, $eventId): void {
            $letter = Communication::query()->where('channel', 'letter')->lockForUpdate()->findOrFail($id);
            if ($eventId !== null && OutboxEvent::query()->where('event_type', 'letter.generate')->where('aggregate_id', $id)->latest('id')->value('id') !== $eventId) {
                return;
            }
            if ($letter->delivery_status === 'queued') {
                $letter->forceFill(['delivery_status' => $status, 'failure_code' => $code])->save();
                $this->event($letter, 'letter_'.$status);
                $this->audit->record(null, 'letter.'.$status, $letter->public_id, ['status' => $status], module: 'M14', entityType: 'communication');
            }
        });
    }

    public function bytes(Document $document): string
    {
        try {
            $bytes = Crypt::decryptString(Storage::disk('s3')->get($document->object_key));
            if (! hash_equals($document->sha256, hash('sha256', $bytes)) || strlen($bytes) !== $document->size_bytes || ! str_starts_with($bytes, '%PDF-')) {
                throw new RuntimeException('letter_integrity_failed');
            }

            return $bytes;
        } catch (\Throwable) {
            throw new RuntimeException('Private letter file is temporarily unavailable.');
        }
    }
}
