<?php
// app/Auth.php
// Autenticação e autorização: login com bloqueio após 5 tentativas, sessão
// regenerada, usuário atual e guarda por perfil (professor, coordenador, admin).
declare(strict_types=1);

namespace App;

use App\Models\AuditLog;
use App\Models\User;

final class Auth
{
    public const MAX_ATTEMPTS = 5;
    public const LOCK_MINUTES = 15;
    public const SESSION_KEY = 'user_id';

    private static ?array $cache = null;

    /** Tenta autenticar. Devolve ['ok'=>bool, 'mensagem'=>string, 'user'=>?array]. */
    public static function attempt(string $email, string $password, string $ip = ''): array
    {
        $email = mb_strtolower(trim($email));
        $generic = 'E-mail ou senha incorretos.';
        $user = $email !== '' ? User::findByEmail($email) : null;
        if ($user === null) {
            AuditLog::record('login_falhou', 'users', null, ['email' => $email, 'motivo' => 'desconhecido'], null, $ip);
            return ['ok' => false, 'mensagem' => $generic, 'user' => null];
        }
        if (!(int) $user['active'] || $user['anonymized_at'] !== null) {
            AuditLog::record('login_falhou', 'users', (int) $user['id'], ['motivo' => 'inativo'], (int) $user['id'], $ip);
            return ['ok' => false, 'mensagem' => 'Esta conta está desativada. Procure a coordenação ou o CECAPE.', 'user' => null];
        }
        if ($user['locked_until'] !== null && strtotime((string) $user['locked_until']) > time()) {
            $min = (int) ceil((strtotime((string) $user['locked_until']) - time()) / 60);
            AuditLog::record('login_bloqueado', 'users', (int) $user['id'], ['minutos_restantes' => $min], (int) $user['id'], $ip);
            return ['ok' => false, 'mensagem' => "Conta bloqueada por excesso de tentativas. Tente novamente em {$min} minuto(s) ou use \"Esqueci minha senha\".", 'user' => null];
        }
        if (!password_verify($password, (string) $user['password_hash'])) {
            $attempts = (int) $user['failed_attempts'] + 1;
            $lock = $attempts >= self::MAX_ATTEMPTS;
            User::registerFailure((int) $user['id'], $attempts, $lock ? self::LOCK_MINUTES : null);
            AuditLog::record('login_falhou', 'users', (int) $user['id'], ['tentativa' => $attempts, 'bloqueou' => $lock], (int) $user['id'], $ip);
            if ($lock) {
                return ['ok' => false, 'mensagem' => 'Conta bloqueada por ' . self::LOCK_MINUTES . ' minutos após ' . self::MAX_ATTEMPTS . ' tentativas incorretas. Use "Esqueci minha senha" se precisar.', 'user' => null];
            }
            $restam = self::MAX_ATTEMPTS - $attempts;
            return ['ok' => false, 'mensagem' => $generic . " Restam {$restam} tentativa(s) antes do bloqueio.", 'user' => null];
        }
        if (password_needs_rehash((string) $user['password_hash'], PASSWORD_DEFAULT)) {
            User::updatePassword((int) $user['id'], $password, (bool) $user['must_change_pass']);
        }
        User::registerLogin((int) $user['id'], $ip);
        self::login((int) $user['id']);
        AuditLog::record('login', 'users', (int) $user['id'], null, (int) $user['id'], $ip);
        return ['ok' => true, 'mensagem' => 'Bem-vindo(a)!', 'user' => User::find((int) $user['id'])];
    }

    /** Abre a sessão do usuário (sessão regenerada contra fixação). */
    public static function login(int $userId): void
    {
        Session::start();
        Session::regenerate();
        Session::set(self::SESSION_KEY, $userId);
        Session::set('login_at', time());
        self::$cache = null;
    }

    public static function logout(): void
    {
        $user = self::user();
        if ($user !== null) {
            AuditLog::record('logout', 'users', (int) $user['id']);
        }
        Session::destroy();
        self::$cache = null;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    /** Usuário logado (array da tabela users com role_slug e school_name) ou null. */
    public static function user(): ?array
    {
        Session::start();
        $id = Session::get(self::SESSION_KEY);
        if (!is_int($id) && !ctype_digit((string) $id)) {
            return null;
        }
        if (self::$cache === null || (int) self::$cache['id'] !== (int) $id) {
            self::$cache = User::find((int) $id);
            if (self::$cache === null || !(int) self::$cache['active'] || self::$cache['anonymized_at'] !== null) {
                self::$cache = null;
                Session::forget(self::SESSION_KEY);
                return null;
            }
        }
        return self::$cache;
    }

    public static function id(): ?int
    {
        $u = self::user();
        return $u === null ? null : (int) $u['id'];
    }

    public static function role(): ?string
    {
        $u = self::user();
        return $u === null ? null : (string) $u['role_slug'];
    }

    /** Verdadeiro se o usuário tem um dos perfis (admin passa em tudo). */
    public static function hasRole(string ...$roles): bool
    {
        $role = self::role();
        if ($role === null) {
            return false;
        }
        return $role === 'admin' || in_array($role, $roles, true);
    }

    public static function isAdmin(): bool
    {
        return self::role() === 'admin';
    }

    /** Limpa o cache do usuário (após alterações no próprio cadastro). */
    public static function refresh(): void
    {
        self::$cache = null;
    }
}
