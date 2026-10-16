<?php
// app/ai/Pricing.php
// Preços de referência (US$) dos modelos de imagem, usados para estimar o custo
// de cada chamada. Quando a API devolve a contagem de tokens, o custo é
// calculado por token; senão, usa-se o valor por imagem desta tabela.
// Fontes: documentação oficial Gemini/OpenAI conforme consultada em 16/10/2026
// (ver PROGRESSO.md — "Modelos e preços"). Revise ao trocar de modelo.
declare(strict_types=1);

namespace App\Ai;

final class Pricing
{
    /**
     * US$ por 1 milhão de tokens de SAÍDA de imagem e custo por imagem de reserva.
     * @var array<string, array{output_per_m: float, input_text_per_m: float, per_image: array<string,float>}>
     */
    public const MODELS = [
        // Google Gemini (Nano Banana Pro, GA desde 28/05/2026; o -preview foi desligado em 25/06/2026)
        'gemini-3-pro-image' => [
            'output_per_m'     => 120.0,
            'input_text_per_m' => 2.0,
            'per_image'        => ['1K' => 0.134, '2K' => 0.134, '4K' => 0.24],
        ],
        // Google Gemini 3.1 Flash Image (Nano Banana 2)
        'gemini-3.1-flash-image' => [
            'output_per_m'     => 60.0,
            'input_text_per_m' => 0.5,
            'per_image'        => ['0.5K' => 0.045, '1K' => 0.067, '2K' => 0.101, '4K' => 0.151],
        ],
        // Google Gemini 3.1 Flash Lite Image (Nano Banana 2 Lite)
        'gemini-3.1-flash-lite-image' => [
            'output_per_m'     => 30.0,
            'input_text_per_m' => 0.25,
            'per_image'        => ['1K' => 0.0336],
        ],
        // OpenAI GPT Image 2.5 (lançado em 08/09/2026): flare = rápido, sunburst = qualidade.
        // Mesma tabela de preço por token; custo por imagem varia com qualidade/tamanho.
        'gpt-image-2.5-flare' => [
            'output_per_m'     => 30.0,
            'input_text_per_m' => 5.0,
            'per_image'        => ['low' => 0.012, 'medium' => 0.048, 'high' => 0.187],
        ],
        'gpt-image-2.5-sunburst' => [
            'output_per_m'     => 30.0,
            'input_text_per_m' => 5.0,
            'per_image'        => ['low' => 0.012, 'medium' => 0.048, 'high' => 0.187],
        ],
    ];

    /**
     * Modelos de TEXTO: US$ por 1 milhão de tokens de entrada e de saída.
     * gemini-3.8-flash: 3.7-flash foi descontinuado e é redirecionado para 3.8 (out/2026);
     * valor de referência do 3.7 (introdutório até 31/12/2026) — confirmar na página oficial.
     */
    public const TEXT_MODELS = [
        'gemini-3.8-flash' => ['input_per_m' => 0.75, 'output_per_m' => 3.75],
        'gemini-3.7-flash' => ['input_per_m' => 0.75, 'output_per_m' => 3.75],
        'gemini-3.5-flash' => ['input_per_m' => 1.50, 'output_per_m' => 9.00],
        'gemini-3.1-pro'   => ['input_per_m' => 2.00, 'output_per_m' => 12.00],
    ];

    public static function estimateText(string $model, int $inputTokens, int $outputTokens): float
    {
        $p = self::TEXT_MODELS[$model] ?? null;
        if ($p === null) {
            return 0.0;
        }
        return round($inputTokens * $p['input_per_m'] / 1_000_000 + $outputTokens * $p['output_per_m'] / 1_000_000, 5);
    }

    /** Custo estimado: por tokens quando disponíveis, senão por imagem. */
    public static function estimate(string $model, ?int $inputTokens, ?int $outputTokens, string $tier): float
    {
        $p = self::MODELS[$model] ?? null;
        if ($p === null) {
            return 0.0;
        }
        if ($outputTokens !== null && $outputTokens > 0) {
            $cost = $outputTokens * $p['output_per_m'] / 1_000_000;
            $cost += (int) $inputTokens * $p['input_text_per_m'] / 1_000_000;
            return round($cost, 5);
        }
        return round($p['per_image'][$tier] ?? (float) (array_values($p['per_image'])[0] ?? 0.0), 5);
    }

    public static function known(string $model): bool
    {
        return isset(self::MODELS[$model]);
    }
}
