<?php
// app/services/GenerationWorker.php
// Worker da fila: reivindica um job, gera a imagem da página com o motor
// configurado, confere com o modelo de visão (regenera até 2 vezes), grava o
// custo em usage_log e atualiza página e projeto. Executável em lote (cron) ou
// contínuo (systemd).
declare(strict_types=1);

namespace App\Services;

use App\Ai\AiException;
use App\Ai\EngineFactory;
use App\Ai\ImageEngine;
use App\Ai\PromptBuilder;
use App\Ai\Verifier;
use App\Log;
use App\Models\GenerationJob;
use App\Models\Page;
use App\Models\Project;
use App\Models\UsageLog;
use Throwable;

final class GenerationWorker
{
    public const MAX_REGENERATIONS = 2;   // regenera até 2 vezes quando a conferência reprova

    private string $workerId;
    private ?ImageEngine $engine;
    private ?Verifier $verifier;
    private bool $verifierSet;

    public function __construct(
        private ?EngineFactory $factory = null,
        ?ImageEngine $engine = null,
        ?Verifier $verifier = null,
        bool $verifierSet = false,
        ?string $workerId = null,
        private int $staleMinutes = 10,
        private int $retryDelaySeconds = 30,
    ) {
        $this->factory ??= new EngineFactory();
        $this->engine = $engine;
        $this->verifier = $verifier;
        $this->verifierSet = $verifierSet || $verifier !== null;
        $this->workerId = $workerId ?? (gethostname() ?: 'worker') . ':' . getmypid();
    }

    public function workerId(): string
    {
        return $this->workerId;
    }

    private function engine(): ImageEngine
    {
        return $this->engine ??= $this->factory->defaultImageEngine();
    }

    private function verifier(): ?Verifier
    {
        if (!$this->verifierSet) {
            $this->verifier = $this->factory->verifier();
            $this->verifierSet = true;
        }
        return $this->verifier;
    }

    /**
     * Processa jobs até a fila esvaziar ou o tempo acabar.
     * @return array{processados:int, recuperados:int, segundos:float}
     */
    public function runBatch(int $maxSeconds = 50, int $maxJobs = 100): array
    {
        $start = microtime(true);
        $recuperados = GenerationJob::recoverStale($this->staleMinutes);
        $n = 0;
        while ($n < $maxJobs && (microtime(true) - $start) < $maxSeconds) {
            if (!$this->runOne()) {
                break;
            }
            $n++;
        }
        return ['processados' => $n, 'recuperados' => $recuperados, 'segundos' => round(microtime(true) - $start, 2)];
    }

    /** Reivindica e processa UM job. Devolve false se a fila estava vazia. */
    public function runOne(): bool
    {
        $job = GenerationJob::claim($this->workerId);
        if ($job === null) {
            return false;
        }
        $this->process($job);
        return true;
    }

    /** Processa um job já reivindicado (público para os testes). */
    public function process(array $job): void
    {
        $page = Page::find((int) $job['page_id']);
        $project = Project::find((int) $job['project_id']);
        if ($page === null || $project === null) {
            GenerationJob::setStatus((int) $job['id'], 'erro', 'Página ou projeto não existe mais.', true);
            return;
        }
        $doc = Project::currentContent($project);
        $number = (int) $page['number'];
        $userId = (int) $job['user_id'];
        $schoolId = $project['school_id'] !== null ? (int) $project['school_id'] : null;
        $ref = 'projeto:' . $project['id'] . '/p' . $number;
        Page::setStatus((int) $page['id'], 'gerando');

        try {
            $builder = new PromptBuilder();
            $request = $number === 0 ? $builder->buildCover($doc, $this->factory->setting('IMAGE_QUALITY')) : $builder->build($doc, $number, $this->factory->setting('IMAGE_QUALITY'));
            $expected = PromptBuilder::expectedTexts($doc, $number);
            $engine = $this->engine();
            $verifier = $this->verifier();
            $check = null;
            $imageRel = null;
            $attempt = 0;
            $finalStatus = 'pronto';
            while (true) {
                $attempt++;
                $result = $engine->generate($request);
                $imageRel = 'projects/' . $project['id'] . '/pagina-' . $number . '-v' . $attempt . '-' . substr(bin2hex(random_bytes(3)), 0, 6) . '.png';
                $result->save(STORAGE_PATH . '/' . $imageRel);
                UsageLog::record([
                    'user_id' => $userId, 'school_id' => $schoolId, 'context' => $project['type'] ?? 'apostila', 'reference' => $ref,
                    'engine' => $result->engine, 'model' => $result->model, 'resolution' => $result->resolution(),
                    'prompt_chars' => mb_strlen($request->prompt), 'input_tokens' => $result->inputTokens, 'output_tokens' => $result->outputTokens,
                    'duration_ms' => $result->durationMs, 'cost_usd' => $result->costUsd, 'status' => 'ok',
                ]);
                if ($verifier === null) {
                    break;
                }
                GenerationJob::setStatus((int) $job['id'], 'conferindo');
                Page::setStatus((int) $page['id'], 'conferindo');
                $check = $this->verify($verifier, STORAGE_PATH . '/' . $imageRel, $expected, $request->context, $userId, $schoolId, $ref);
                $check['tentativa'] = $attempt;
                if ($check['ok']) {
                    break;
                }
                Log::warning('Conferência reprovou a página', ['ref' => $ref, 'tentativa' => $attempt, 'problemas' => $check['problemas']]);
                if ($attempt > self::MAX_REGENERATIONS) {
                    $finalStatus = 'revisar';
                    break;
                }
            }
            Page::setResult((int) $page['id'], $finalStatus, $imageRel, $request->prompt, $check, $attempt);
            GenerationJob::setStatus((int) $job['id'], 'pronto', $finalStatus === 'revisar' ? 'Conferência reprovou após ' . $attempt . ' gerações; página marcada para revisão manual.' : null, true);
        } catch (AiException $e) {
            $this->fail($job, $page, $e->getMessage(), $e->retryable, $userId, $schoolId, $ref, $e->detail);
        } catch (Throwable $e) {
            Log::error('Worker: erro inesperado', ['job' => $job['id'], 'erro' => $e->getMessage(), 'arquivo' => $e->getFile(), 'linha' => $e->getLine()]);
            $this->fail($job, $page, 'Erro interno ao gerar a página. Detalhes no log.', true, $userId, $schoolId, $ref, $e->getMessage());
        }
        Project::refreshStatusFromPages((int) $project['id']);
    }

    private function verify(Verifier $verifier, string $file, array $expected, array $context, int $userId, ?int $schoolId, string $ref): array
    {
        try {
            $r = $verifier->verify($file, $expected, $context);
            UsageLog::record([
                'user_id' => $userId, 'school_id' => $schoolId, 'context' => 'conferencia', 'reference' => $ref,
                'engine' => $verifier->name(), 'model' => $verifier->model(), 'resolution' => 'visao',
                'input_tokens' => $r['input_tokens'], 'output_tokens' => $r['output_tokens'], 'duration_ms' => $r['duration_ms'], 'cost_usd' => $r['cost_usd'], 'status' => 'ok',
            ]);
            return $r;
        } catch (AiException $e) {
            // Conferência indisponível não derruba a geração: aprova com aviso.
            Log::warning('Conferência indisponível; página aprovada sem conferência', ['ref' => $ref, 'erro' => $e->getMessage()]);
            UsageLog::record(['user_id' => $userId, 'school_id' => $schoolId, 'context' => 'conferencia', 'reference' => $ref, 'engine' => $verifier->name(), 'model' => $verifier->model(), 'status' => 'erro', 'error_message' => $e->getMessage()]);
            return ['ok' => true, 'problemas' => [], 'textos_lidos' => [], 'aviso' => 'Conferência automática indisponível: ' . $e->getMessage(), 'input_tokens' => null, 'output_tokens' => null, 'cost_usd' => 0.0, 'duration_ms' => 0];
        }
    }

    private function fail(array $job, array $page, string $message, bool $retryable, int $userId, ?int $schoolId, string $ref, string $detail = ''): void
    {
        UsageLog::record(['user_id' => $userId, 'school_id' => $schoolId, 'context' => 'apostila', 'reference' => $ref, 'engine' => $this->engine()->name(), 'model' => $this->engine()->model(), 'status' => 'erro', 'error_message' => $message]);
        Log::error('Worker: falha na geração', ['job' => $job['id'], 'ref' => $ref, 'mensagem' => $message, 'detalhe' => $detail]);
        if ($retryable) {
            $novo = GenerationJob::retryLater($job, $message, $this->retryDelaySeconds * (int) $job['attempts']);
            Page::setStatus((int) $page['id'], $novo === 'erro' ? 'erro' : 'pendente');
        } else {
            GenerationJob::setStatus((int) $job['id'], 'erro', $message, true);
            Page::setStatus((int) $page['id'], 'erro');
        }
    }
}
