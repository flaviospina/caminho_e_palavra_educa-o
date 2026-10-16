<?php
// app/services/LessonPlanService.php
// Plano de aula em texto, montado a partir do mesmo JSON aprovado, sem chamada
// à IA (sem custo) e sem imagem. Exportável em PDF de texto (mPDF).
declare(strict_types=1);

namespace App\Services;

use App\Ai\ContentPromptBuilder;
use App\Models\BnccSkill;

final class LessonPlanService
{
    /** Estrutura do plano (seções com título e linhas/itens). */
    public function build(array $doc, array $project = []): array
    {
        $etapa = BnccSkill::STAGES[$doc['etapa']] ?? $doc['etapa'];
        $ano = BnccSkill::yearLabel((string) $doc['etapa'], (string) $doc['ano']);
        $paginas = $doc['paginas'] ?? [];
        $abertura = $paginas[0] ?? ['blocos' => []];
        $blocoPor = fn(array $p, string $titulo): ?array => array_values(array_filter($p['blocos'] ?? [], fn($b) => mb_strtolower((string) ($b['titulo'] ?? '')) === mb_strtolower($titulo)))[0] ?? null;

        $habilidades = [];
        foreach ($doc['habilidades'] ?? [] as $h) {
            $skill = BnccSkill::find((string) $h['codigo']);
            $habilidades[] = ['codigo' => $h['codigo'], 'texto' => $skill['description'] ?? $h['resumo'], 'resumo' => $h['resumo'] ?? ''];
        }

        $objetivo = $blocoPor($abertura, 'Objetivo');
        $aprender = $blocoPor($abertura, 'O que você vai aprender');
        $importante = $blocoPor($abertura, 'Por que é importante');

        $momentos = [];
        $minutos = [15, 20, 15];
        foreach ($paginas as $i => $p) {
            $itens = [];
            foreach ($p['blocos'] ?? [] as $b) {
                $linha = $b['titulo'];
                if (!empty($b['texto'])) {
                    $linha .= ': ' . $b['texto'];
                } elseif (!empty($b['itens'])) {
                    $linha .= ': ' . implode('; ', $b['itens']);
                }
                $itens[] = $linha;
            }
            $momentos[] = [
                'titulo'   => 'Momento ' . ($i + 1) . ' — ' . ($p['titulo'] ?? ''),
                'tipo'     => self::tipoLabel((string) ($p['tipo'] ?? '')),
                'duracao'  => ($minutos[$i] ?? 15) . ' min',
                'subtitulo' => $p['subtitulo'] ?? '',
                'itens'    => $itens,
            ];
        }
        $duracaoTotal = array_sum(array_slice($minutos, 0, max(1, count($paginas)))) + (count($paginas) > 3 ? (count($paginas) - 3) * 15 : 0);

        $pratica = null;
        foreach ($paginas as $p) {
            if (($p['tipo'] ?? '') === 'pratica' || ($p['tipo'] ?? '') === 'atividade') {
                $pratica = $p;
            }
        }
        $avaliacao = [];
        if ($pratica !== null) {
            foreach ($pratica['blocos'] ?? [] as $b) {
                $avaliacao[] = $b['titulo'] . (!empty($b['texto']) ? ': ' . $b['texto'] : (!empty($b['itens']) ? ': ' . implode('; ', $b['itens']) : ''));
            }
        }
        if ($avaliacao === []) {
            $avaliacao[] = 'Observação da participação nas conversas e registro das respostas no caderno.';
        }

        return [
            'titulo'        => 'Plano de aula — ' . ($doc['capa']['titulo'] ?? $doc['tema']),
            'identificacao' => [
                'Componente curricular' => $doc['componente'],
                'Etapa / ano'           => $etapa . ' · ' . $ano,
                'Tema'                  => $doc['tema'],
                'Finalidade'            => ContentPromptBuilder::FINALIDADES[$doc['finalidade'] ?? ''] ?? ($doc['finalidade'] ?? ''),
                'Duração sugerida'      => $duracaoTotal . ' minutos (' . max(1, (int) ceil($duracaoTotal / 50)) . ' aula(s) de 50 min)',
                'Escola'                => $project['school_name'] ?? '',
                'Professor(a)'          => $project['user_name'] ?? '',
            ],
            'habilidades'   => $habilidades,
            'objetivos'     => array_values(array_filter([
                $objetivo['texto'] ?? null,
                ...array_map(fn($x) => 'Reconhecer: ' . $x, $aprender['itens'] ?? []),
            ])),
            'justificativa' => $importante['itens'] ?? [],
            'recursos'      => ['Apostila visual gerada no sistema (impressa ou projetada)', 'Quadro e caderno', 'Materiais simples indicados na página de prática'],
            'momentos'      => $momentos,
            'avaliacao'     => $avaliacao,
            'observacoes'   => 'Material elaborado com apoio de inteligência artificial e revisado pelo(a) professor(a). Nenhum dado de aluno é utilizado.',
        ];
    }

    public static function tipoLabel(string $tipo): string
    {
        return match ($tipo) {
            'abertura'  => 'Sensibilização e apresentação',
            'explicacao' => 'Desenvolvimento do conteúdo',
            'pratica', 'atividade' => 'Prática e sistematização',
            'resumo'    => 'Síntese',
            default     => 'Desenvolvimento',
        };
    }

    /** HTML do plano (usado na tela e no PDF). */
    public function html(array $plan): string
    {
        $h = '<h1>' . e($plan['titulo']) . '</h1><table class="ident">';
        foreach ($plan['identificacao'] as $k => $v) {
            if ((string) $v !== '') {
                $h .= '<tr><th>' . e($k) . '</th><td>' . e($v) . '</td></tr>';
            }
        }
        $h .= '</table><h2>Habilidades BNCC</h2><ul>';
        foreach ($plan['habilidades'] as $hab) {
            $h .= '<li><strong>' . e($hab['codigo']) . '</strong> — ' . e($hab['texto']) . '</li>';
        }
        $h .= '</ul><h2>Objetivos de aprendizagem</h2><ul>';
        foreach ($plan['objetivos'] as $o) {
            $h .= '<li>' . e($o) . '</li>';
        }
        $h .= '</ul>';
        if ($plan['justificativa']) {
            $h .= '<h2>Justificativa</h2><ul>';
            foreach ($plan['justificativa'] as $j) {
                $h .= '<li>' . e($j) . '</li>';
            }
            $h .= '</ul>';
        }
        $h .= '<h2>Recursos</h2><ul>';
        foreach ($plan['recursos'] as $r) {
            $h .= '<li>' . e($r) . '</li>';
        }
        $h .= '</ul><h2>Desenvolvimento</h2>';
        foreach ($plan['momentos'] as $m) {
            $h .= '<h3>' . e($m['titulo']) . ' <small>(' . e($m['tipo']) . ' · ' . e($m['duracao']) . ')</small></h3>';
            if ($m['subtitulo'] !== '') {
                $h .= '<p><em>' . e($m['subtitulo']) . '</em></p>';
            }
            $h .= '<ol>';
            foreach ($m['itens'] as $it) {
                $h .= '<li>' . e($it) . '</li>';
            }
            $h .= '</ol>';
        }
        $h .= '<h2>Avaliação</h2><ul>';
        foreach ($plan['avaliacao'] as $a) {
            $h .= '<li>' . e($a) . '</li>';
        }
        $h .= '</ul><p class="obs">' . e($plan['observacoes']) . '</p>';
        return $h;
    }

    /** Gera o PDF (texto selecionável) e devolve os bytes. */
    public function pdf(array $plan): string
    {
        $css = 'body{font-family:dejavusans;font-size:10.5pt;color:#111}h1{font-size:17pt;color:#1e3a8a;margin:0 0 8pt}h2{font-size:12.5pt;color:#1d4ed8;margin:12pt 0 4pt;border-bottom:1px solid #cbd5e1}h3{font-size:11pt;margin:9pt 0 3pt}small{font-weight:normal;color:#475569}table.ident{width:100%;border-collapse:collapse;margin-bottom:6pt}table.ident th{text-align:left;width:32%;padding:3pt 4pt;background:#f1f5f9;font-weight:bold}table.ident td{padding:3pt 4pt;border-bottom:1px solid #e2e8f0}ul,ol{margin:2pt 0 4pt 14pt}li{margin-bottom:2pt}.obs{font-size:9pt;color:#475569;margin-top:14pt}';
        return (new PdfService())->fromHtml('<style>' . $css . '</style>' . $this->html($plan), $plan['titulo']);
    }
}
