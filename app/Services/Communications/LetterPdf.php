<?php

namespace App\Services\Communications;

use App\Models\Communication;
use Dompdf\Dompdf;
use Dompdf\Options;
use RuntimeException;

/** M14: only escaped literal text and a bundled logo enter the renderer. */
class LetterPdf
{
    public function render(Communication $letter): string
    {
        $options = new Options;
        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);
        $options->setIsJavascriptEnabled(false);
        $options->setAllowedProtocols(['data://']);
        $options->setChroot(public_path('images/brand'));
        $options->setDefaultFont('DejaVu Sans');
        $pdf = new Dompdf($options);
        $logo = 'data:image/png;base64,'.base64_encode(file_get_contents(public_path(config('dcms.branding.icon'))));
        $pdf->loadHtml(view('livewire.admin.communications.letter-pdf', ['letter' => $letter, 'logo' => $logo])->render(), 'UTF-8');
        $pdf->setPaper('A4');
        $pdf->render();
        $bytes = $pdf->output();
        if (! str_starts_with($bytes, '%PDF-') || strlen($bytes) > 10 * 1024 * 1024) {
            throw new RuntimeException('letter_pdf_invalid');
        }

        return $bytes;
    }
}
