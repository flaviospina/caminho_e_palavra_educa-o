<?php
// app/models/PasswordReset.php
// Tokens de recuperação de senha: só o hash fica no banco; validade de 1 hora.
declare(strict_types=1);

namespace App\Models;

use App\Database;

final class PasswordReset
{
    public const TTL_MINUTES = 60;

    /** Cria um token para o usuário e devolve o valor em claro (vai no e-mail). */
    public static function create(int $userId, string $ip = ''): string
    {
        $token = bin2hex(random_bytes(32));
        $pdo = Database::get();
        $pdo->prepare('UPDATE password_resets SET used_at = NOW() WHERE user_id = :u AND used_at IS NULL')->execute(['u' => $userId]);
        $st = $pdo->prepare('INSERT INTO password_resets (user_id, token_hash, expires_at, created_ip) VALUES (:u, :h, :e, :ip)');
        $st->execute(['u' => $userId, 'h' => hash('sha256', $token), 'e' => date('Y-m-d H:i:s', time() + self::TTL_MINUTES * 60), 'ip' => $ip ?: null]);
        return $token;
    }

    /** Devolve o user_id de um token válido (não usado e não expirado) ou null. */
    public static function userIdFor(string $token): ?int
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }
        $st = Database::get()->prepare('SELECT user_id FROM password_resets WHERE token_hash = :h AND used_at IS NULL AND expires_at > NOW()');
        $st->execute(['h' => hash('sha256', $token)]);
        $id = $st->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    public static function consume(string $token): void
    {
        Database::get()->prepare('UPDATE password_resets SET used_at = NOW() WHERE token_hash = :h')->execute(['h' => hash('sha256', $token)]);
    }
}
