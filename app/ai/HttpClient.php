<?php
// app/ai/HttpClient.php
// Cliente HTTP mínimo (cURL) para as APIs de IA: JSON, tempo limite e
// tradução dos erros HTTP em AiException com mensagem em português.
declare(strict_types=1);

namespace App\Ai;

final class HttpClient
{
    public function __construct(private int $timeoutSeconds = 360)
    {
    }

    /**
     * POST JSON. Devolve o corpo decodificado.
     * @param array<string,string> $headers
     * @throws AiException
     */
    public function postJson(string $url, array $body, array $headers, string $engineLabel): array
    {
        $json = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new AiException('Não foi possível montar a requisição para a IA.', json_last_error_msg());
        }
        $ch = curl_init($url);
        if ($ch === false) {
            throw new AiException('cURL indisponível no servidor.', '', 'erro');
        }
        $hdr = ['Content-Type: application/json', 'Accept: application/json'];
        foreach ($headers as $k => $v) {
            $hdr[] = $k . ': ' . $v;
        }
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $json,
            CURLOPT_HTTPHEADER     => $hdr,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_TIMEOUT        => $this->timeoutSeconds,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT      => 'MateriaisVisuaisCECAPE/' . APP_VERSION,
        ]);
        $response = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($response === false || $errno !== 0) {
            if (in_array($errno, [CURLE_OPERATION_TIMEDOUT, 28], true)) {
                throw new AiException(
                    "O {$engineLabel} demorou mais que o tempo limite de {$this->timeoutSeconds} s e a geração foi interrompida.",
                    "curl {$errno}: {$error}",
                    'tempo_limite',
                    true
                );
            }
            throw new AiException(
                "Não foi possível conectar ao {$engineLabel}. Verifique a conexão do servidor com a internet.",
                "curl {$errno}: {$error}",
                'rede',
                true
            );
        }

        $data = json_decode((string) $response, true);
        if ($status >= 200 && $status < 300) {
            if (!is_array($data)) {
                throw new AiException("O {$engineLabel} devolveu uma resposta que não é JSON.", substr((string) $response, 0, 300), 'resposta_invalida');
            }
            return $data;
        }
        throw self::fromHttpStatus($status, is_array($data) ? $data : [], (string) $response, $engineLabel);
    }

    /** Mapeia o status HTTP para uma mensagem clara em português. */
    public static function fromHttpStatus(int $status, array $data, string $raw, string $engineLabel): AiException
    {
        $apiMsg = (string) ($data['error']['message'] ?? $data['message'] ?? substr($raw, 0, 300));
        $detail = "HTTP {$status}: {$apiMsg}";
        return match (true) {
            $status === 401 || $status === 403 => new AiException(
                "A chave de API do {$engineLabel} é inválida ou não tem permissão. Confira o valor no arquivo .env.",
                $detail, 'chave_invalida', false
            ),
            $status === 429 => new AiException(
                "O {$engineLabel} recusou a chamada por limite de uso ou cota esgotada. Aguarde alguns minutos ou verifique o saldo da conta.",
                $detail, 'cota', true
            ),
            $status === 400 || $status === 404 || $status === 422 => new AiException(
                "O {$engineLabel} rejeitou o pedido (modelo inexistente ou parâmetro inválido). Detalhe: " . mb_substr($apiMsg, 0, 160),
                $detail, 'resposta_invalida', false
            ),
            $status >= 500 => new AiException(
                "O {$engineLabel} está instável no momento (erro {$status}). Tente novamente em instantes.",
                $detail, 'erro', true
            ),
            default => new AiException(
                "O {$engineLabel} devolveu um erro inesperado ({$status}).",
                $detail, 'erro', false
            ),
        };
    }
}
