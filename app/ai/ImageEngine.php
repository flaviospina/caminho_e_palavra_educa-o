<?php
// app/ai/ImageEngine.php
// Contrato de todo motor de imagem (Gemini, OpenAI, simulado).
declare(strict_types=1);

namespace App\Ai;

interface ImageEngine
{
    /** Nome curto do motor: gemini | openai | mock. */
    public function name(): string;

    /** ID do modelo configurado (ex.: gemini-3-pro-image). */
    public function model(): string;

    /**
     * Gera UMA imagem. Em caso de falha definitiva lança AiException com
     * mensagem em português; as novas tentativas acontecem internamente.
     */
    public function generate(ImageRequest $request): ImageResult;
}
