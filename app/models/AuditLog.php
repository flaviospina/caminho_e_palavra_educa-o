<?php
// app/models/AuditLog.php
// Trilha de auditoria: quem, quando, o quê (nunca grava senhas).
declare(strict_types=1);

namespace App\Models;

use App\Auth;
use App\Database;
use App\Log;
use App\Request;
use Throwable;

final class AuditLog
{
    public static function record(string $action, ?string $entity = null, ?int $entityId = null, ?array $details = null, ?int $userId = null, ?string $ip = null): void
    {
        try {
            $userId ??= Auth::id();
            if ($details !== null) {
                unset($details['password'], $details['senha'], $details['password_hash']);
            }
            $st = Database::get()->prepare('INSERT INTO audit_log (user_id, action, entity, entity_id, details, ip, user_agent) VALUES (:u, :a, :e, :eid, :d, :ip, :ua)');
            $st->execute([
                'u'   => $userId,
                'a'   => mb_substr($action, 0, 60),
                'e'   => $entity === null ? null : mb_substr($entity, 0, 40),
                'eid' => $entityId,
                'd'   => $details === null ? null : json_encode($details, JSON_UNESCAPED_UNICODE),
                'ip'  => $ip !== null && $ip !== '' ? mb_substr($ip, 0, 45) : (PHP_SAPI === 'cli' ? null : Request::ip()),
                'ua'  => PHP_SAPI === 'cli' ? null : Request::userAgent(),
            ]);
        } catch (Throwable $e) {
            Log::error('Falha ao gravar audit_log', ['acao' => $action, 'erro' => $e->getMessage()]);
        }
    }

    public static function recent(int $limit = 50, ?int $userId = null): array
    {
        $sql = 'SELECT a.*, u.name AS user_name FROM audit_log a LEFT JOIN users u ON u.id = a.user_id';
        $params = [];
        if ($userId !== null) {
            $sql .= ' WHERE a.user_id = :u';
            $params['u'] = $userId;
        }
        $sql .= ' ORDER BY a.id DESC LIMIT ' . (int) $limit;
        $st = Database::get()->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }
}
