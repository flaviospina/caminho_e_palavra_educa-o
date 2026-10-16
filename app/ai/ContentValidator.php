<?php
// app/ai/ContentValidator.php
// Valida o JSON de conteúdo (seção 5 do prompt-mestre) antes de qualquer
// geração de imagem: estrutura, habilidades dentro do recorte oficial e
// limites de texto do estilo. Devolve mensagens em português para o professor.
declare(strict_types=1);

namespace App\Ai;

use App\Models\BnccSkill;

final class ContentValidator
{
    public const TIPOS = ['apostila', 'cartaz', 'plano'];
    public const FINALIDADES = ['introducao', 'aprofundamento', 'revisao', 'atividade'];
    public const TIPOS_PAGINA = ['abertura', 'explicacao', 'pratica', 'atividade', 'resumo', 'capa'];
    public const MAX_PAGINAS = 6;
    public const MAX_HABILIDADES = 3;

    /** @var string[] */
    private array $errors = [];

    /**
     * Valida o documento. $expected (opcional) traz etapa/ano/componente/habilidades/estilo
     * escolhidos pelo professor; o conteúdo precisa ser coerente com eles.
     * @return string[] lista de erros (vazia = válido)
     */
    public function validate(array $doc, ?array $expected = null, bool $checkBncc = true): array
    {
        $this->errors = [];
        foreach (['tipo', 'etapa', 'ano', 'componente', 'tema', 'estilo', 'habilidades', 'paginas'] as $campo) {
            if (!array_key_exists($campo, $doc) || $doc[$campo] === '' || $doc[$campo] === null) {
                $this->errors[] = "Falta o campo \"{$campo}\" no conteúdo.";
            }
        }
        if ($this->errors !== []) {
            return $this->errors;
        }
        if (!in_array($doc['tipo'], self::TIPOS, true)) {
            $this->errors[] = 'Tipo de material inválido: ' . $this->str($doc['tipo']) . '.';
        }
        if (!isset(BnccSkill::STAGES[$doc['etapa']])) {
            $this->errors[] = 'Etapa inválida: ' . $this->str($doc['etapa']) . '.';
        }
        if (isset($doc['finalidade']) && $doc['finalidade'] !== '' && !in_array($doc['finalidade'], self::FINALIDADES, true)) {
            $this->errors[] = 'Finalidade inválida: ' . $this->str($doc['finalidade']) . '.';
        }
        if (!Styles::exists((string) $doc['estilo'])) {
            $this->errors[] = 'Estilo desconhecido: ' . $this->str($doc['estilo']) . '.';
        }

        if ($expected !== null) {
            foreach (['etapa', 'ano', 'componente'] as $campo) {
                if (isset($expected[$campo]) && (string) $doc[$campo] !== (string) $expected[$campo]) {
                    $this->errors[] = "O conteúdo veio com {$campo} \"" . $this->str($doc[$campo]) . "\", mas você escolheu \"" . $this->str($expected[$campo]) . "\".";
                }
            }
            if (isset($expected['estilo']) && (string) $doc['estilo'] !== (string) $expected['estilo']) {
                $this->errors[] = 'O estilo do conteúdo não é o que você escolheu.';
            }
        }

        $this->validateSkills($doc, $expected, $checkBncc);
        $this->validatePages($doc);
        return $this->errors;
    }

    public function errors(): array
    {
        return $this->errors;
    }

    private function validateSkills(array $doc, ?array $expected, bool $checkBncc): void
    {
        $habs = $doc['habilidades'];
        if (!is_array($habs) || $habs === []) {
            $this->errors[] = 'Informe pelo menos uma habilidade BNCC.';
            return;
        }
        if (count($habs) > self::MAX_HABILIDADES) {
            $this->errors[] = 'Use no máximo ' . self::MAX_HABILIDADES . ' habilidades por material.';
        }
        $codes = [];
        foreach ($habs as $i => $h) {
            $code = is_array($h) ? BnccSkill::normalizeCode((string) ($h['codigo'] ?? '')) : '';
            if ($code === '') {
                $this->errors[] = 'Habilidade ' . ($i + 1) . ' sem código.';
                continue;
            }
            if (in_array($code, $codes, true)) {
                $this->errors[] = "Habilidade {$code} repetida.";
            }
            $codes[] = $code;
            if (trim((string) ($h['resumo'] ?? '')) === '') {
                $this->errors[] = "Habilidade {$code} sem resumo.";
            } elseif (mb_strlen((string) $h['resumo']) > 200) {
                $this->errors[] = "O resumo da habilidade {$code} passa de 200 caracteres.";
            }
            if ($checkBncc) {
                $v = BnccSkill::validateFor($code, (string) $doc['etapa'], (string) $doc['ano'], (string) $doc['componente']);
                if (!$v['ok']) {
                    $this->errors[] = $v['motivo'];
                }
            }
        }
        if ($expected !== null && !empty($expected['habilidades'])) {
            $esperados = array_map(fn($c) => BnccSkill::normalizeCode((string) $c), (array) $expected['habilidades']);
            sort($esperados);
            $recebidos = $codes;
            sort($recebidos);
            if ($esperados !== $recebidos) {
                $this->errors[] = 'As habilidades do conteúdo (' . implode(', ', $recebidos) . ') não são as que você escolheu (' . implode(', ', $esperados) . ').';
            }
        }
    }

    private function validatePages(array $doc): void
    {
        $paginas = $doc['paginas'];
        if (!is_array($paginas) || $paginas === []) {
            $this->errors[] = 'O conteúdo precisa ter pelo menos uma página.';
            return;
        }
        if (count($paginas) > self::MAX_PAGINAS) {
            $this->errors[] = 'O material pode ter no máximo ' . self::MAX_PAGINAS . ' páginas.';
        }
        $limites = Styles::exists((string) $doc['estilo']) ? (Styles::get((string) $doc['estilo'])['limites'] ?? []) : [];
        $lim = fn(string $k, int $padrao): int => (int) ($limites[$k] ?? $padrao);

        if (isset($doc['capa']) && is_array($doc['capa'])) {
            $this->checkLen('Capa › título', $doc['capa']['titulo'] ?? '', $lim('titulo', 34), true);
            $this->checkLen('Capa › subtítulo', $doc['capa']['subtitulo'] ?? '', $lim('subtitulo', 90));
            $this->checkLen('Capa › texto superior', $doc['capa']['texto_superior'] ?? '', 60);
            $this->checkLen('Capa › frase inferior', $doc['capa']['frase_inferior'] ?? '', 90);
        }

        foreach (array_values($paginas) as $i => $p) {
            $n = $i + 1;
            if (!is_array($p)) {
                $this->errors[] = "Página {$n} inválida.";
                continue;
            }
            if ((int) ($p['numero'] ?? 0) !== $n) {
                $this->errors[] = "A página {$n} está numerada como \"" . $this->str($p['numero'] ?? '') . "\".";
            }
            if (!in_array((string) ($p['tipo'] ?? ''), self::TIPOS_PAGINA, true)) {
                $this->errors[] = "Página {$n}: tipo \"" . $this->str($p['tipo'] ?? '') . "\" inválido.";
            }
            $this->checkLen("Página {$n} › título", $p['titulo'] ?? '', $lim('titulo', 34), true);
            $this->checkLen("Página {$n} › subtítulo", $p['subtitulo'] ?? '', $lim('subtitulo', 90));
            $this->checkLen("Página {$n} › rodapé", $p['rodape'] ?? '', 90);
            $blocos = $p['blocos'] ?? null;
            if (!is_array($blocos) || $blocos === []) {
                $this->errors[] = "Página {$n} sem blocos.";
                continue;
            }
            if (count($blocos) > $lim('blocos_max', 8)) {
                $this->errors[] = "Página {$n} tem " . count($blocos) . ' blocos; o estilo permite no máximo ' . $lim('blocos_max', 8) . '.';
            }
            foreach (array_values($blocos) as $j => $b) {
                $bn = $j + 1;
                $rot = "Página {$n} › bloco {$bn}";
                if (!is_array($b)) {
                    $this->errors[] = "{$rot} inválido.";
                    continue;
                }
                $this->checkLen("{$rot} › título", $b['titulo'] ?? '', $lim('bloco_titulo', 30), true);
                $texto = trim((string) ($b['texto'] ?? ''));
                $itens = $b['itens'] ?? [];
                if (!is_array($itens)) {
                    $this->errors[] = "{$rot}: a lista de itens é inválida.";
                    $itens = [];
                }
                if ($texto === '' && $itens === []) {
                    $this->errors[] = "{$rot} está vazio: escreva um texto ou itens.";
                }
                $this->checkLen("{$rot} › texto", $texto, $lim('bloco_texto', 220));
                if (count($itens) > $lim('itens_max', 5)) {
                    $this->errors[] = "{$rot} tem " . count($itens) . ' itens; o máximo é ' . $lim('itens_max', 5) . '.';
                }
                foreach (array_values($itens) as $k => $item) {
                    $this->checkLen("{$rot} › item " . ($k + 1), (string) $item, $lim('item', 80), true);
                }
            }
        }
    }

    private function checkLen(string $rotulo, mixed $valor, int $max, bool $obrigatorio = false): void
    {
        $v = trim((string) $valor);
        if ($obrigatorio && $v === '') {
            $this->errors[] = "{$rotulo} está vazio.";
            return;
        }
        $len = mb_strlen($v);
        if ($len > $max) {
            $this->errors[] = "{$rotulo} tem {$len} caracteres; o máximo para este estilo é {$max}. Encurte o texto.";
        }
    }

    private function str(mixed $v): string
    {
        return mb_substr(is_scalar($v) ? (string) $v : json_encode($v, JSON_UNESCAPED_UNICODE), 0, 40);
    }

    /** Limites do estilo em formato pronto para o editor (JS). */
    public static function limitsFor(string $style): array
    {
        $padrao = ['titulo' => 34, 'subtitulo' => 90, 'bloco_titulo' => 30, 'bloco_texto' => 220, 'item' => 80, 'itens_max' => 5, 'blocos_max' => 8];
        $l = Styles::exists($style) ? (Styles::get($style)['limites'] ?? []) : [];
        return array_merge($padrao, array_map('intval', $l), ['rodape' => 90, 'resumo' => 200, 'paginas_max' => self::MAX_PAGINAS]);
    }
}
