<?php
// app/ai/GeminiVerifier.php
// Conferência com visão: envia a imagem ao Gemini e pede, em JSON, a lista de
// textos lidos e as divergências em relação ao conteúdo aprovado.
declare(strict_types=1);

namespace App\Ai;

final class GeminiVerifier implements Verifier
{
    public const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent';

    private HttpClient $http;

    public function __construct(
        private string $apiKey,
        private string $modelId = 'gemini-3.8-flash',
        private int $timeoutSeconds = 120,
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

    /** Prompt de conferência (público para os testes). */
    public static function prompt(array $expected): string
    {
        $lista = implode("\n", array_map(fn($t) => '- ' . $t, $expected));
        return <<<TXT
Você é um revisor de material didático em português do Brasil. A imagem é uma página A4 gerada por IA.
Tarefa: leia TODOS os textos visíveis na imagem e compare com a lista de textos esperados abaixo.
Considere PROBLEMA: texto esperado ausente; palavra com grafia errada ou acento faltando; texto extra que não está na lista (exceto pequenos rótulos decorativos); número de página errado.
Ignore diferenças só de maiúsculas/minúsculas, pontuação final e quebras de linha.
Responda SOMENTE com JSON: {"ok": true|false, "problemas": ["descrição curta de cada problema"], "textos_lidos": ["..."]}

Textos esperados:
{$lista}
TXT;
    }

    public function buildBody(string $pngPath, array $expected): array
    {
        return [
            'contents' => [['role' => 'user', 'parts' => [
                ['text' => self::prompt($expected)],
                ['inlineData' => ['mimeType' => 'image/png', 'data' => base64_encode((string) file_get_contents($pngPath))]],
            ]]],
            'generationConfig' => ['responseMimeType' => 'application/json', 'temperature' => 0.1, 'maxOutputTokens' => 4096],
        ];
    }

    public function verify(string $pngPath, array $expected, array $context = []): array
    {
        if (trim($this->apiKey) === '') {
            throw new AiException('A chave de API do Google Gemini não está configurada no .env.', 'apiKey vazia', 'chave_invalida');
        }
        if (!is_file($pngPath)) {
            throw new AiException('Imagem para conferência não encontrada.', $pngPath, 'erro');
        }
        $start = microtime(true);
        $url = sprintf(self::ENDPOINT, rawurlencode($this->modelId));
        $data = $this->http->postJson($url, $this->buildBody($pngPath, $expected), ['x-goog-api-key' => $this->apiKey], 'Google Gemini (conferência)');
        $json = GeminiText::parseResponse($data);
        $usage = $data['usageMetadata'] ?? [];
        $in = (int) ($usage['promptTokenCount'] ?? 0);
        $out = (int) ($usage['candidatesTokenCount'] ?? 0) + (int) ($usage['thoughtsTokenCount'] ?? 0);
        return [
            'ok'            => (bool) ($json['ok'] ?? false),
            'problemas'     => array_values(array_map('strval', (array) ($json['problemas'] ?? []))),
            'textos_lidos'  => array_values(array_map('strval', (array) ($json['textos_lidos'] ?? []))),
            'input_tokens'  => $in,
            'output_tokens' => $out,
            'cost_usd'      => Pricing::estimateText($this->modelId, $in, $out),
            'duration_ms'   => (int) round((microtime(true) - $start) * 1000),
        ];
    }
}
