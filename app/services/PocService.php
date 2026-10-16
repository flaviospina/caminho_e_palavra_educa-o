<?php
// app/services/PocService.php
// Prova de conceito: gera a mesma página em vários motores, grava os PNGs em
// storage/poc/<execucao>/ com um meta.json e registra cada chamada em usage_log.
declare(strict_types=1);

namespace App\Services;

use App\Ai\AiException;
use App\Ai\EngineFactory;
use App\Ai\ImageEngine;
use App\Ai\PromptBuilder;
use App\Log;
use App\Models\UsageLog;
use Throwable;

final class PocService
{
    public function __construct(
        private ?EngineFactory $factory = null,
        private ?string $baseDir = null,
    ) {
        $this->factory ??= new EngineFactory();
        $this->baseDir ??= STORAGE_PATH . '/poc';
    }

    public function dir(): string
    {
        return $this->baseDir;
    }

    /** Lê e valida minimamente um JSON de exemplo. */
    public static function loadDoc(string $file): array
    {
        $json = @file_get_contents($file);
        if ($json === false) {
            throw new AiException('Arquivo de conteúdo não encontrado: ' . basename($file), $file);
        }
        $doc = json_decode($json, true);
        if (!is_array($doc)) {
            throw new AiException('O arquivo não contém um JSON válido: ' . basename($file), json_last_error_msg());
        }
        foreach (['tipo', 'etapa', 'ano', 'componente', 'tema', 'estilo', 'paginas'] as $campo) {
            if (!isset($doc[$campo]) || $doc[$campo] === '' || $doc[$campo] === []) {
                throw new AiException("O conteúdo está sem o campo obrigatório \"{$campo}\".");
            }
        }
        return $doc;
    }

    /** Lista os exemplos disponíveis em data/exemplos (nome => caminho). */
    public static function examples(): array
    {
        $out = [];
        foreach (glob(ROOT_PATH . '/data/exemplos/*.json') ?: [] as $f) {
            $out[basename($f, '.json')] = $f;
        }
        ksort($out);
        return $out;
    }

    /**
     * Gera a página nos motores informados (ou em todos os ativos).
     * Nunca lança por erro de motor: cada item do resultado traz ok/erro.
     *
     * @param string[]|null $engineNames
     * @return array{run_id:string, dir:string, prompt:string, resultados:array<int,array>}
     */
    public function run(array $doc, int $pageNumber, ?array $engineNames = null, ?string $runId = null): array
    {
        $builder = new PromptBuilder();
        $request = $builder->build($doc, $pageNumber, $this->factory->setting('IMAGE_QUALITY'));
        $runId ??= date('Ymd-His') . '-' . substr(bin2hex(random_bytes(3)), 0, 6);
        $dir = $this->baseDir . '/' . $runId;
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new AiException('Não foi possível criar a pasta da execução em storage/poc.', $dir);
        }
        file_put_contents($dir . '/prompt.txt', $request->prompt);

        $engines = $this->factory->imageEngines($engineNames);
        $resultados = [];
        foreach ($engines as $name => $engine) {
            $resultados[] = $this->generateOne($engine, $request, $dir, $runId);
        }

        $meta = [
            'run_id'     => $runId,
            'gerado_em'  => date('c'),
            'simulado'   => $this->factory->mock(),
            'documento'  => ['componente' => $doc['componente'], 'ano' => $doc['ano'], 'etapa' => $doc['etapa'], 'tema' => $doc['tema'], 'estilo' => $doc['estilo']],
            'pagina'     => $pageNumber,
            'titulo'     => $request->context['titulo'] ?? '',
            'resultados' => $resultados,
        ];
        file_put_contents($dir . '/meta.json', json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        return ['run_id' => $runId, 'dir' => $dir, 'prompt' => $request->prompt, 'resultados' => $resultados];
    }

    private function generateOne(ImageEngine $engine, \App\Ai\ImageRequest $request, string $dir, string $runId): array
    {
        $start = microtime(true);
        try {
            $result = $engine->generate($request);
            $file = $dir . '/' . $engine->name() . '.png';
            $result->save($file);
            UsageLog::record([
                'context' => 'poc', 'reference' => $runId,
                'engine' => $engine->name(), 'model' => $engine->model(), 'resolution' => $result->resolution(),
                'prompt_chars' => mb_strlen($request->prompt),
                'input_tokens' => $result->inputTokens, 'output_tokens' => $result->outputTokens,
                'duration_ms' => $result->durationMs, 'cost_usd' => $result->costUsd, 'status' => 'ok',
            ]);
            return [
                'motor' => $engine->name(), 'modelo' => $engine->model(), 'ok' => true,
                'arquivo' => basename($file), 'caminho' => $file,
                'largura' => $result->width, 'altura' => $result->height,
                'tempo_ms' => $result->durationMs, 'tentativas' => $result->attempts,
                'tokens_entrada' => $result->inputTokens, 'tokens_saida' => $result->outputTokens,
                'custo_usd' => $result->costUsd, 'mensagem' => 'Imagem gerada.',
            ];
        } catch (AiException $e) {
            $ms = (int) round((microtime(true) - $start) * 1000);
            Log::error('PoC: motor falhou', ['motor' => $engine->name(), 'tipo' => $e->kind, 'mensagem' => $e->getMessage(), 'detalhe' => $e->detail]);
            UsageLog::record([
                'context' => 'poc', 'reference' => $runId, 'engine' => $engine->name(), 'model' => $engine->model(),
                'prompt_chars' => mb_strlen($request->prompt), 'duration_ms' => $ms, 'cost_usd' => 0,
                'status' => 'erro', 'error_message' => $e->getMessage(),
            ]);
            return ['motor' => $engine->name(), 'modelo' => $engine->model(), 'ok' => false, 'tipo_erro' => $e->kind, 'tempo_ms' => $ms, 'mensagem' => $e->getMessage()];
        } catch (Throwable $e) {
            $ms = (int) round((microtime(true) - $start) * 1000);
            Log::error('PoC: erro inesperado', ['motor' => $engine->name(), 'erro' => $e->getMessage(), 'arquivo' => $e->getFile(), 'linha' => $e->getLine()]);
            UsageLog::record([
                'context' => 'poc', 'reference' => $runId, 'engine' => $engine->name(), 'model' => $engine->model(),
                'prompt_chars' => mb_strlen($request->prompt), 'duration_ms' => $ms, 'cost_usd' => 0,
                'status' => 'erro', 'error_message' => 'Erro interno: ' . $e->getMessage(),
            ]);
            return ['motor' => $engine->name(), 'modelo' => $engine->model(), 'ok' => false, 'tipo_erro' => 'erro', 'tempo_ms' => $ms, 'mensagem' => 'Erro interno ao gerar a imagem. Detalhes no log.'];
        }
    }

    /** Execuções anteriores (mais recentes primeiro) a partir dos meta.json. */
    public function runs(int $limit = 10): array
    {
        $dirs = glob($this->baseDir . '/*', GLOB_ONLYDIR) ?: [];
        rsort($dirs);
        $out = [];
        foreach (array_slice($dirs, 0, $limit) as $d) {
            $meta = json_decode((string) @file_get_contents($d . '/meta.json'), true);
            if (is_array($meta)) {
                $out[] = $meta;
            }
        }
        return $out;
    }

    /** Caminho seguro de uma imagem de execução (ou null). */
    public function imagePath(string $runId, string $file): ?string
    {
        if (!preg_match('/^[\w\-]{6,40}$/', $runId) || !preg_match('/^[a-z0-9_\-]{1,40}\.png$/', $file)) {
            return null;
        }
        $path = $this->baseDir . '/' . $runId . '/' . $file;
        return is_file($path) ? $path : null;
    }
}
