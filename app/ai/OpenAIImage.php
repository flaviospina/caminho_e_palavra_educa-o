<?php
// app/ai/OpenAIImage.php
// Adaptador da OpenAI (Images API /v1/images/generations).
// Modelos: gpt-image-2.5-flare (padrão, rápido) e gpt-image-2.5-sunburst (qualidade).
declare(strict_types=1);

namespace App\Ai;

final class OpenAIImage extends AbstractImageEngine
{
    public const ENDPOINT = 'https://api.openai.com/v1/images/generations';

    public function name(): string
    {
        return 'openai';
    }

    protected function label(): string
    {
        return 'OpenAI';
    }

    protected function priceTier(ImageRequest $request): string
    {
        return self::quality($request->quality);
    }

    /** low | medium | high (xhigh/max existem no 2.5, mas custam mais; mapeamos para high). */
    public static function quality(string $quality): string
    {
        return match (strtolower($quality)) {
            'low', '1k', '0.5k' => 'low',
            'medium', '2k' => 'medium',
            default => 'high',
        };
    }

    /** Corpo da requisição (público para os testes). */
    public function buildBody(ImageRequest $request): array
    {
        return [
            'model'         => $this->modelId,
            'prompt'        => $request->prompt,
            'n'             => 1,
            'size'          => $request->openai_size(),
            'quality'       => self::quality($request->quality),
            'output_format' => 'png',
        ];
    }

    protected function callApi(ImageRequest $request): array
    {
        $data = $this->http->postJson(self::ENDPOINT, $this->buildBody($request), ['Authorization' => 'Bearer ' . $this->apiKey], $this->label());
        return self::parseResponse($data, $this->label());
    }

    /** Extrai o PNG e os tokens da resposta (público para os testes). */
    public static function parseResponse(array $data, string $label = 'OpenAI'): array
    {
        $b64 = $data['data'][0]['b64_json'] ?? null;
        if (!is_string($b64) || $b64 === '') {
            throw new AiException("A {$label} não devolveu imagem na resposta.", json_encode(array_keys($data)), 'resposta_invalida', true);
        }
        $bytes = base64_decode($b64, true);
        if ($bytes === false) {
            throw new AiException("A {$label} devolveu uma imagem corrompida (base64 inválido).", '', 'resposta_invalida', true);
        }
        $usage = $data['usage'] ?? [];
        return [
            $bytes,
            isset($usage['input_tokens']) ? (int) $usage['input_tokens'] : null,
            isset($usage['output_tokens']) ? (int) $usage['output_tokens'] : null,
            ['output_format' => $data['output_format'] ?? 'png', 'created' => $data['created'] ?? null],
        ];
    }
}
