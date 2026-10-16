<?php
// app/Session.php
// Inicia a sessão com cookies seguros (HttpOnly, SameSite=Lax, Secure em HTTPS).
declare(strict_types=1);

namespace App;

final class Session
{
    public static function start(): void
    {
        if (PHP_SAPI === 'cli') {
            // Nos testes e nas ferramentas de linha de comando a sessão é um array simples.
            if (!isset($_SESSION) || !is_array($_SESSION)) {
                $_SESSION = [];
            }
            return;
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || str_starts_with(BASE_URL, 'https://');
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => (BASE_PATH !== '' ? BASE_PATH : '') . '/',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_name('materiais_sess');
        session_start();
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public static function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public static function forget(string $key): void
    {
        unset($_SESSION[$key]);
    }

    /** Troca o ID da sessão (login/logout). Em CLI só registra que foi pedido. */
    public static function regenerate(): void
    {
        self::start();
        if (PHP_SAPI === 'cli') {
            $_SESSION['_regenerated'] = ($_SESSION['_regenerated'] ?? 0) + 1;
            return;
        }
        session_regenerate_id(true);
    }

    /** Encerra a sessão por completo. */
    public static function destroy(): void
    {
        self::start();
        $_SESSION = [];
        if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_ACTIVE) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => $p['path'], 'secure' => $p['secure'], 'httponly' => true, 'samesite' => 'Lax']);
            session_destroy();
        }
    }
}
