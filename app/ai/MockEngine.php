<?php
// app/ai/MockEngine.php
// Motor simulado (AI_MOCK=1): desenha a página com GD a partir dos dados do
// pedido, sem chamar nenhuma API e sem custo. Implementa imagem e texto.
declare(strict_types=1);

namespace App\Ai;

final class MockEngine implements ImageEngine, TextEngine
{
    public function __construct(
        private string $emulates = 'mock',        // nome do motor que está substituindo (gemini, openai) ou "mock"
        private string $realModel = 'mock-v1',
        private int $delayMs = 0,                 // atraso artificial (testes de fila)
    ) {
    }

    public function name(): string
    {
        return $this->emulates;
    }

    public function model(): string
    {
        return 'simulado:' . $this->realModel;
    }

    public function generate(ImageRequest $request): ImageResult
    {
        $start = microtime(true);
        if ($this->delayMs > 0) {
            usleep($this->delayMs * 1000);
        }
        [$w, $h] = $request->mock_size();
        $png = $this->draw($w, $h, $request);
        $ms = (int) round((microtime(true) - $start) * 1000);
        return ImageResult::fromPng($png, $this->name(), $this->model(), $ms, strlen($request->prompt) >> 2, 1120, 0.0, 1, ['simulado' => true]);
    }

    /**
     * Texto simulado: monta um conteúdo coerente com o pedido embutido no prompt
     * ("Dados do pedido (JSON): {...}"). Sem pedido, devolve o exemplo de Ciências.
     */
    public function generateJson(string $systemPrompt, string $userPrompt): array
    {
        $req = null;
        if (preg_match('/Dados do pedido \(JSON\):\s*(\{[^\n]*\})/', $userPrompt, $m)) {
            $req = json_decode($m[1], true);
        }
        if (!is_array($req)) {
            $file = ROOT_PATH . '/data/exemplos/ef04_ciencias_misturas.json';
            $data = json_decode((string) @file_get_contents($file), true);
            if (!is_array($data)) {
                throw new AiException('Exemplo simulado de conteúdo não encontrado.', $file);
            }
            return $data;
        }
        if (str_contains($userPrompt, 'SIMULAR_JSON_INVALIDO')) {
            return ['tipo' => 'apostila', 'paginas' => []];   // para os testes do validador
        }
        $tema = (string) ($req['tema'] ?? 'Tema');
        $comp = (string) ($req['componente'] ?? '');
        $ano = \App\Models\BnccSkill::yearLabel((string) ($req['etapa'] ?? ''), (string) ($req['ano'] ?? ''));
        $habs = [];
        $habItens = [];
        foreach ($req['habilidades'] ?? [] as $h) {
            $code = (string) ($h['codigo'] ?? '');
            $resumo = mb_substr('Desenvolver ' . mb_strtolower($tema) . ' conforme a habilidade ' . $code . '.', 0, 200);
            $habs[] = ['codigo' => $code, 'resumo' => $resumo];
            $habItens[] = mb_substr($code . ' – ' . mb_strtolower($tema), 0, 80);
        }
        $n = max(1, (int) ($req['paginas'] ?? 1));
        $tipos = $req['tipos_paginas'] ?? [];
        $t = fn(string $s, int $max) => mb_substr($s, 0, $max);
        $paginas = [[
            'numero' => 1, 'tipo' => 'abertura', 'titulo' => $t($tema, 30), 'subtitulo' => $t('Começando a estudar ' . mb_strtolower($tema), 60),
            'blocos' => [
                ['n' => '01', 'titulo' => 'Objetivo', 'texto' => $t('Compreender ' . mb_strtolower($tema) . ' com exemplos do dia a dia.', 150)],
                ['n' => '02', 'titulo' => 'O que você vai aprender', 'itens' => ['Ideias principais', 'Exemplos do cotidiano', 'Como aplicar']],
                ['n' => '03', 'titulo' => 'Habilidades BNCC', 'itens' => $habItens],
                ['n' => '04', 'titulo' => 'Por que é importante', 'itens' => ['Ajuda a entender o mundo', 'Base para os próximos temas']],
                ['n' => '05', 'titulo' => 'Como vamos estudar', 'itens' => ['Leitura e conversa', 'Exemplos', 'Atividade final']],
                ['n' => '06', 'titulo' => 'Nas próximas páginas', 'itens' => array_map(fn($i) => "Página {$i} – " . ($tipos[$i - 1] ?? 'conteúdo'), range(2, max(2, $n)))],
            ],
            'rodape' => $t('Vamos aprender juntos sobre ' . mb_strtolower($tema) . '.', 90),
        ]];
        for ($i = 2; $i <= $n; $i++) {
            $tipo = $tipos[$i - 1] ?? ($i === $n ? 'pratica' : 'explicacao');
            $paginas[] = [
                'numero' => $i, 'tipo' => $tipo,
                'titulo' => $t(($tipo === 'pratica' ? 'Mão na massa: ' : 'Entendendo ') . mb_strtolower($tema), 30),
                'subtitulo' => $t($tipo === 'pratica' ? 'Atividades para praticar' : 'Conceitos e exemplos', 60),
                'blocos' => [
                    ['n' => '01', 'titulo' => $tipo === 'pratica' ? 'Atividade 1' : 'Conceito', 'texto' => $t('Texto simulado sobre ' . mb_strtolower($tema) . ' para ' . $ano . ' de ' . $comp . '.', 150)],
                    ['n' => '02', 'titulo' => $tipo === 'pratica' ? 'Desafio' : 'Exemplo', 'itens' => ['Primeiro item', 'Segundo item', 'Terceiro item']],
                    ['n' => '03', 'titulo' => $tipo === 'pratica' ? 'Para conversar' : 'Lembre-se', 'texto' => $t('Pergunta ou lembrete simulado sobre ' . mb_strtolower($tema) . '.', 150)],
                ],
                'rodape' => $t('Página simulada ' . $i . ' sobre ' . mb_strtolower($tema) . '.', 90),
            ];
        }
        return [
            'tipo' => 'apostila', 'etapa' => (string) ($req['etapa'] ?? ''), 'ano' => (string) ($req['ano'] ?? ''), 'componente' => $comp,
            'habilidades' => $habs, 'tema' => $tema, 'finalidade' => (string) ($req['finalidade'] ?? 'introducao'), 'estilo' => (string) ($req['estilo'] ?? 'doodle_escolar'),
            'capa' => ['texto_superior' => $t($comp . ' · ' . $ano, 60), 'titulo' => $t($tema, 30), 'subtitulo' => $t('Um material simulado sobre ' . mb_strtolower($tema), 60), 'frase_inferior' => 'Aprender é descobrir.'],
            'paginas' => $paginas,
        ];
    }

    // ── desenho ──────────────────────────────────────────────────────────

    private function font(bool $bold = false): string
    {
        return ROOT_PATH . '/templates/fonts/DejaVuSans' . ($bold ? '-Bold' : '') . '.ttf';
    }

    private function draw(int $w, int $h, ImageRequest $r): string
    {
        $c = $r->context;
        $img = imagecreatetruecolor($w, $h);
        $cores = $c['cores'] ?? [];
        $bg = $this->rgb($img, $cores['fundo'] ?? '#FFFFFF');
        $accent = $this->rgb($img, $cores['destaque'] ?? '#1D4ED8');
        $text = $this->rgb($img, $cores['texto'] ?? '#111827');
        $white = imagecolorallocate($img, 255, 255, 255);
        $light = $this->rgb($img, $cores['bloco'] ?? '#F3F4F6');
        $dark = $this->rgb($img, $cores['escuro'] ?? '#1E3A8A');
        imagefilledrectangle($img, 0, 0, $w, $h, $bg);

        $m = (int) round($w * 0.05);              // margem
        $s = $w / 1024;                           // escala

        // Faixa superior
        imagefilledrectangle($img, 0, 0, $w, (int) (150 * $s), $dark);
        $this->text($img, $c['etiqueta'] ?? 'Material didático', 15 * $s, $m, 40 * $s, $white, false);
        $this->text($img, mb_strtoupper((string) ($c['titulo'] ?? 'Título')), 46 * $s, $m, 105 * $s, $white, true, $w - 2 * $m);
        $y = 150 * $s + 48 * $s;
        if (!empty($c['subtitulo'])) {
            $y = $this->paragraph($img, (string) $c['subtitulo'], 20 * $s, $m, $y, $w - 2 * $m, $accent, true);
            $y += 12 * $s;
        }

        // Capa: título grande centralizado
        if (!empty($c['capa'])) {
            imagefilledrectangle($img, 0, (int) (150 * $s), $w, (int) ($h - 90 * $s), $accent);
            $this->paragraph($img, (string) ($c['titulo'] ?? ''), 64 * $s, $m, $h * 0.3, $w - 2 * $m, $white, true);
            $this->paragraph($img, (string) ($c['subtitulo'] ?? ''), 26 * $s, $m, $h * 0.55, $w - 2 * $m, $white, false);
            imagefilledrectangle($img, 0, (int) ($h - 90 * $s), $w, $h, $dark);
            $this->text($img, (string) ($c['rodape'] ?? ''), 16 * $s, $m, $h - 50 * $s, $white, false, $w - 2 * $m);
            $wm = imagecolorallocatealpha($img, 220, 38, 38, 90);
            imagettftext($img, (int) (60 * $s), 30, (int) ($w * 0.15), (int) ($h * 0.6), $wm, $this->font(true), 'SIMULADO');
            ob_start();
            imagepng($img, null, 6);
            imagedestroy($img);
            return (string) ob_get_clean();
        }

        // Blocos em duas colunas
        $blocos = array_values($c['blocos'] ?? []);
        if ($blocos === []) {
            $y = $this->paragraph($img, $r->prompt, 14 * $s, $m, $y, $w - 2 * $m, $text, false);
        } else {
            $gap = 16 * $s;
            $colW = ($w - 2 * $m - $gap) / 2;
            $footerTop = $h - 110 * $s;
            $availH = $footerTop - $y - 10 * $s;
            $rows = (int) ceil(count($blocos) / 2);
            $blockH = max(90 * $s, ($availH - ($rows - 1) * $gap) / $rows);
            foreach ($blocos as $i => $b) {
                $col = $i % 2;
                $row = intdiv($i, 2);
                $x0 = $m + $col * ($colW + $gap);
                $y0 = $y + $row * ($blockH + $gap);
                $this->roundRect($img, (int) $x0, (int) $y0, (int) ($x0 + $colW), (int) ($y0 + $blockH), (int) (14 * $s), $light);
                imagefilledrectangle($img, (int) $x0, (int) $y0, (int) ($x0 + 8 * $s), (int) ($y0 + $blockH), $accent);
                $n = (string) ($b['n'] ?? str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT));
                $this->text($img, $n, 22 * $s, $x0 + 22 * $s, $y0 + 36 * $s, $accent, true);
                $this->text($img, (string) ($b['titulo'] ?? ''), 17 * $s, $x0 + 70 * $s, $y0 + 34 * $s, $text, true, $colW - 85 * $s);
                $ty = $y0 + 62 * $s;
                $maxY = $y0 + $blockH - 10 * $s;
                if (!empty($b['itens'])) {
                    foreach ($b['itens'] as $item) {
                        if ($ty > $maxY) {
                            break;
                        }
                        $ty = $this->paragraph($img, '• ' . $item, 13 * $s, $x0 + 22 * $s, $ty, $colW - 40 * $s, $text, false, $maxY);
                    }
                }
                if (!empty($b['texto']) && $ty <= $maxY) {
                    $this->paragraph($img, (string) $b['texto'], 13 * $s, $x0 + 22 * $s, $ty, $colW - 40 * $s, $text, false, $maxY);
                }
            }
        }

        // Rodapé
        imagefilledrectangle($img, 0, (int) ($h - 90 * $s), $w, $h, $dark);
        $this->text($img, (string) ($c['rodape'] ?? ''), 14 * $s, $m, $h - 50 * $s, $white, false, $w * 0.7);
        $pag = 'Página ' . ($c['numero'] ?? 1) . ' de ' . ($c['total'] ?? 1);
        $this->text($img, $pag, 14 * $s, $w - $m - $this->width($pag, 14 * $s, false), $h - 50 * $s, $white, false);

        // Marca d'água
        $wm = imagecolorallocatealpha($img, 220, 38, 38, 90);
        imagettftext($img, (int) (60 * $s), 30, (int) ($w * 0.15), (int) ($h * 0.6), $wm, $this->font(true), 'SIMULADO');

        ob_start();
        imagepng($img, null, 6);
        imagedestroy($img);
        return (string) ob_get_clean();
    }

    private function rgb(\GdImage $img, string $hex): int
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) !== 6) {
            $hex = '000000';
        }
        return imagecolorallocate($img, (int) hexdec(substr($hex, 0, 2)), (int) hexdec(substr($hex, 2, 2)), (int) hexdec(substr($hex, 4, 2)));
    }

    private function roundRect(\GdImage $img, int $x1, int $y1, int $x2, int $y2, int $r, int $color): void
    {
        imagefilledrectangle($img, $x1 + $r, $y1, $x2 - $r, $y2, $color);
        imagefilledrectangle($img, $x1, $y1 + $r, $x2, $y2 - $r, $color);
        foreach ([[$x1 + $r, $y1 + $r], [$x2 - $r, $y1 + $r], [$x1 + $r, $y2 - $r], [$x2 - $r, $y2 - $r]] as [$cx, $cy]) {
            imagefilledellipse($img, $cx, $cy, 2 * $r, 2 * $r, $color);
        }
    }

    private function width(string $txt, float $size, bool $bold): float
    {
        $box = imagettfbbox($size, 0, $this->font($bold), $txt);
        return $box === false ? 0 : (float) ($box[2] - $box[0]);
    }

    /** Uma linha, truncada com "…" se exceder maxWidth. */
    private function text(\GdImage $img, string $txt, float $size, float $x, float $y, int $color, bool $bold, ?float $maxWidth = null): void
    {
        if ($maxWidth !== null) {
            while ($txt !== '' && $this->width($txt, $size, $bold) > $maxWidth) {
                $txt = mb_substr($txt, 0, -2) . '…';
            }
        }
        imagettftext($img, (int) round($size), 0, (int) $x, (int) $y, $color, $this->font($bold), $txt);
    }

    /** Parágrafo com quebra de linha; devolve o y após a última linha. */
    private function paragraph(\GdImage $img, string $txt, float $size, float $x, float $y, float $maxWidth, int $color, bool $bold, ?float $maxY = null): float
    {
        $words = preg_split('/\s+/u', trim($txt)) ?: [];
        $line = '';
        $lineH = $size * 1.5;
        foreach ($words as $word) {
            $try = $line === '' ? $word : $line . ' ' . $word;
            if ($this->width($try, $size, $bold) > $maxWidth && $line !== '') {
                if ($maxY !== null && $y + $lineH > $maxY) {
                    return $y;
                }
                $y += $lineH;
                imagettftext($img, (int) round($size), 0, (int) $x, (int) $y, $color, $this->font($bold), $line);
                $line = $word;
            } else {
                $line = $try;
            }
        }
        if ($line !== '' && ($maxY === null || $y + $lineH <= $maxY)) {
            $y += $lineH;
            imagettftext($img, (int) round($size), 0, (int) $x, (int) $y, $color, $this->font($bold), $line);
        }
        return $y + $size * 0.4;
    }
}
