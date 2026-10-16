<?php
// app/ai/AiException.php
// Erro de um motor de IA: mensagem amigável em português para o professor e
// detalhe técnico para o log. Nunca derruba o script que a captura.
declare(strict_types=1);

namespace App\Ai;

use RuntimeException;
use Throwable;

final class AiException extends RuntimeException
{
    public function __construct(
        string $userMessage,
        public readonly string $detail = '',
        public readonly string $kind = 'erro',   // chave_invalida | cota | tempo_limite | resposta_invalida | rede | erro
        public readonly bool $retryable = false,
        ?Throwable $previous = null,
    ) {
        parent::__construct($userMessage, 0, $previous);
    }
}
