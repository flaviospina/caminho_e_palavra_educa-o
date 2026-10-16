<?php
// app/Request.php
// Acesso seguro aos dados da requisição (POST, IP, user agent) e mensagens flash.
declare(strict_types=1);

namespace App;

final class Request
{
    public static function post(string $key, string $default = ''): string
    {
        $v = $_POST[$key] ?? $default;
        return is_string($v) ? trim($v) : $default;
    }

    public static function get(string $key, string $default = ''): string
    {
        $v = $_GET[$key] ?? $default;
        return is_string($v) ? trim($v) : $default;
    }

    public static function ip(): string
    {
        $ip = (string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '');
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
    }

    public static function userAgent(): string
    {
        return mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
    }

    /** Guarda uma mensagem para a próxima página (ok | erro | aviso). */
    public static function flash(string $tipo, string $mensagem): void
    {
        Session::start();
        $all = Session::get('flash', []);
        $all[] = ['tipo' => $tipo, 'mensagem' => $mensagem];
        Session::set('flash', $all);
    }

    /** Devolve e apaga as mensagens pendentes. @return array<int,array{tipo:string,mensagem:string}> */
    public static function pullFlash(): array
    {
        Session::start();
        $all = Session::get('flash', []);
        Session::forget('flash');
        return is_array($all) ? $all : [];
    }
}
