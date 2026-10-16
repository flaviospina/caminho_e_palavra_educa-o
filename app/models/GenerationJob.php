<?php
// app/models/GenerationJob.php
// Fila de geração (tabela generation_jobs): criação, reivindicação atômica,
// novas tentativas, recuperação de jobs órfãos e consulta de estado.
declare(strict_types=1);

namespace App\Models;

use App\Database;
use PDO;

final class GenerationJob
{
    public const STATUS = ['pendente', 'gerando', 'conferindo', 'pronto', 'erro'];

    public static function find(int $id): ?array
    {
        $st = Database::get()->prepare('SELECT * FROM generation_jobs WHERE id = :id');
        $st->execute(['id' => $id]);
        $row = $st->fetch();
        return $row === false ? null : $row;
    }

    /** Cria um job de imagem para a página; cancela jobs pendentes anteriores da mesma página. */
    public static function enqueue(int $projectId, int $pageId, int $userId, string $jobType = 'imagem', array $payload = [], int $maxAttempts = 3): int
    {
        $pdo = Database::get();
        $pdo->prepare("UPDATE generation_jobs SET status = 'erro', error_message = 'Substituído por um novo pedido.', finished_at = NOW() WHERE page_id = :p AND status = 'pendente'")->execute(['p' => $pageId]);
        $st = $pdo->prepare('INSERT INTO generation_jobs (project_id, page_id, user_id, job_type, status, max_attempts, payload) VALUES (:pr, :pg, :u, :t, \'pendente\', :m, :pl)');
        $st->execute(['pr' => $projectId, 'pg' => $pageId, 'u' => $userId, 't' => $jobType, 'm' => $maxAttempts, 'pl' => $payload === [] ? null : json_encode($payload, JSON_UNESCAPED_UNICODE)]);
        return (int) $pdo->lastInsertId();
    }

    /**
     * Reivindica o próximo job pendente de forma atômica (SELECT … FOR UPDATE).
     * Devolve o job já marcado como "gerando" e bloqueado por este worker, ou null.
     */
    public static function claim(string $workerId): ?array
    {
        $pdo = Database::get();
        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare("SELECT id FROM generation_jobs WHERE status = 'pendente' AND available_at <= NOW() ORDER BY id LIMIT 1 FOR UPDATE");
            $st->execute();
            $id = $st->fetchColumn();
            if ($id === false) {
                $pdo->commit();
                return null;
            }
            $pdo->prepare("UPDATE generation_jobs SET status = 'gerando', locked_by = :w, locked_at = NOW(), started_at = NOW(), attempts = attempts + 1, error_message = NULL WHERE id = :id")
                ->execute(['w' => mb_substr($workerId, 0, 80), 'id' => (int) $id]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return self::find((int) $id);
    }

    public static function setStatus(int $id, string $status, ?string $error = null, bool $finished = false): void
    {
        $sql = 'UPDATE generation_jobs SET status = :s, error_message = :e' . ($finished ? ', finished_at = NOW(), locked_by = NULL, locked_at = NULL' : '') . ' WHERE id = :id';
        Database::get()->prepare($sql)->execute(['s' => $status, 'e' => $error === null ? null : mb_substr($error, 0, 500), 'id' => $id]);
    }

    /** Devolve o job à fila para nova tentativa após N segundos (ou marca erro se esgotou). */
    public static function retryLater(array $job, string $error, int $delaySeconds): string
    {
        if ((int) $job['attempts'] >= (int) $job['max_attempts']) {
            self::setStatus((int) $job['id'], 'erro', $error, true);
            return 'erro';
        }
        Database::get()->prepare("UPDATE generation_jobs SET status = 'pendente', error_message = :e, locked_by = NULL, locked_at = NULL, available_at = DATE_ADD(NOW(), INTERVAL :d SECOND) WHERE id = :id")
            ->execute(['e' => mb_substr($error, 0, 500), 'd' => max(0, $delaySeconds), 'id' => (int) $job['id']]);
        return 'pendente';
    }

    /**
     * Recupera jobs órfãos: presos em "gerando"/"conferindo" há mais de N minutos
     * (worker derrubado no meio). Voltam para a fila ou viram erro se esgotaram.
     * @return int quantidade recuperada
     */
    public static function recoverStale(int $staleMinutes): int
    {
        $pdo = Database::get();
        $st = $pdo->prepare("SELECT * FROM generation_jobs WHERE status IN ('gerando', 'conferindo') AND locked_at IS NOT NULL AND locked_at <= DATE_SUB(NOW(), INTERVAL :m MINUTE)");
        $st->execute(['m' => max(0, $staleMinutes)]);
        $n = 0;
        foreach ($st->fetchAll() as $job) {
            $msg = 'Geração interrompida (worker reiniciado); retomada automática.';
            if ((int) $job['attempts'] >= (int) $job['max_attempts']) {
                self::setStatus((int) $job['id'], 'erro', 'Geração interrompida repetidas vezes.', true);
                Page::setStatus((int) $job['page_id'], 'erro');
            } else {
                $pdo->prepare("UPDATE generation_jobs SET status = 'pendente', error_message = :e, locked_by = NULL, locked_at = NULL, available_at = NOW() WHERE id = :id")->execute(['e' => $msg, 'id' => (int) $job['id']]);
                Page::setStatus((int) $job['page_id'], 'pendente');
            }
            $n++;
        }
        return $n;
    }

    public static function forProject(int $projectId): array
    {
        $st = Database::get()->prepare('SELECT * FROM generation_jobs WHERE project_id = :p ORDER BY id');
        $st->execute(['p' => $projectId]);
        return $st->fetchAll();
    }

    /** Há jobs ainda não concluídos para o projeto? */
    public static function pendingForProject(int $projectId): int
    {
        $st = Database::get()->prepare("SELECT COUNT(*) FROM generation_jobs WHERE project_id = :p AND status IN ('pendente', 'gerando', 'conferindo')");
        $st->execute(['p' => $projectId]);
        return (int) $st->fetchColumn();
    }

    public static function queueCounts(): array
    {
        $rows = Database::get()->query('SELECT status, COUNT(*) AS n FROM generation_jobs GROUP BY status')->fetchAll(PDO::FETCH_KEY_PAIR);
        return array_map('intval', $rows);
    }
}
