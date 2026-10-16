<?php
// app/ai/GeminiImage.php
// Adaptador do Google Gemini (API generateContent com saída de imagem).
// Modelos: gemini-3-pro-image (padrão), gemini-3.1-flash-image, gemini-3.1-flash-lite-image.
declare(strict_types=1);

namespace App\Ai;

final class GeminiImage extends AbstractImageEngine
{
    public const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent';

    public function name(): string
    {
        return 'gemini';
    }

    protected function label(): string
    {
        return 'Google Gemini';
    }

    /** 1K | 2K | 4K (apenas o Pro aceita imageSize; o Flash ignora). */
    protected function priceTier(ImageRequest $request): string
    {
        return self::imageSize($request->quality);
    }

    public static function imageSize(string $quality): string
    {
        return match (strtolower($quality)) {
            'low', '1k', '0.5k' => '1K',
            'medium', '2k', 'high' => '2K',
            '4k', 'max', 'xhigh' => '4K',
            default => '2K',
        };
    }

    /** Corpo da requisição (público para os testes). */
    public function buildBody(ImageRequest $request): array
    {
        $imageConfig = ['aspectRatio' => $request->gemini_aspect()];
        if (str_contains($this->modelId, 'pro-image')) {
            $imageConfig['imageSize'] = self::imageSize($request->quality);
        }
        return [
            'contents' => [['parts' => [['text' => $request->prompt]]]],
            'generationConfig' => [
                'responseModalities' => ['IMAGE'],
                'imageConfig'        => $imageConfig,
            ],
        ];
    }

    protected function callApi(ImageRequest $request): array
    {
        $url = sprintf(self::ENDPOINT, rawurlencode($this->modelId));
        $data = $this->http->postJson($url, $this->buildBody($request), ['x-goog-api-key' => $this->apiKey], $this->label());
        return self::parseResponse($data, $this->label());
    }

    /** Extrai o PNG e os tokens da resposta (público para os testes). */
    public static function parseResponse(array $data, string $label = 'Google Gemini'): array
    {
        $parts = $data['candidates'][0]['content']['parts'] ?? [];
        foreach ($parts as $part) {
            $inline = $part['inlineData'] ?? $part['inline_data'] ?? null;
            if (is_array($inline) && isset($inline['data'])) {
                $bytes = base64_decode((string) $inline['data'], true);
                if ($bytes === false) {
                    throw new AiException("O {$label} devolveu uma imagem corrompida (base64 inválido).", '', 'resposta_invalida', true);
                }
                $mime = (string) ($inline['mimeType'] ?? $inline['mime_type'] ?? 'image/png');
                if ($mime !== 'image/png') {
                    $bytes = self::toPng($bytes, $label);
                }
                $usage = $data['usageMetadata'] ?? [];
                return [
                    $bytes,
                    isset($usage['promptTokenCount']) ? (int) $usage['promptTokenCount'] : null,
                    isset($usage['candidatesTokenCount']) ? (int) $usage['candidatesTokenCount'] : null,
                    ['finishReason' => $data['candidates'][0]['finishReason'] ?? null, 'modelVersion' => $data['modelVersion'] ?? null],
                ];
            }
        }
        $reason = (string) ($data['candidates'][0]['finishReason'] ?? $data['promptFeedback']['blockReason'] ?? 'sem imagem');
        throw new AiException(
            "O {$label} não devolveu imagem (motivo: {$reason}). Revise o texto da página e tente de novo.",
            json_encode($data['promptFeedback'] ?? $data['candidates'][0]['finishReason'] ?? [], JSON_UNESCAPED_UNICODE),
            'resposta_invalida',
            $reason === 'sem imagem'
        );
    }

    /** Converte JPEG/WebP para PNG com GD (o pipeline guarda sempre PNG). */
    public static function toPng(string $bytes, string $label): string
    {
        $img = @imagecreatefromstring($bytes);
        if ($img === false) {
            throw new AiException("O {$label} devolveu uma imagem em formato não reconhecido.", '', 'resposta_invalida', true);
        }
        ob_start();
        imagepng($img, null, 6);
        imagedestroy($img);
        return (string) ob_get_clean();
    }
}
