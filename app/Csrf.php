<?php
// app/Csrf.php
// Token CSRF por sessão: campo oculto em todo formulário POST e validação com hash_equals.
declare(strict_types=1);

namespace App;

final class Csrf
{
    public const FIELD = '_csrf';

    public static function token(): string
    {
        Session::start();
        $token = Session::get('csrf_token');
        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(32));
            Session::set('csrf_token', $token);
        }
        return $token;
    }

    /** Campo oculto pronto para o formulário. */
    public static function field(): string
    {
        return '<input type="hidden" name="' . self::FIELD . '" value="' . e(self::token()) . '">';
    }

    /** Confere o token enviado (POST) com o da sessão. */
    public static function check(?string $sent): bool
    {
        Session::start();
        $token = Session::get('csrf_token');
        return is_string($token) && is_string($sent) && $sent !== '' && hash_equals($token, $sent);
    }
}
