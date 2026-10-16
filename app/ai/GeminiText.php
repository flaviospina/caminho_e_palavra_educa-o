<?php
// app/ai/GeminiText.php
// Adaptador do Google Gemini para texto com saída JSON garantida
// (generateContent com responseMimeType application/json).
// Modelo padrão: gemini-3.8-flash (ver PROGRESSO.md — "Modelos e preços").
declare(strict_types=1);

namespace App\Ai;

use App\Log;

final class GeminiText implements TextEngine
{
    public const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent';

    private HttpClient $http;
    /** @var array{input:int,output:int,cost:float,ms:int,attempts:int} última chamada */
    private array $last = ['input' => 0, 'output' => 0, 'cost' => 0.0, 'ms' => 0, 'attempts' => 0];

    public function __construct(
        private string $apiKey,
        private string $modelId = 'gemini-3.8-flash',
        private int $timeoutSeconds = 120,
        private int $retries = 2,
        private float $backoffSeconds = 3.0,
        ?HttpClient $http = null,
    ) {
        $this->http = $http ?? new HttpClient($timeoutSeconds);
    }

    public function name(): string
    {
        return 'gemini';
    }

    public function model(): string
    {
        return $this->modelId;
    }

    /** Dados da última chamada (tokens, custo, tempo) para o usage_log. */
    public function lastUsage(): array
    {
        return $this->last;
    }

    /** Corpo da requisição (público para os testes). */
    public function buildBody(string $systemPrompt, string $userPrompt): array
    {
        return [
            'systemInstruction' => ['parts' => [['text' => $systemPrompt]]],
            'contents'          => [['role' => 'user', 'parts' => [['text' => $userPrompt]]]],
            'generationConfig'  => [
                'responseMimeType' => 'application/json',
                'temperature'      => 0.7,
                'maxOutputTokens'  => 8192,
            ],
        ];
    }

    public function generateJson(string $systemPrompt, string $userPrompt): array
    {
        if (trim($this->apiKey) === '') {
            throw new AiException('A chave de API do Google Gemini não está configurada no .env.', 'apiKey vazia', 'chave_invalida');
        }
        $start = microtime(true);
        $attempt = 0;
        $last = null;
        while (true) {
            $attempt++;
            try {
                $url = sprintf(self::ENDPOINT, rawurlencode($this->modelId));
                $data = $this->http->postJson($url, $this->buildBody($systemPrompt, $userPrompt), ['x-goog-api-key' => $this->apiKey], 'Google Gemini');
                $json = self::parseResponse($data);
                $usage = $data['usageMetadata'] ?? [];
                $in = (int) ($usage['promptTokenCount'] ?? 0);
                $out = (int) ($usage['candidatesTokenCount'] ?? 0) + (int) ($usage['thoughtsTokenCount'] ?? 0);
                $this->last = [
                    'input' => $in, 'output' => $out,
                    'cost' => Pricing::estimateText($this->modelId, $in, $out),
                    'ms' => (int) round((microtime(true) - $start) * 1000), 'attempts' => $attempt,
                ];
                return $json;
            } catch (AiException $e) {
                $last = $e;
                Log::warning('Falha no motor de texto', ['modelo' => $this->modelId, 'tentativa' => $attempt, 'tipo' => $e->kind, 'detalhe' => $e->detail]);
                if (!$e->retryable || $attempt > $this->retries) {
                    break;
                }
                usleep((int) ($this->backoffSeconds * $attempt * 1_000_000));
            }
        }
        throw new AiException($last->getMessage() . ($attempt > 1 ? " (após {$attempt} tentativas)" : ''), $last->detail, $last->kind, false, $last);
    }

    /** Extrai e decodifica o JSON da resposta (público para os testes). */
    public static function parseResponse(array $data): array
    {
        $text = '';
        foreach ($data['candidates'][0]['content']['parts'] ?? [] as $part) {
            if (isset($part['text'])) {
                $text .= $part['text'];
            }
        }
        $text = trim($text);
        if ($text === '') {
            $reason = (string) ($data['candidates'][0]['finishReason'] ?? $data['promptFeedback']['blockReason'] ?? 'vazio');
            throw new AiException("O Google Gemini não devolveu conteúdo (motivo: {$reason}). Tente de novo ou ajuste o tema.", json_encode($data['promptFeedback'] ?? [], JSON_UNESCAPED_UNICODE), 'resposta_invalida', true);
        }
        $json = self::decodeJson($text);
        if ($json === null) {
            throw new AiException('O Google Gemini devolveu um texto que não é JSON válido. Tente gerar de novo.', mb_substr($text, 0, 300), 'resposta_invalida', true);
        }
        return $json;
    }

    /** Aceita JSON puro ou envolto em ```json … ```. */
    public static function decodeJson(string $text): ?array
    {
        $text = trim($text);
        if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/s', $text, $m)) {
            $text = $m[1];
        }
        $json = json_decode($text, true);
        if (!is_array($json)) {
            $ini = strpos($text, '{');
            $fim = strrpos($text, '}');
            if ($ini !== false && $fim !== false && $fim > $ini) {
                $json = json_decode(substr($text, $ini, $fim - $ini + 1), true);
            }
        }
        return is_array($json) ? $json : null;
    }
}
