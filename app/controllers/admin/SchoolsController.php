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
use App\Upload;
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
            $logo = Upload::image('logo', 'schools');
            $id = School::create($data);
            if ($logo !== null) {
                School::setLogo($id, $logo);
            }
            AuditLog::record('escola_criada', 'schools', $id, $data);
            Request::flash('ok', 'Escola cadastrada.');
            return Response::redirect(url('/admin/escolas'));
        } catch (InvalidArgumentException $e) {
            Request::flash('erro', $e->getMessage());
            return Response::redirect(url('/admin/escolas/nova'));
        }
    }

    /** GET /escolas/{id}/logo — logotipo da escola (usuários logados). */
    public function logo(array $args): Response
    {
        $escola = School::find((int) ($args['id'] ?? 0));
        $file = $escola === null ? null : Upload::path($escola['logo_path'] ?? null);
        if ($file === null) {
            return new Response('Sem logotipo.', 404, ['Content-Type' => 'text/plain; charset=utf-8']);
        }
        return new Response((string) file_get_contents($file), 200, ['Content-Type' => Upload::mime($file), 'Cache-Control' => 'private, max-age=3600']);
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
            $logo = Upload::image('logo', 'schools');
            School::update($id, $data);
            if ($logo !== null) {
                School::setLogo($id, $logo);
            } elseif (!empty($_POST['remover_logo'])) {
                School::setLogo($id, null);
            }
            AuditLog::record('escola_alterada', 'schools', $id, $data);
            Request::flash('ok', 'Escola atualizada.');
            return Response::redirect(url('/admin/escolas'));
        } catch (InvalidArgumentException $e) {
            Request::flash('erro', $e->getMessage());
            return Response::redirect(url('/admin/escolas/' . $id . '/editar'));
        }
    }
}
