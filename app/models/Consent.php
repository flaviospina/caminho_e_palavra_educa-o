<?php
// app/models/Consent.php
// Aceite do termo de uso: data, hora, IP e navegador, por versão do termo.
declare(strict_types=1);

namespace App\Models;

use App\Database;

final class Consent
{
    public static function hasCurrent(int $userId): bool
    {
        $st = Database::get()->prepare('SELECT id FROM consents WHERE user_id = :u AND term_version = :v');
        $st->execute(['u' => $userId, 'v' => TERM_VERSION]);
        return $st->fetchColumn() !== false;
    }

    public static function record(int $userId, string $ip, string $userAgent = ''): void
    {
        $st = Database::get()->prepare('INSERT INTO consents (user_id, term_version, ip, user_agent) VALUES (:u, :v, :ip, :ua)
            ON DUPLICATE KEY UPDATE accepted_at = CURRENT_TIMESTAMP, ip = VALUES(ip), user_agent = VALUES(user_agent)');
        $st->execute(['u' => $userId, 'v' => TERM_VERSION, 'ip' => mb_substr($ip, 0, 45), 'ua' => mb_substr($userAgent, 0, 255) ?: null]);
        AuditLog::record('termo_aceito', 'consents', $userId, ['versao' => TERM_VERSION], $userId, $ip);
    }

    public static function latest(int $userId): ?array
    {
        $st = Database::get()->prepare('SELECT * FROM consents WHERE user_id = :u ORDER BY accepted_at DESC LIMIT 1');
        $st->execute(['u' => $userId]);
        $row = $st->fetch();
        return $row === false ? null : $row;
    }
}
