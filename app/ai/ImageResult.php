<?php
// app/ai/ImageResult.php
// Resultado de uma geração: bytes PNG, dimensões, tempo, tokens e custo estimado.
declare(strict_types=1);

namespace App\Ai;

final class ImageResult
{
    public function __construct(
        public readonly string $png,            // bytes da imagem
        public readonly int $width,
        public readonly int $height,
        public readonly string $engine,         // gemini | openai | mock
        public readonly string $model,
        public readonly int $durationMs,
        public readonly ?int $inputTokens,
        public readonly ?int $outputTokens,
        public readonly float $costUsd,
        public readonly int $attempts = 1,
        public readonly array $meta = [],
    ) {
    }

    public function resolution(): string
    {
        return $this->width . 'x' . $this->height;
    }

    /** Grava o PNG em disco (cria a pasta) e devolve o caminho. */
    public function save(string $path): string
    {
        $dir = dirname($path);
        // 0755/0644: o worker (cron/systemd) e o PHP da web podem rodar como usuários
        // diferentes; a pasta storage/ fica fora de public/, então leitura ampla é segura.
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new AiException('Não foi possível criar a pasta de destino da imagem.', $dir);
        }
        if (file_put_contents($path, $this->png) === false) {
            throw new AiException('Não foi possível gravar a imagem gerada.', $path);
        }
        @chmod($path, 0644);
        return $path;
    }

    /** Monta um resultado a partir dos bytes, lendo as dimensões do PNG. */
    public static function fromPng(string $png, string $engine, string $model, int $durationMs, ?int $in, ?int $out, float $cost, int $attempts = 1, array $meta = []): self
    {
        $info = @getimagesizefromstring($png);
        if ($info === false || ($info['mime'] ?? '') !== 'image/png') {
            throw new AiException(
                'O motor devolveu um arquivo que não é uma imagem PNG válida.',
                'mime=' . ($info['mime'] ?? 'desconhecido'),
                'resposta_invalida'
            );
        }
        return new self($png, (int) $info[0], (int) $info[1], $engine, $model, $durationMs, $in, $out, $cost, $attempts, $meta);
    }
}
