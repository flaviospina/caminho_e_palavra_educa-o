<?php
// app/models/UsageLog.php
// Grava cada chamada aos motores de IA em usage_log (custo estimado em US$).
declare(strict_types=1);

namespace App\Models;

use App\Database;
use App\Log;
use PDO;
use Throwable;

final class UsageLog
{
    /**
     * @param array{context?:string,reference?:string,engine:string,model:string,resolution?:string,prompt_chars?:int,input_tokens?:?int,output_tokens?:?int,duration_ms?:int,cost_usd?:float,status?:string,error_message?:?string,user_id?:?int,school_id?:?int} $row
     * @return int id inserido (0 se a gravação falhou — o erro vai para o log)
     */
    public static function record(array $row, ?PDO $pdo = null): int
    {
        $sql = 'INSERT INTO usage_log (user_id, school_id, context, reference, engine, model, resolution, prompt_chars,
                    input_tokens, output_tokens, duration_ms, cost_usd, status, error_message)
                VALUES (:user_id, :school_id, :context, :reference, :engine, :model, :resolution, :prompt_chars,
                    :input_tokens, :output_tokens, :duration_ms, :cost_usd, :status, :error_message)';
        try {
            $pdo ??= Database::get();
            $st = $pdo->prepare($sql);
            $st->execute([
                'user_id'       => $row['user_id'] ?? null,
                'school_id'     => $row['school_id'] ?? null,
                'context'       => $row['context'] ?? 'poc',
                'reference'     => isset($row['reference']) ? mb_substr((string) $row['reference'], 0, 120) : null,
                'engine'        => mb_substr($row['engine'], 0, 40),
                'model'         => mb_substr($row['model'], 0, 80),
                'resolution'    => isset($row['resolution']) ? mb_substr((string) $row['resolution'], 0, 20) : null,
                'prompt_chars'  => (int) ($row['prompt_chars'] ?? 0),
                'input_tokens'  => $row['input_tokens'] ?? null,
                'output_tokens' => $row['output_tokens'] ?? null,
                'duration_ms'   => (int) ($row['duration_ms'] ?? 0),
                'cost_usd'      => number_format((float) ($row['cost_usd'] ?? 0), 5, '.', ''),
                'status'        => ($row['status'] ?? 'ok') === 'ok' ? 'ok' : 'erro',
                'error_message' => isset($row['error_message']) ? mb_substr((string) $row['error_message'], 0, 500) : null,
            ]);
            return (int) $pdo->lastInsertId();
        } catch (Throwable $e) {
            Log::error('Falha ao gravar usage_log', ['erro' => $e->getMessage()]);
            return 0;
        }
    }

    /** Verifica se a tabela existe (para orientar a importação do schema.sql). */
    public static function tableExists(?PDO $pdo = null): bool
    {
        try {
            ($pdo ?? Database::get())->query('SELECT 1 FROM usage_log LIMIT 1');
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /** Últimos registros (para a página /poc). */
    public static function recent(int $limit = 20, ?string $reference = null): array
    {
        try {
            $pdo = Database::get();
            if ($reference !== null) {
                $st = $pdo->prepare('SELECT * FROM usage_log WHERE reference = :r ORDER BY id DESC LIMIT ' . (int) $limit);
                $st->execute(['r' => $reference]);
            } else {
                $st = $pdo->query('SELECT * FROM usage_log ORDER BY id DESC LIMIT ' . (int) $limit);
            }
            return $st->fetchAll();
        } catch (Throwable) {
            return [];
        }
    }
}
