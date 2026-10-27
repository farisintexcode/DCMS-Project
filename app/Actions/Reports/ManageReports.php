<?php

namespace App\Actions\Reports;

use App\Models\Document;
use App\Models\ExportJob;
use App\Models\OutboxEvent;
use App\Models\User;
use App\Services\AuditWriter;
use App\Services\Reports\ReportRenderer;
use App\Services\Reports\TaskReports;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class ManageReports
{
    public function __construct(private TaskReports $reports, private AuditWriter $audit) {}

    public function request(User $actor, array $criteria, string $kind): ExportJob
    {
        $this->reports->authorize($actor, true);
        abort_unless(in_array($kind, ['csv', 'pdf'], true), 422);
        $snapshot = $this->reports->snapshot($actor, $criteria);

        return DB::transaction(function () use ($actor, $snapshot, $kind): ExportJob {
            $job = new ExportJob;
            $job->forceFill(['requested_by' => $actor->id, 'kind' => $kind, 'report_family' => $snapshot['criteria']['family'], 'criteria' => $snapshot['criteria'], 'snapshot_at' => $snapshot['as_of'], 'snapshot_ciphertext' => $snapshot, 'snapshot_digest' => hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR)), 'status' => 'queued', 'row_count' => count($snapshot['rows']), 'expires_at' => now()->addHours(config('reports.expires_hours'))])->save();
            $this->publish($job);
            $this->audit->record($actor, 'report.requested', $job->public_id, ['status' => 'queued'], module: 'M09', entityType: 'export_job');

            return $job;
        });
    }

    private function publish(ExportJob $job): void
    {
        $event = new OutboxEvent;
        $event->forceFill(['event_key' => (string) Str::uuid7(), 'event_type' => 'report.generate', 'aggregate_type' => 'export_job', 'aggregate_id' => $job->id, 'payload' => [], 'available_at' => now()])->save();
    }

    public function access(User $actor, ExportJob $job): array
    {
        abort_unless($job->requested_by === $actor->id, 404);
        $this->reports->authorize($actor, true);
        abort_if($job->expires_at->isPast(), 410, 'This report has expired. Create a new report.');
        $snapshot = $job->snapshot_ciphertext;
        abort_unless(hash_equals($job->snapshot_digest, hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR))), 409);
        abort_unless($this->reports->stillAccessible($actor, $snapshot), 403);

        return $snapshot;
    }

    public function generate(int $id): void
    {
        $object = null;
        try {
            DB::transaction(function () use ($id, &$object): void {
                $job = ExportJob::query()->lockForUpdate()->findOrFail($id);
                if ($job->status !== 'queued') {
                    return;
                }
                $actor = User::query()->find($job->requested_by);
                if (! $actor || $job->expires_at->isPast() || ! $this->reports->stillAccessible($actor, $job->snapshot_ciphertext)) {
                    $job->forceFill(['status' => $job->expires_at->isPast() ? 'expired' : 'cancelled', 'error_summary' => 'Report authorization or availability changed.'])->save();

                    return;
                }
                $snapshot = $this->access($actor, $job);
                $job->forceFill(['status' => 'running'])->save();
                $bytes = app(ReportRenderer::class)->render($snapshot, $job->kind);
                if (strlen($bytes) > 25 * 1024 * 1024) {
                    throw new RuntimeException('report_too_large');
                }
                // Recheck after rendering, including time-bound scopes.
                $this->access($actor, $job);
                $object = 'reports/'.$job->public_id.'/'.Str::uuid7().'.'.$job->kind.'.enc';
                if (! Storage::disk('s3')->put($object, Crypt::encryptString($bytes), ['visibility' => 'private'])) {
                    throw new RuntimeException('report_storage_unavailable');
                }
                $doc = new Document;
                $doc->forceFill(['owner_type' => 'export', 'owner_id' => $job->id, 'uploaded_by' => $actor->id, 'disk' => 's3', 'bucket' => config('filesystems.disks.s3.bucket'), 'object_key' => $object, 'original_filename' => 'nexora-'.$job->report_family.'-'.$job->public_id.'.'.$job->kind, 'mime_type' => $job->kind === 'csv' ? 'text/csv' : 'application/pdf', 'size_bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes), 'document_type_code' => 'report_'.$job->kind, 'scan_status' => 'clean'])->save();
                $job->forceFill(['status' => 'completed', 'artifact_document_id' => $doc->id, 'error_summary' => null])->save();
                $this->audit->record($actor, 'report.generated', $job->public_id, ['status' => 'completed'], module: 'M09', entityType: 'export_job');
            });
        } catch (\Throwable $error) {
            if ($object) {
                try {
                    Storage::disk('s3')->delete($object);
                } catch (\Throwable) {
                    Log::warning('report_artifact_cleanup_pending', ['export_job_id' => $id]);
                }
            }
            throw new RuntimeException('Report generation is temporarily unavailable.');
        }
    }

    public function failed(int $id): void
    {
        DB::transaction(function () use ($id): void {
            $job = ExportJob::query()->lockForUpdate()->find($id);
            if (! $job || ! in_array($job->status, ['queued', 'running'], true)) {
                return;
            }
            $job->forceFill(['status' => 'failed', 'error_summary' => 'Generation failed. You can retry while the snapshot remains available.'])->save();
            $this->audit->record(User::query()->find($job->requested_by), 'report.failed', $job->public_id, ['status' => 'failed'], module: 'M09', entityType: 'export_job');
        });
    }

    public function retry(User $actor, ExportJob $job): void
    {
        DB::transaction(function () use ($actor, $job): void {
            $job = ExportJob::query()->lockForUpdate()->findOrFail($job->id);
            $this->access($actor, $job);
            abort_unless($job->status === 'failed', 409);
            $job->forceFill(['status' => 'queued', 'error_summary' => null])->save();
            $this->publish($job);
            $this->audit->record($actor, 'report.retried', $job->public_id, ['status' => 'queued'], module: 'M09', entityType: 'export_job');
        });
    }

    public function bytes(Document $doc): string
    {
        $bytes = Crypt::decryptString(Storage::disk('s3')->get($doc->object_key));
        if (! hash_equals($doc->sha256, hash('sha256', $bytes))) {
            throw new RuntimeException('Report integrity check failed.');
        }

        return $bytes;
    }
}
