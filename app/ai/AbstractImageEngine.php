<?php
// app/ai/AbstractImageEngine.php
// Base dos motores reais: novas tentativas com espera crescente, tempo limite
// configurável e registro em log. As subclasses só implementam callApi().
declare(strict_types=1);

namespace App\Ai;

use App\Log;

abstract class AbstractImageEngine implements ImageEngine
{
    protected HttpClient $http;

    /**
     * @param int   $timeoutSeconds tempo limite de cada chamada (padrão 360 s)
     * @param int   $retries        novas tentativas além da primeira (padrão 2)
     * @param float $backoffSeconds espera base: 1ª nova tentativa espera N s, 2ª espera 2N s…
     */
    public function __construct(
        protected string $apiKey,
        protected string $modelId,
        protected int $timeoutSeconds = 360,
        protected int $retries = 2,
        protected float $backoffSeconds = 5.0,
        ?HttpClient $http = null,
    ) {
        $this->http = $http ?? new HttpClient($timeoutSeconds);
    }

    public function model(): string
    {
        return $this->modelId;
    }

    /** Nome legível para mensagens ao professor (ex.: "Google Gemini"). */
    abstract protected function label(): string;

    /**
     * Uma chamada à API. Devolve [pngBytes, inputTokens, outputTokens, meta].
     * @return array{0:string,1:?int,2:?int,3:array}
     * @throws AiException
     */
    abstract protected function callApi(ImageRequest $request): array;

    /** Faixa de preço usada na estimativa por imagem (quando a API não devolve tokens). */
    abstract protected function priceTier(ImageRequest $request): string;

    public function generate(ImageRequest $request): ImageResult
    {
        if (trim($this->apiKey) === '') {
            throw new AiException(
                'A chave de API do ' . $this->label() . ' não está configurada no .env.',
                'apiKey vazia', 'chave_invalida'
            );
        }
        $start = microtime(true);
        $attempt = 0;
        $last = null;
        while (true) {
            $attempt++;
            try {
                [$png, $in, $out, $meta] = $this->callApi($request);
                $ms = (int) round((microtime(true) - $start) * 1000);
                $cost = Pricing::estimate($this->modelId, $in, $out, $this->priceTier($request));
                Log::info('Imagem gerada', ['motor' => $this->name(), 'modelo' => $this->modelId, 'tentativas' => $attempt, 'ms' => $ms, 'custo_usd' => $cost]);
                return ImageResult::fromPng($png, $this->name(), $this->modelId, $ms, $in, $out, $cost, $attempt, $meta);
            } catch (AiException $e) {
                $last = $e;
                Log::warning('Falha no motor de imagem', [
                    'motor' => $this->name(), 'modelo' => $this->modelId, 'tentativa' => $attempt,
                    'tipo' => $e->kind, 'detalhe' => $e->detail,
                ]);
                if (!$e->retryable || $attempt > $this->retries) {
                    break;
                }
                $wait = $this->backoffSeconds * $attempt;   // 5 s, 10 s…
                if ($wait > 0) {
                    usleep((int) ($wait * 1_000_000));
                }
            }
        }
        $sufixo = $attempt > 1 ? " (após {$attempt} tentativas)" : '';
        throw new AiException($last->getMessage() . $sufixo, $last->detail, $last->kind, false, $last);
    }
}
