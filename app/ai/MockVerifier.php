<?php
// app/ai/MockVerifier.php
// Conferência simulada (AI_MOCK=1): aprova tudo, exceto quando o título da
// página contém SIMULAR_FALHA_CONFERENCIA — usado nos testes de regeneração.
declare(strict_types=1);

namespace App\Ai;

final class MockVerifier implements Verifier
{
    public const MARKER = 'SIMULAR_FALHA_CONFERENCIA';

    public function name(): string
    {
        return 'mock';
    }

    public function model(): string
    {
        return 'simulado:conferencia';
    }

    public function verify(string $pngPath, array $expected, array $context = []): array
    {
        $titulo = (string) ($context['titulo'] ?? '');
        $falha = str_contains($titulo, self::MARKER) || str_contains(implode(' ', $expected), self::MARKER);
        return [
            'ok'            => !$falha && is_file($pngPath),
            'problemas'     => $falha ? ['Texto do título diferente do aprovado (simulado): "' . mb_substr($titulo, 0, 40) . '"'] : (is_file($pngPath) ? [] : ['Arquivo da imagem não encontrado.']),
            'textos_lidos'  => $expected,
            'input_tokens'  => 0,
            'output_tokens' => 0,
            'cost_usd'      => 0.0,
            'duration_ms'   => 1,
        ];
    }
}
