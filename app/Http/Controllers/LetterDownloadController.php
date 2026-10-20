<?php

namespace App\Http\Controllers;

use App\Actions\Communications\ManageLetters;
use App\Models\Communication;
use App\Services\AuditWriter;
use App\Services\Communications\LetterAccess;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** UI64/67: expiring app URL; signature never replaces live authorization. */
class LetterDownloadController extends Controller
{
    public function __invoke(Request $request, Communication $letter, LetterAccess $access, ManageLetters $letters, AuditWriter $audit): Response
    {
        abort_unless((string) $request->query('user') === (string) $request->user()->id, 403);
        $letter = $access->letter($request->user(), $letter->id);
        abort_unless($letter->delivery_status === 'generated', 404);
        $document = $letter->documents()->where('document_type_code', 'letter_pdf')->where('scan_status', 'clean')->firstOrFail();
        try {
            $bytes = $letters->bytes($document);
        } catch (\RuntimeException) {
            abort(503, 'Private letter file is temporarily unavailable.');
        }
        $audit->record($request->user(), 'letter.downloaded', $letter->public_id, ['status' => 'generated'], module: 'M14', entityType: 'communication');

        return response($bytes, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="letter-'.$letter->public_id.'.pdf"', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'none'; sandbox"]);
    }
}
