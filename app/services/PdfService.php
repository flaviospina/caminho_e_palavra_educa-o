<?php
// app/services/PdfService.php
// Geração de PDF de texto (selecionável) com mPDF, usando as fontes DejaVu
// versionadas em templates/fonts (a pasta ttfonts do mPDF não é versionada).
declare(strict_types=1);

namespace App\Services;

use Mpdf\Mpdf;
use Mpdf\Output\Destination;

final class PdfService
{
    public function create(string $format = 'A4'): Mpdf
    {
        $tmp = STORAGE_PATH . '/cache/mpdf';
        if (!is_dir($tmp)) {
            @mkdir($tmp, 0755, true);
        }
        return new Mpdf([
            'mode'         => 'utf-8',
            'format'       => $format,
            'tempDir'      => $tmp,
            'fontDir'      => [ROOT_PATH . '/templates/fonts'],
            'fontdata'     => ['dejavusans' => ['R' => 'DejaVuSans.ttf', 'B' => 'DejaVuSans-Bold.ttf']],
            'default_font' => 'dejavusans',
            'margin_left'  => 18, 'margin_right' => 18, 'margin_top' => 18, 'margin_bottom' => 18,
        ]);
    }

    /** HTML → bytes do PDF. */
    public function fromHtml(string $html, string $title = ''): string
    {
        $pdf = $this->create();
        $pdf->SetTitle($title);
        $pdf->SetAuthor(APP_NAME);
        $pdf->SetCreator(APP_NAME);
        $pdf->WriteHTML($html);
        return $pdf->Output('', Destination::STRING_RETURN);
    }
}
