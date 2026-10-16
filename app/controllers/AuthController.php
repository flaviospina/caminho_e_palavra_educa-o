<?php
// app/controllers/AuthController.php
// Login, logout, recuperação de senha por e-mail e troca de senha provisória.
declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\Csrf;
use App\Models\AuditLog;
use App\Models\PasswordReset;
use App\Models\User;
use App\Request;
use App\Response;
use App\Services\Mailer;
use App\Session;
use App\View;
use InvalidArgumentException;

final class AuthController
{
    public function __construct(private ?Mailer $mailer = null)
    {
        $this->mailer ??= new Mailer();
    }

    /** GET /entrar */
    public function loginForm(): Response
    {
        if (Auth::check()) {
            return Response::redirect(url('/painel'));
        }
        return Response::html(View::render('auth/entrar', ['titulo' => 'Entrar']));
    }

    /** POST /entrar */
    public function login(): Response
    {
        if (!Csrf::check(Request::post(Csrf::FIELD))) {
            Request::flash('erro', 'Sessão expirada. Tente de novo.');
            return Response::redirect(url('/entrar'));
        }
        $r = Auth::attempt(Request::post('email'), (string) ($_POST['senha'] ?? ''), Request::ip());
        if (!$r['ok']) {
            Request::flash('erro', $r['mensagem']);
            return Response::redirect(url('/entrar'));
        }
        $after = Session::get('after_login');
        Session::forget('after_login');
        $dest = is_string($after) && str_starts_with($after, url('/')) && !str_contains($after, '/entrar') ? $after : url('/painel');
        return Response::redirect($dest);
    }

    /** POST /sair */
    public function logout(): Response
    {
        if (Csrf::check(Request::post(Csrf::FIELD))) {
            Auth::logout();
        }
        return Response::redirect(url('/'));
    }

    /** GET /senha/esqueci */
    public function forgotForm(): Response
    {
        return Response::html(View::render('auth/esqueci', ['titulo' => 'Esqueci minha senha']));
    }

    /** POST /senha/esqueci — resposta sempre igual, para não revelar e-mails cadastrados. */
    public function forgot(): Response
    {
        if (!Csrf::check(Request::post(Csrf::FIELD))) {
            Request::flash('erro', 'Sessão expirada. Tente de novo.');
            return Response::redirect(url('/senha/esqueci'));
        }
        $email = Request::post('email');
        $user = $email !== '' ? User::findByEmail($email) : null;
        if ($user !== null && (int) $user['active'] === 1 && $user['anonymized_at'] === null) {
            $token = PasswordReset::create((int) $user['id'], Request::ip());
            $link = url('/senha/redefinir/' . $token);
            $body = "Olá, {$user['name']}.\n\nRecebemos um pedido para redefinir a sua senha no " . APP_NAME . ".\n"
                . "Abra o endereço abaixo em até " . PasswordReset::TTL_MINUTES . " minutos:\n\n{$link}\n\n"
                . "Se você não pediu isso, ignore esta mensagem; a sua senha continua a mesma.\n";
            $this->mailer->send((string) $user['email'], 'Redefinição de senha — ' . APP_NAME, $body);
            AuditLog::record('senha_recuperacao_pedida', 'users', (int) $user['id'], null, (int) $user['id'], Request::ip());
        }
        Request::flash('ok', 'Se o e-mail estiver cadastrado, você receberá em instantes um link para redefinir a senha. Confira também a caixa de spam.');
        return Response::redirect(url('/entrar'));
    }

    /** GET /senha/redefinir/{token} */
    public function resetForm(array $args): Response
    {
        $token = (string) ($args['token'] ?? '');
        if (PasswordReset::userIdFor($token) === null) {
            Request::flash('erro', 'Este link de redefinição é inválido ou expirou. Peça um novo.');
            return Response::redirect(url('/senha/esqueci'));
        }
        return Response::html(View::render('auth/redefinir', ['titulo' => 'Redefinir senha', 'token' => $token]));
    }

    /** POST /senha/redefinir/{token} */
    public function reset(array $args): Response
    {
        $token = (string) ($args['token'] ?? '');
        if (!Csrf::check(Request::post(Csrf::FIELD))) {
            Request::flash('erro', 'Sessão expirada. Tente de novo.');
            return Response::redirect(url('/senha/redefinir/' . $token));
        }
        $userId = PasswordReset::userIdFor($token);
        if ($userId === null) {
            Request::flash('erro', 'Este link de redefinição é inválido ou expirou. Peça um novo.');
            return Response::redirect(url('/senha/esqueci'));
        }
        $senha = (string) ($_POST['senha'] ?? '');
        try {
            User::validatePassword($senha);
            if ($senha !== (string) ($_POST['senha2'] ?? '')) {
                throw new InvalidArgumentException('As duas senhas não conferem.');
            }
        } catch (InvalidArgumentException $e) {
            Request::flash('erro', $e->getMessage());
            return Response::redirect(url('/senha/redefinir/' . $token));
        }
        User::updatePassword($userId, $senha, false);
        PasswordReset::consume($token);
        AuditLog::record('senha_redefinida', 'users', $userId, null, $userId, Request::ip());
        Request::flash('ok', 'Senha redefinida. Entre com a nova senha.');
        return Response::redirect(url('/entrar'));
    }

    /** GET /senha/trocar (usuário logado) */
    public function changeForm(): Response
    {
        $user = Auth::user();
        return Response::html(View::render('auth/trocar', ['titulo' => 'Trocar senha', 'obrigatorio' => (int) $user['must_change_pass'] === 1]));
    }

    /** POST /senha/trocar */
    public function change(): Response
    {
        $user = Auth::user();
        if (!Csrf::check(Request::post(Csrf::FIELD))) {
            Request::flash('erro', 'Sessão expirada. Tente de novo.');
            return Response::redirect(url('/senha/trocar'));
        }
        $atual = (string) ($_POST['senha_atual'] ?? '');
        $nova = (string) ($_POST['senha'] ?? '');
        try {
            if (!password_verify($atual, (string) $user['password_hash'])) {
                throw new InvalidArgumentException('A senha atual está incorreta.');
            }
            User::validatePassword($nova);
            if ($nova === $atual) {
                throw new InvalidArgumentException('A nova senha precisa ser diferente da atual.');
            }
            if ($nova !== (string) ($_POST['senha2'] ?? '')) {
                throw new InvalidArgumentException('As duas senhas não conferem.');
            }
        } catch (InvalidArgumentException $e) {
            Request::flash('erro', $e->getMessage());
            return Response::redirect(url('/senha/trocar'));
        }
        User::updatePassword((int) $user['id'], $nova, false);
        Auth::refresh();
        AuditLog::record('senha_trocada', 'users', (int) $user['id']);
        Request::flash('ok', 'Senha atualizada.');
        return Response::redirect(url('/painel'));
    }
}
