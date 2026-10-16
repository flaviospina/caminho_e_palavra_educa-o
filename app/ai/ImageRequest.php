<?php
// app/ai/ImageRequest.php
// Pedido de imagem já com o prompt final. O formato "A4" vira a proporção mais
// próxima suportada por cada motor (2:3 / 1024x1536).
declare(strict_types=1);

namespace App\Ai;

final class ImageRequest
{
    public const FORMATS = ['A4', 'STORY', 'FEED'];   // retrato 2:3, 9:16, 1:1

    public function __construct(
        public readonly string $prompt,
        public readonly string $format = 'A4',
        public readonly string $quality = 'high',    // low | medium | high (OpenAI) · 1K | 2K | 4K (Gemini)
        public readonly string $label = '',          // identificação livre (ex.: "ef04_ciencias p1")
        public readonly array $context = [],         // dados estruturados da página (título, blocos…) usados pelo motor simulado
    ) {
        if (!in_array($format, self::FORMATS, true)) {
            throw new AiException('Formato de imagem desconhecido: ' . $format, '', 'erro');
        }
    }

    /** Proporção Gemini (aspectRatio). */
    public function gemini_aspect(): string
    {
        return match ($this->format) {
            'STORY' => '9:16',
            'FEED'  => '1:1',
            default => '2:3',
        };
    }

    /** Tamanho OpenAI (largura x altura). */
    public function openai_size(): string
    {
        return match ($this->format) {
            'STORY' => '1024x1792',   // 9:16 (múltiplo de 16, dentro dos limites do gpt-image-2.5)
            'FEED'  => '1024x1024',
            default => '1024x1536',
        };
    }

    /** Dimensões do PNG simulado (largura, altura). */
    public function mock_size(): array
    {
        return match ($this->format) {
            'STORY' => [1080, 1920],
            'FEED'  => [1080, 1080],
            default => [1024, 1536],
        };
    }
}
