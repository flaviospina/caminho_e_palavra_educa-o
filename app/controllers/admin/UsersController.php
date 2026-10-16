<?php
// app/controllers/admin/UsersController.php
// Admin: cadastro de usuários (professor, coordenador, admin) e senha provisória.
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Auth;
use App\Csrf;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\School;
use App\Models\User;
use App\Request;
use App\Response;
use App\Services\Mailer;
use App\View;
use InvalidArgumentException;

final class UsersController
{
    public function __construct(private ?Mailer $mailer = null)
    {
        $this->mailer ??= new Mailer();
    }

    public function index(): Response
    {
        $filters = ['q' => Request::get('q'), 'role' => Request::get('perfil'), 'school_id' => Request::get('escola'), 'active' => Request::get('ativo')];
        $page = max(1, (int) Request::get('pagina', '1'));
        return Response::html(View::render('admin/usuarios', [
            'titulo'   => 'Usuários',
            'lista'    => User::paginate($filters, $page),
            'filtros'  => $filters,
            'escolas'  => School::all(),
            'perfis'   => Role::all(),
        ]));
    }

    public function createForm(): Response
    {
        return Response::html(View::render('admin/usuario_form', [
            'titulo' => 'Novo usuário', 'usuario' => ['active' => 1, 'role_slug' => 'professor'], 'acao' => url('/admin/usuarios'),
            'escolas' => School::all(true), 'perfis' => Role::all(),
        ]));
    }

    public function create(): Response
    {
        if (!Csrf::check(Request::post(Csrf::FIELD))) {
            Request::flash('erro', 'Sessão expirada. Tente de novo.');
            return Response::redirect(url('/admin/usuarios/novo'));
        }
        try {
            $data = User::validate($_POST);
            $senha = User::temporaryPassword();
            $id = User::create($data, $senha, true);
            AuditLog::record('usuario_criado', 'users', $id, ['email' => $data['email'], 'role_id' => $data['role_id'], 'school_id' => $data['school_id']]);
            $this->notifyPassword($data['email'], $data['name'], $senha);
            Request::flash('ok', "Usuário cadastrado. Senha provisória: {$senha} (anote agora; ela não será mostrada de novo). Um e-mail com a senha também foi enviado.");
            return Response::redirect(url('/admin/usuarios'));
        } catch (InvalidArgumentException $e) {
            Request::flash('erro', $e->getMessage());
            return Response::redirect(url('/admin/usuarios/novo'));
        }
    }

    public function editForm(array $args): Response
    {
        $usuario = User::find((int) ($args['id'] ?? 0));
        if ($usuario === null || $usuario['anonymized_at'] !== null) {
            return Response::html(View::render('erro', ['titulo' => 'Erro 404', 'status' => 404, 'mensagem' => 'Usuário não encontrado.', 'detalhe' => null]), 404);
        }
        return Response::html(View::render('admin/usuario_form', [
            'titulo' => 'Editar usuário', 'usuario' => $usuario, 'acao' => url('/admin/usuarios/' . $usuario['id']),
            'escolas' => School::all(), 'perfis' => Role::all(),
        ]));
    }

    public function update(array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        if (!Csrf::check(Request::post(Csrf::FIELD))) {
            Request::flash('erro', 'Sessão expirada. Tente de novo.');
            return Response::redirect(url('/admin/usuarios/' . $id . '/editar'));
        }
        $atual = User::find($id);
        if ($atual === null) {
            return Response::redirect(url('/admin/usuarios'));
        }
        try {
            $data = User::validate($_POST, $id);
            if ($id === Auth::id() && ($data['role_id'] !== (int) $atual['role_id'] || $data['active'] !== 1)) {
                throw new InvalidArgumentException('Você não pode alterar o próprio perfil nem desativar a própria conta.');
            }
            User::update($id, $data);
            if ($id === Auth::id()) {
                Auth::refresh();
            }
            AuditLog::record('usuario_alterado', 'users', $id, $data);
            Request::flash('ok', 'Usuário atualizado.');
            return Response::redirect(url('/admin/usuarios'));
        } catch (InvalidArgumentException $e) {
            Request::flash('erro', $e->getMessage());
            return Response::redirect(url('/admin/usuarios/' . $id . '/editar'));
        }
    }

    /** POST /admin/usuarios/{id}/senha — gera nova senha provisória e desbloqueia. */
    public function resetPassword(array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        if (!Csrf::check(Request::post(Csrf::FIELD))) {
            Request::flash('erro', 'Sessão expirada. Tente de novo.');
            return Response::redirect(url('/admin/usuarios'));
        }
        $usuario = User::find($id);
        if ($usuario === null) {
            return Response::redirect(url('/admin/usuarios'));
        }
        $senha = User::temporaryPassword();
        User::updatePassword($id, $senha, true);
        AuditLog::record('usuario_senha_provisoria', 'users', $id);
        $this->notifyPassword((string) $usuario['email'], (string) $usuario['name'], $senha);
        Request::flash('ok', "Nova senha provisória de {$usuario['name']}: {$senha} (anote agora). Um e-mail também foi enviado.");
        return Response::redirect(url('/admin/usuarios'));
    }

    private function notifyPassword(string $email, string $name, string $senha): void
    {
        $body = "Olá, {$name}.\n\nSeu acesso ao " . APP_NAME . " foi criado ou renovado.\n\n"
            . "Endereço: " . url('/entrar') . "\nE-mail: {$email}\nSenha provisória: {$senha}\n\n"
            . "No primeiro acesso você precisará trocar a senha e aceitar o termo de uso.\n";
        $this->mailer->send($email, 'Seu acesso — ' . APP_NAME, $body);
    }
}
