<?php
// app/ai/TextEngine.php
// Contrato do motor de texto: sempre devolve JSON (array PHP já decodificado).
// Na Fase 1 só o MockEngine o implementa; o adaptador real chega na Fase 3.
declare(strict_types=1);

namespace App\Ai;

interface TextEngine
{
    public function name(): string;

    public function model(): string;

    /**
     * @param string $systemPrompt instruções fixas (papel, esquema, regras)
     * @param string $userPrompt   pedido do professor já montado
     * @return array JSON decodificado conforme o esquema da seção 5 do prompt-mestre
     */
    public function generateJson(string $systemPrompt, string $userPrompt): array;
}
