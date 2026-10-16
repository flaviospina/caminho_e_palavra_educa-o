<?php
// app/ai/PromptBuilder.php
// Transforma o JSON aprovado (seção 5 do prompt-mestre) + prompt-modelo do
// estilo no prompt final de UMA página, e monta o ImageRequest correspondente.
declare(strict_types=1);

namespace App\Ai;

final class PromptBuilder
{
    public const ETAPAS = [
        'EI'                => 'Educação Infantil',
        'EF_ANOS_INICIAIS'  => 'Ensino Fundamental – Anos Iniciais',
        'EF_ANOS_FINAIS'    => 'Ensino Fundamental – Anos Finais',
        'EM'                => 'Ensino Médio',
        'EJA'               => 'Educação de Jovens e Adultos',
    ];

    /** Monta o pedido de imagem da página indicada. */
    public function build(array $doc, int $pageNumber, string $quality = 'high'): ImageRequest
    {
        $page = $this->findPage($doc, $pageNumber);
        $style = Styles::get((string) ($doc['estilo'] ?? ''));
        $template = Styles::template($style['chave']);
        $total = count($doc['paginas']);

        $vars = [
            '{descricao_do_estilo}' => (string) $style['descricao'],
            '{mascote}'             => (string) $style['mascote'],
            '{ano}'                 => self::anoLabel((string) ($doc['ano'] ?? '')),
            '{etapa}'               => self::etapaLabel((string) ($doc['etapa'] ?? '')),
            '{componente}'          => (string) ($doc['componente'] ?? ''),
            '{tema}'                => (string) ($doc['tema'] ?? ''),
            '{titulo}'              => (string) ($page['titulo'] ?? ''),
            '{subtitulo}'           => (string) ($page['subtitulo'] ?? ''),
            '{blocos}'              => self::formatBlocks($page['blocos'] ?? []),
            '{rodape}'              => (string) ($page['rodape'] ?? ''),
            '{numero}'              => (string) $pageNumber,
            '{total}'               => (string) $total,
        ];
        $prompt = strtr($template, $vars);
        $prompt = preg_replace("/\n{3,}/", "\n\n", trim($prompt)) ?? $prompt;

        $context = [
            'estilo'    => $style['chave'],
            'etiqueta'  => trim($vars['{componente}'] . ' · ' . $vars['{ano}'] . ' · ' . $vars['{etapa}'], ' ·'),
            'titulo'    => $vars['{titulo}'],
            'subtitulo' => $vars['{subtitulo}'],
            'blocos'    => array_values($page['blocos'] ?? []),
            'rodape'    => $vars['{rodape}'],
            'numero'    => $pageNumber,
            'total'     => $total,
            'cores'     => $style['cores'] ?? [],
        ];
        $label = ($doc['componente'] ?? '') . ' ' . $vars['{ano}'] . ' p' . $pageNumber;
        return new ImageRequest($prompt, 'A4', $quality, trim($label), $context);
    }

    /** Monta o pedido de imagem da CAPA (modelo compartilhado templates/styles/_capa.txt). */
    public function buildCover(array $doc, string $quality = 'high'): ImageRequest
    {
        $style = Styles::get((string) ($doc['estilo'] ?? ''));
        $file = Styles::dir() . '/_capa.txt';
        $template = @file_get_contents($file);
        if ($template === false) {
            throw new AiException('Modelo de capa não encontrado.', $file);
        }
        $capa = $doc['capa'] ?? [];
        $vars = [
            '{descricao_do_estilo}' => (string) $style['descricao'],
            '{mascote}'             => (string) $style['mascote'],
            '{ano}'                 => self::anoLabel((string) ($doc['ano'] ?? '')),
            '{etapa}'               => self::etapaLabel((string) ($doc['etapa'] ?? '')),
            '{componente}'          => (string) ($doc['componente'] ?? ''),
            '{tema}'                => (string) ($doc['tema'] ?? ''),
            '{texto_superior}'      => (string) ($capa['texto_superior'] ?? ''),
            '{titulo}'              => (string) ($capa['titulo'] ?? $doc['tema'] ?? ''),
            '{subtitulo}'           => (string) ($capa['subtitulo'] ?? ''),
            '{frase_inferior}'      => (string) ($capa['frase_inferior'] ?? ''),
        ];
        $prompt = trim(strtr($template, $vars));
        $context = [
            'capa' => true, 'estilo' => $style['chave'], 'etiqueta' => $vars['{texto_superior}'],
            'titulo' => $vars['{titulo}'], 'subtitulo' => $vars['{subtitulo}'], 'rodape' => $vars['{frase_inferior}'],
            'blocos' => [], 'numero' => 0, 'total' => count($doc['paginas'] ?? []), 'cores' => $style['cores'] ?? [],
        ];
        return new ImageRequest($prompt, 'A4', $quality, 'capa', $context);
    }

    /** Textos que devem aparecer na imagem de uma página (0 = capa), para a conferência. @return string[] */
    public static function expectedTexts(array $doc, int $pageNumber): array
    {
        if ($pageNumber === 0) {
            $c = $doc['capa'] ?? [];
            return array_values(array_filter(array_map('trim', [(string) ($c['texto_superior'] ?? ''), (string) ($c['titulo'] ?? ''), (string) ($c['subtitulo'] ?? ''), (string) ($c['frase_inferior'] ?? '')]), fn($t) => $t !== ''));
        }
        $out = [];
        foreach ($doc['paginas'] ?? [] as $p) {
            if ((int) ($p['numero'] ?? 0) !== $pageNumber) {
                continue;
            }
            $out[] = (string) $p['titulo'];
            if (!empty($p['subtitulo'])) {
                $out[] = (string) $p['subtitulo'];
            }
            foreach ($p['blocos'] ?? [] as $b) {
                $out[] = (string) $b['titulo'];
                if (!empty($b['texto'])) {
                    $out[] = (string) $b['texto'];
                }
                foreach ($b['itens'] ?? [] as $it) {
                    $out[] = (string) $it;
                }
            }
            if (!empty($p['rodape'])) {
                $out[] = (string) $p['rodape'];
            }
            $out[] = 'Página ' . $pageNumber . ' de ' . count($doc['paginas']);
        }
        return array_values(array_filter(array_map('trim', $out), fn($t) => $t !== ''));
    }

    /** Localiza a página pelo campo "numero". */
    public function findPage(array $doc, int $pageNumber): array
    {
        $paginas = $doc['paginas'] ?? null;
        if (!is_array($paginas) || $paginas === []) {
            throw new AiException('O conteúdo não tem páginas. Gere ou revise o conteúdo antes de criar as imagens.');
        }
        foreach ($paginas as $p) {
            if ((int) ($p['numero'] ?? 0) === $pageNumber) {
                if (trim((string) ($p['titulo'] ?? '')) === '') {
                    throw new AiException("A página {$pageNumber} está sem título.");
                }
                return $p;
            }
        }
        throw new AiException("Página {$pageNumber} não existe neste conteúdo (há " . count($paginas) . ' página(s)).');
    }

    /** "4" → "4º ano"; valores não numéricos ficam como estão. */
    public static function anoLabel(string $ano): string
    {
        $ano = trim($ano);
        return ctype_digit($ano) ? $ano . 'º ano' : $ano;
    }

    public static function etapaLabel(string $etapa): string
    {
        return self::ETAPAS[$etapa] ?? $etapa;
    }

    /** Blocos numerados em texto, com título e conteúdo EXATOS. */
    public static function formatBlocks(array $blocos): string
    {
        $lines = [];
        foreach ($blocos as $i => $b) {
            $n = (string) ($b['n'] ?? str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT));
            $titulo = trim((string) ($b['titulo'] ?? ''));
            $lines[] = "Bloco {$n} — Título: \"{$titulo}\"";
            if (!empty($b['itens']) && is_array($b['itens'])) {
                foreach ($b['itens'] as $item) {
                    $lines[] = '  • "' . trim((string) $item) . '"';
                }
            }
            $texto = trim((string) ($b['texto'] ?? ''));
            if ($texto !== '') {
                $lines[] = "  Texto: \"{$texto}\"";
            }
        }
        return implode("\n", $lines);
    }
}
