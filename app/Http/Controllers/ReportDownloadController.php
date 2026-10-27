<?php

namespace App\Http\Controllers;

use App\Actions\Reports\ManageReports;
use App\Models\ExportJob;
use App\Services\AuditWriter;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ReportDownloadController extends Controller
{
    public function __invoke(Request $request, ExportJob $exportJob, ManageReports $reports, AuditWriter $audit): Response
    {
        abort_unless((string) $request->query('user') === (string) $request->user()->id, 403);
        $reports->access($request->user(), $exportJob);
        abort_unless($exportJob->status === 'completed', 404);
        $document = $exportJob->artifact;
        abort_unless($document && ! $document->trashed() && $document->owner_type === 'export' && $document->owner_id === $exportJob->id, 404);
        try {
            $bytes = $reports->bytes($document);
        } catch (\Throwable) {
            abort(503, 'Private report file is temporarily unavailable.');
        }
        $reports->access($request->user(), $exportJob);
        $audit->record($request->user(), 'report.downloaded', $exportJob->public_id, ['status' => 'completed'], module: 'M09', entityType: 'export_job');

        return response($bytes, 200, ['Content-Type' => $document->mime_type, 'Content-Disposition' => 'attachment; filename="'.$document->original_filename.'"', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'none'; sandbox"]);
    }
}
