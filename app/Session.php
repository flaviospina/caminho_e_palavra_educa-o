<?php
// app/Session.php
// Inicia a sessão com cookies seguros (HttpOnly, SameSite=Lax, Secure em HTTPS).
declare(strict_types=1);

namespace App;

final class Session
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE || PHP_SAPI === 'cli') {
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
}
