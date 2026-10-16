<?php
// app/controllers/admin/SchoolsController.php
// Admin: cadastro de escolas.
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Csrf;
use App\Models\AuditLog;
use App\Models\School;
use App\Request;
use App\Response;
use App\View;
use InvalidArgumentException;

final class SchoolsController
{
    public function index(): Response
    {
        return Response::html(View::render('admin/escolas', ['titulo' => 'Escolas', 'escolas' => School::all()]));
    }

    public function createForm(): Response
    {
        return Response::html(View::render('admin/escola_form', ['titulo' => 'Nova escola', 'escola' => ['active' => 1, 'city' => 'São Caetano do Sul'], 'acao' => url('/admin/escolas')]));
    }

    public function create(): Response
    {
        if (!Csrf::check(Request::post(Csrf::FIELD))) {
            Request::flash('erro', 'Sessão expirada. Tente de novo.');
            return Response::redirect(url('/admin/escolas/nova'));
        }
        try {
            $data = School::validate($_POST);
            if (School::nameExists($data['name'])) {
                throw new InvalidArgumentException('Já existe uma escola com este nome.');
            }
            $id = School::create($data);
            AuditLog::record('escola_criada', 'schools', $id, $data);
            Request::flash('ok', 'Escola cadastrada.');
            return Response::redirect(url('/admin/escolas'));
        } catch (InvalidArgumentException $e) {
            Request::flash('erro', $e->getMessage());
            return Response::redirect(url('/admin/escolas/nova'));
        }
    }

    public function editForm(array $args): Response
    {
        $escola = School::find((int) ($args['id'] ?? 0));
        if ($escola === null) {
            return Response::html(View::render('erro', ['titulo' => 'Erro 404', 'status' => 404, 'mensagem' => 'Escola não encontrada.', 'detalhe' => null]), 404);
        }
        return Response::html(View::render('admin/escola_form', ['titulo' => 'Editar escola', 'escola' => $escola, 'acao' => url('/admin/escolas/' . $escola['id'])]));
    }

    public function update(array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        if (!Csrf::check(Request::post(Csrf::FIELD))) {
            Request::flash('erro', 'Sessão expirada. Tente de novo.');
            return Response::redirect(url('/admin/escolas/' . $id . '/editar'));
        }
        if (School::find($id) === null) {
            return Response::redirect(url('/admin/escolas'));
        }
        try {
            $data = School::validate($_POST);
            if (School::nameExists($data['name'], $id)) {
                throw new InvalidArgumentException('Já existe outra escola com este nome.');
            }
            School::update($id, $data);
            AuditLog::record('escola_alterada', 'schools', $id, $data);
            Request::flash('ok', 'Escola atualizada.');
            return Response::redirect(url('/admin/escolas'));
        } catch (InvalidArgumentException $e) {
            Request::flash('erro', $e->getMessage());
            return Response::redirect(url('/admin/escolas/' . $id . '/editar'));
        }
    }
}
