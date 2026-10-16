<?php
// app/ai/Verifier.php
// Conferência automática: um modelo com visão lê a imagem gerada e compara os
// textos com o conteúdo aprovado. Devolve ['ok' => bool, 'problemas' => string[], ...].
declare(strict_types=1);

namespace App\Ai;

interface Verifier
{
    public function name(): string;

    public function model(): string;

    /**
     * @param string   $pngPath   caminho da imagem gerada
     * @param string[] $expected  textos que DEVEM aparecer (título, blocos, rodapé…)
     * @param array    $context   dados da página (para o modo simulado)
     * @return array{ok:bool, problemas:string[], textos_lidos:string[], input_tokens:?int, output_tokens:?int, cost_usd:float, duration_ms:int}
     */
    public function verify(string $pngPath, array $expected, array $context = []): array;
}
