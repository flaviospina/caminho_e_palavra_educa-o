<?php
// app/Guard.php
// Guardas de rota: exigem login, perfil e aceite do termo antes de executar o
// controlador. Devolvem redirecionamento (anônimo), 403 (perfil insuficiente)
// ou a resposta do controlador.
declare(strict_types=1);

namespace App;

use App\Models\Consent;

final class Guard
{
    /** Exige usuário logado (qualquer perfil) com termo aceito. */
    public static function auth(callable $handler): callable
    {
        return self::roles([], $handler);
    }

    /**
     * Exige um dos perfis informados (admin sempre passa). Lista vazia = só login.
     * @param string[] $roles
     */
    public static function roles(array $roles, callable $handler, bool $requireConsent = true): callable
    {
        return static function (array $args = []) use ($roles, $handler, $requireConsent): Response {
            $user = Auth::user();
            if ($user === null) {
                Session::set('after_login', $_SERVER['REQUEST_URI'] ?? null);
                Session::set('flash_erro', 'Faça login para continuar.');
                return Response::redirect(url('/entrar'));
            }
            if ($roles !== [] && !Auth::hasRole(...$roles)) {
                return Response::html(View::render('erro', [
                    'titulo' => 'Erro 403', 'status' => 403,
                    'mensagem' => 'Você não tem permissão para acessar esta página.', 'detalhe' => null,
                ]), 403);
            }
            if ($requireConsent && !Consent::hasCurrent((int) $user['id'])) {
                return Response::redirect(url('/termo'));
            }
            if ($requireConsent && (int) $user['must_change_pass'] === 1) {
                return Response::redirect(url('/senha/trocar'));
            }
            return $handler($args);
        };
    }

    /** Exige perfil admin. */
    public static function admin(callable $handler): callable
    {
        return self::roles(['admin'], $handler);
    }

    /** Exige coordenador ou admin. */
    public static function coordinator(callable $handler): callable
    {
        return self::roles(['coordenador'], $handler);
    }
}
