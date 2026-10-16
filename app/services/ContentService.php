<?php
// app/services/ContentService.php
// Fluxo do assistente: valida o pedido, chama a IA de texto, valida o JSON
// (com uma nova tentativa de correção), registra o uso e salva o rascunho.
declare(strict_types=1);

namespace App\Services;

use App\Ai\AiException;
use App\Ai\ContentPromptBuilder;
use App\Ai\ContentValidator;
use App\Ai\EngineFactory;
use App\Ai\GeminiText;
use App\Ai\Styles;
use App\Ai\TextEngine;
use App\Log;
use App\Models\AuditLog;
use App\Models\BnccSkill;
use App\Models\Project;
use App\Models\UsageLog;
use InvalidArgumentException;

final class ContentService
{
    public function __construct(private ?EngineFactory $factory = null)
    {
        $this->factory ??= new EngineFactory();
    }

    /**
     * Valida os campos do assistente (passos 1 a 3). Devolve o pedido normalizado
     * com as habilidades oficiais carregadas. @throws InvalidArgumentException
     */
    public static function validateRequest(array $in): array
    {
        $etapa = (string) ($in['etapa'] ?? '');
        if (!isset(BnccSkill::STAGES[$etapa])) {
            throw new InvalidArgumentException('Escolha a etapa de ensino.');
        }
        $ano = trim((string) ($in['ano'] ?? ''));
        if ($ano === '') {
            throw new InvalidArgumentException('Escolha o ano.');
        }
        $componente = trim((string) ($in['componente'] ?? ''));
        if ($componente === '') {
            throw new InvalidArgumentException('Escolha o componente curricular.');
        }
        $codes = array_values(array_unique(array_filter(array_map(fn($c) => BnccSkill::normalizeCode((string) $c), (array) ($in['habilidades'] ?? [])))));
        if ($codes === []) {
            throw new InvalidArgumentException('Escolha pelo menos uma habilidade BNCC.');
        }
        if (count($codes) > ContentValidator::MAX_HABILIDADES) {
            throw new InvalidArgumentException('Escolha no máximo ' . ContentValidator::MAX_HABILIDADES . ' habilidades.');
        }
        $habs = [];
        foreach ($codes as $code) {
            $v = BnccSkill::validateFor($code, $etapa, $ano, $componente);
            if (!$v['ok']) {
                throw new InvalidArgumentException($v['motivo']);
            }
            $habs[] = $v['skill'];
        }
        $tema = trim((string) ($in['tema'] ?? ''));
        if (mb_strlen($tema) < 3 || mb_strlen($tema) > 120) {
            throw new InvalidArgumentException('Informe o tema (3 a 120 caracteres).');
        }
        $finalidade = (string) ($in['finalidade'] ?? 'introducao');
        if (!in_array($finalidade, ContentValidator::FINALIDADES, true)) {
            throw new InvalidArgumentException('Escolha a finalidade do material.');
        }
        $paginas = (int) ($in['paginas'] ?? 3);
        if ($paginas < 1 || $paginas > ContentValidator::MAX_PAGINAS) {
            throw new InvalidArgumentException('O número de páginas deve ficar entre 1 e ' . ContentValidator::MAX_PAGINAS . '.');
        }
        $estilo = (string) ($in['estilo'] ?? '');
        if (!Styles::exists($estilo)) {
            throw new InvalidArgumentException('Escolha um estilo visual.');
        }
        return [
            'etapa' => $etapa, 'ano' => $ano, 'componente' => $componente, 'habilidades' => $habs,
            'tema' => $tema, 'finalidade' => $finalidade, 'paginas' => $paginas, 'estilo' => $estilo,
            'observacoes' => mb_substr(trim((string) ($in['observacoes'] ?? '')), 0, 500),
        ];
    }

    /**
     * Gera o conteúdo pela IA de texto e valida. Se inválido, pede uma correção
     * ao modelo (1 vez). Devolve ['doc' => array, 'erros' => string[], 'uso' => array].
     */
    public function generate(array $req, ?TextEngine $engine = null, ?int $userId = null, ?int $schoolId = null): array
    {
        $engine ??= $this->factory->textEngine();
        $builder = new ContentPromptBuilder();
        $prompts = $builder->build($req);
        $expected = [
            'etapa' => $req['etapa'], 'ano' => $req['ano'], 'componente' => $req['componente'],
            'habilidades' => array_map(fn($h) => $h['code'], $req['habilidades']), 'estilo' => $req['estilo'],
        ];
        $validator = new ContentValidator();
        $doc = [];
        $erros = [];
        $uso = ['input' => 0, 'output' => 0, 'cost' => 0.0, 'ms' => 0, 'chamadas' => 0];
        $userPrompt = $prompts['user'];
        for ($tentativa = 1; $tentativa <= 2; $tentativa++) {
            $start = microtime(true);
            try {
                $doc = $engine->generateJson($prompts['system'], $userPrompt);
            } catch (AiException $e) {
                UsageLog::record(['context' => 'conteudo', 'engine' => $engine->name(), 'model' => $engine->model(), 'prompt_chars' => mb_strlen($prompts['system'] . $userPrompt), 'duration_ms' => (int) round((microtime(true) - $start) * 1000), 'status' => 'erro', 'error_message' => $e->getMessage(), 'user_id' => $userId, 'school_id' => $schoolId]);
                throw $e;
            }
            $this->recordUsage($engine, $prompts['system'] . $userPrompt, $start, $uso, $userId, $schoolId);
            $doc = $this->normalize($doc, $req);
            $erros = $validator->validate($doc, $expected);
            if ($erros === []) {
                break;
            }
            Log::warning('Conteúdo inválido na tentativa ' . $tentativa, ['erros' => $erros]);
            $userPrompt = $prompts['user'] . "\n\n" . $builder->fixPrompt($doc, $erros);
        }
        return ['doc' => $doc, 'erros' => $erros, 'uso' => $uso];
    }

    /** Gera, valida e salva como rascunho. Lança AiException com os motivos se continuar inválido. */
    public function createProject(array $user, array $req): int
    {
        $r = $this->generate($req, null, (int) $user['id'], $user['school_id'] !== null ? (int) $user['school_id'] : null);
        if ($r['erros'] !== []) {
            throw new AiException(
                "O conteúdo gerado não passou na validação. Motivos:\n• " . implode("\n• ", $r['erros']) . "\n\nTente de novo ou ajuste o tema e as habilidades.",
                json_encode($r['erros'], JSON_UNESCAPED_UNICODE),
                'resposta_invalida'
            );
        }
        $id = Project::create($user, $r['doc']);
        AuditLog::record('projeto_criado', 'projects', $id, ['tema' => $req['tema'], 'habilidades' => array_map(fn($h) => $h['code'], $req['habilidades']), 'custo_usd' => $r['uso']['cost']], (int) $user['id']);
        return $id;
    }

    /** Preenche campos que o modelo pode omitir (copiados do pedido) e normaliza códigos. */
    private function normalize(array $doc, array $req): array
    {
        $doc['tipo'] = $doc['tipo'] ?? 'apostila';
        foreach (['etapa', 'ano', 'componente', 'tema', 'finalidade', 'estilo'] as $campo) {
            if (!isset($doc[$campo]) || trim((string) $doc[$campo]) === '') {
                $doc[$campo] = (string) $req[$campo];
            }
        }
        $doc['ano'] = (string) $doc['ano'];
        if (isset($doc['habilidades']) && is_array($doc['habilidades'])) {
            foreach ($doc['habilidades'] as &$h) {
                if (is_array($h) && isset($h['codigo'])) {
                    $h['codigo'] = BnccSkill::normalizeCode((string) $h['codigo']);
                }
            }
            unset($h);
        }
        if (isset($doc['paginas']) && is_array($doc['paginas'])) {
            foreach ($doc['paginas'] as $i => &$p) {
                if (is_array($p)) {
                    $p['numero'] = $i + 1;
                    foreach ($p['blocos'] ?? [] as $j => &$b) {
                        if (is_array($b) && empty($b['n'])) {
                            $b['n'] = str_pad((string) ($j + 1), 2, '0', STR_PAD_LEFT);
                        }
                    }
                    unset($b);
                }
            }
            unset($p);
        }
        return $doc;
    }

    private function recordUsage(TextEngine $engine, string $prompt, float $start, array &$uso, ?int $userId, ?int $schoolId): void
    {
        $ms = (int) round((microtime(true) - $start) * 1000);
        $last = $engine instanceof GeminiText ? $engine->lastUsage() : ['input' => (int) (mb_strlen($prompt) / 4), 'output' => 0, 'cost' => 0.0];
        $uso['input'] += (int) $last['input'];
        $uso['output'] += (int) $last['output'];
        $uso['cost'] += (float) $last['cost'];
        $uso['ms'] += $ms;
        $uso['chamadas']++;
        UsageLog::record([
            'context' => 'conteudo', 'engine' => $engine->name(), 'model' => $engine->model(), 'resolution' => 'texto',
            'prompt_chars' => mb_strlen($prompt), 'input_tokens' => (int) $last['input'], 'output_tokens' => (int) $last['output'],
            'duration_ms' => $ms, 'cost_usd' => (float) $last['cost'], 'status' => 'ok', 'user_id' => $userId, 'school_id' => $schoolId,
        ]);
    }

    /**
     * Reconstrói o JSON a partir do formulário do editor de revisão e valida.
     * @return array{doc:array, erros:string[]}
     */
    public function applyReview(array $doc, array $form): array
    {
        $doc['tema'] = trim((string) ($form['tema'] ?? $doc['tema']));
        foreach (['texto_superior', 'titulo', 'subtitulo', 'frase_inferior'] as $c) {
            $doc['capa'][$c] = trim((string) ($form['capa'][$c] ?? ($doc['capa'][$c] ?? '')));
        }
        foreach ($doc['habilidades'] as $i => &$h) {
            $h['resumo'] = trim((string) ($form['habilidades'][$i]['resumo'] ?? $h['resumo']));
        }
        unset($h);
        foreach ($doc['paginas'] as $i => &$p) {
            $fp = $form['paginas'][$i] ?? [];
            $p['titulo'] = trim((string) ($fp['titulo'] ?? $p['titulo']));
            $p['subtitulo'] = trim((string) ($fp['subtitulo'] ?? ($p['subtitulo'] ?? '')));
            $p['rodape'] = trim((string) ($fp['rodape'] ?? ($p['rodape'] ?? '')));
            foreach ($p['blocos'] as $j => &$b) {
                $fb = $fp['blocos'][$j] ?? [];
                $b['titulo'] = trim((string) ($fb['titulo'] ?? $b['titulo']));
                if (array_key_exists('texto', $fb)) {
                    $texto = trim((string) $fb['texto']);
                    if ($texto !== '') {
                        $b['texto'] = $texto;
                    } else {
                        unset($b['texto']);
                    }
                }
                if (array_key_exists('itens', $fb)) {
                    $itens = array_values(array_filter(array_map(fn($x) => trim((string) $x), preg_split("/\r?\n/", (string) $fb['itens']) ?: []), fn($x) => $x !== ''));
                    if ($itens !== []) {
                        $b['itens'] = $itens;
                    } else {
                        unset($b['itens']);
                    }
                }
            }
            unset($b);
        }
        unset($p);
        $erros = (new ContentValidator())->validate($doc, null, false);
        return ['doc' => $doc, 'erros' => $erros];
    }
}
