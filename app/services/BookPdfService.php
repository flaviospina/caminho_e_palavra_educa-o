<?php
// app/services/BookPdfService.php
// Monta o PDF da apostila no servidor (mPDF) com as páginas geradas na ordem
// escolhida, capa sempre primeiro, cada imagem ocupando uma página A4 com um
// rodapé discreto. É a alternativa à montagem no navegador (pdf-lib).
declare(strict_types=1);

namespace App\Services;

use App\Models\Page;
use App\Models\Project;
use InvalidArgumentException;
use Mpdf\Output\Destination;

final class BookPdfService
{
    /** Ordem válida: capa (0) primeiro quando existir, demais páginas prontas sem repetição. @return int[] */
    public static function normalizeOrder(array $pages, array $requested): array
    {
        $prontas = [];
        foreach ($pages as $p) {
            if (in_array($p['status'], ['pronto', 'revisar'], true) && Page::imageFile($p) !== null) {
                $prontas[(int) $p['number']] = $p;
            }
        }
        if ($prontas === []) {
            throw new InvalidArgumentException('Nenhuma página gerada ainda. Gere as imagens antes de montar o PDF.');
        }
        $ordem = [];
        foreach ($requested as $n) {
            if (isset($prontas[$n]) && !in_array($n, $ordem, true)) {
                $ordem[] = $n;
            }
        }
        foreach (array_keys($prontas) as $n) {
            if (!in_array($n, $ordem, true)) {
                $ordem[] = $n;
            }
        }
        if (isset($prontas[0])) {
            $ordem = array_values(array_unique(array_merge([0], array_diff($ordem, [0]))));
        }
        return $ordem;
    }

    /** @param int[] $requested */
    public function build(array $project, array $requested): string
    {
        $pages = Page::forProject((int) $project['id']);
        $ordem = self::normalizeOrder($pages, $requested);
        $byNumber = [];
        foreach ($pages as $p) {
            $byNumber[(int) $p['number']] = $p;
        }
        $pdf = (new PdfService())->create('A4');
        $pdf->SetTitle((string) $project['title']);
        $pdf->SetAuthor(APP_NAME);
        $pdf->SetMargins(0, 0, 0);
        $pdf->SetAutoPageBreak(false);
        $total = count($ordem);
        foreach ($ordem as $i => $n) {
            $file = Page::imageFile($byNumber[$n]);
            $pdf->AddPage();   // o mPDF não abre página sozinha para Image(): sem isto, o conteúdo sairia antes do cabeçalho %PDF
            // A4 = 210 × 297 mm; a imagem 2:3 ocupa a largura inteira (210 × 315 → cortada no rodapé) ou cabe na altura
            $pdf->Image($file, 0, 0, 210, 297, 'png', '', true, false);
            $rotulo = ($n === 0 ? 'Capa' : 'Página ' . $i . ' de ' . ($total - (isset($byNumber[0]) ? 1 : 0))) . ' · ' . mb_substr((string) $project['title'], 0, 60) . ' · projeto ' . (int) $project['id'];
            $pdf->SetFont('dejavusans', '', 6.5);
            $pdf->SetTextColor(100, 116, 139);
            $pdf->SetXY(0, 292);
            $pdf->Cell(210, 4, $rotulo, 0, 0, 'C');
        }
        return $pdf->Output('', Destination::STRING_RETURN);
    }
}
