<?php
// app/controllers/ProjectsController.php
// Assistente de criação (passos 1–5), revisão do conteúdo, plano de aula e
// "Meus projetos".
declare(strict_types=1);

namespace App\Controllers;

use App\Ai\AiException;
use App\Ai\ContentValidator;
use App\Ai\Styles;
use App\Auth;
use App\Csrf;
use App\Models\AuditLog;
use App\Models\BnccSkill;
use App\Models\Page;
use App\Models\Project;
use App\Request;
use App\Response;
use App\Services\ContentService;
use App\Services\LessonPlanService;
use App\View;
use InvalidArgumentException;

final class ProjectsController
{
    public function __construct(private ?ContentService $service = null)
    {
        $this->service ??= new ContentService();
    }

    /** GET /projetos — Meus projetos (professor: próprios; coordenador: escola; admin: todos). */
    public function index(): Response
    {
        $user = Auth::user();
        $filters = ['q' => Request::get('q'), 'componente' => Request::get('componente'), 'ano' => Request::get('ano'), 'habilidade' => Request::get('habilidade'), 'status' => Request::get('status')];
        return Response::html(View::render('projetos/index', [
            'titulo'  => 'Meus projetos',
            'lista'   => Project::paginateFor($user, $filters, max(1, (int) Request::get('pagina', '1'))),
            'filtros' => $filters,
            'opcoes'  => Project::filterOptions($user),
            'status'  => Project::STATUS,
        ]));
    }

    /** GET /projetos/novo — passos 1 a 3 do assistente. */
    public function newForm(): Response
    {
        return Response::html(View::render('projetos/novo', [
            'titulo'      => 'Novo material',
            'etapas'      => BnccSkill::STAGES,
            'ei_grupos'   => BnccSkill::EI_GROUPS,
            'estilos'     => Styles::all(),
            'finalidades' => \App\Ai\ContentPromptBuilder::FINALIDADES,
            'max_paginas' => ContentValidator::MAX_PAGINAS,
            'max_habilidades' => ContentValidator::MAX_HABILIDADES,
            'simulado'    => AI_MOCK,
            'valores'     => \App\Session::get('assistente_valores', []),
        ]));
    }

    /** POST /projetos — gera o conteúdo pela IA, valida e salva o rascunho. */
    public function create(): Response
    {
        $user = Auth::user();
        if (!Csrf::check(Request::post(Csrf::FIELD))) {
            Request::flash('erro', 'Sessão expirada. Tente de novo.');
            return Response::redirect(url('/projetos/novo'));
        }
        \App\Session::set('assistente_valores', array_intersect_key($_POST, array_flip(['etapa', 'ano', 'componente', 'habilidades', 'tema', 'observacoes', 'finalidade', 'paginas', 'estilo'])));
        try {
            $req = ContentService::validateRequest($_POST);
        } catch (InvalidArgumentException $e) {
            Request::flash('erro', $e->getMessage());
            return Response::redirect(url('/projetos/novo'));
        }
        @set_time_limit((int) env('TEXT_TIMEOUT', '120') * 3 + 30);
        try {
            $id = $this->service->createProject($user, $req);
        } catch (AiException $e) {
            Request::flash('erro', $e->getMessage());
            return Response::redirect(url('/projetos/novo'));
        }
        \App\Session::forget('assistente_valores');
        Request::flash('ok', 'Conteúdo gerado. Agora revise cada bloco antes de seguir.');
        return Response::redirect(url('/projetos/' . $id . '/revisar'));
    }

    /** GET /projetos/{id} — passo 5: resumo e próximas ações. */
    public function show(array $args): Response
    {
        [$project, $resp] = $this->load($args);
        if ($resp !== null) {
            return $resp;
        }
        $user = Auth::user();
        return Response::html(View::render('projetos/mostrar', [
            'titulo'   => $project['title'],
            'projeto'  => $project,
            'doc'      => Project::currentContent($project),
            'gerado'   => Project::generatedContent($project),
            'paginas'  => Page::forProject((int) $project['id']),
            'pode_editar' => Project::canEdit($project, $user),
            'estilo'   => Styles::exists((string) $project['style_slug']) ? Styles::get((string) $project['style_slug']) : null,
            'status'   => Project::STATUS,
        ]));
    }

    /** GET /projetos/{id}/revisar — passo 4: editor por bloco. */
    public function reviewForm(array $args): Response
    {
        [$project, $resp] = $this->load($args, true);
        if ($resp !== null) {
            return $resp;
        }
        return Response::html(View::render('projetos/revisar', [
            'titulo'  => 'Revisar conteúdo',
            'projeto' => $project,
            'doc'     => Project::currentContent($project),
            'limites' => ContentValidator::limitsFor((string) $project['style_slug']),
            'erros'   => \App\Session::get('revisao_erros', []),
        ]));
    }

    /** POST /projetos/{id}/revisar — salva a versão revisada. */
    public function saveReview(array $args): Response
    {
        [$project, $resp] = $this->load($args, true);
        if ($resp !== null) {
            return $resp;
        }
        $id = (int) $project['id'];
        if (!Csrf::check(Request::post(Csrf::FIELD))) {
            Request::flash('erro', 'Sessão expirada. Tente de novo.');
            return Response::redirect(url('/projetos/' . $id . '/revisar'));
        }
        \App\Session::forget('revisao_erros');
        $r = $this->service->applyReview(Project::currentContent($project), $_POST);
        if ($r['erros'] !== []) {
            \App\Session::set('revisao_erros', $r['erros']);
            Request::flash('erro', 'Alguns campos precisam de ajuste (veja a lista abaixo). Nada foi salvo.');
            return Response::redirect(url('/projetos/' . $id . '/revisar'));
        }
        Project::saveReview($id, $r['doc']);
        AuditLog::record('projeto_revisado', 'projects', $id);
        Request::flash('ok', 'Revisão salva. Esta é a versão que será usada nas páginas.');
        return Response::redirect(url('/projetos/' . $id));
    }

    /** POST /projetos/{id}/reverter — descarta a revisão e volta ao gerado. */
    public function revert(array $args): Response
    {
        [$project, $resp] = $this->load($args, true);
        if ($resp !== null) {
            return $resp;
        }
        $id = (int) $project['id'];
        if (Csrf::check(Request::post(Csrf::FIELD))) {
            Project::revertReview($id);
            AuditLog::record('projeto_revisao_descartada', 'projects', $id);
            Request::flash('ok', 'Voltamos à versão gerada pela IA.');
        }
        return Response::redirect(url('/projetos/' . $id . '/revisar'));
    }

    /** GET /projetos/{id}/plano — plano de aula em texto. */
    public function plan(array $args): Response
    {
        [$project, $resp] = $this->load($args);
        if ($resp !== null) {
            return $resp;
        }
        $svc = new LessonPlanService();
        $plan = $svc->build(Project::currentContent($project), $project);
        return Response::html(View::render('projetos/plano', ['titulo' => 'Plano de aula', 'projeto' => $project, 'plano' => $plan, 'html' => $svc->html($plan)]));
    }

    /** GET /projetos/{id}/plano.pdf — PDF de texto do plano. */
    public function planPdf(array $args): Response
    {
        [$project, $resp] = $this->load($args);
        if ($resp !== null) {
            return $resp;
        }
        $svc = new LessonPlanService();
        $plan = $svc->build(Project::currentContent($project), $project);
        $bytes = $svc->pdf($plan);
        $nome = 'plano-de-aula-' . (int) $project['id'] . '.pdf';
        return new Response($bytes, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="' . $nome . '"', 'Content-Length' => (string) strlen($bytes)]);
    }

    /** POST /projetos/{id}/excluir — exclui rascunho (só autor ou admin). */
    public function delete(array $args): Response
    {
        [$project, $resp] = $this->load($args, true);
        if ($resp !== null) {
            return $resp;
        }
        $id = (int) $project['id'];
        if (Csrf::check(Request::post(Csrf::FIELD))) {
            Project::softDelete($id);
            AuditLog::record('projeto_excluido', 'projects', $id);
            Request::flash('ok', 'Projeto excluído.');
            return Response::redirect(url('/projetos'));
        }
        return Response::redirect(url('/projetos/' . $id));
    }

    /** Carrega o projeto e confere a permissão. @return array{0:?array,1:?Response} */
    private function load(array $args, bool $edit = false): array
    {
        $project = Project::find((int) ($args['id'] ?? 0));
        if ($project === null) {
            return [null, Response::html(View::render('erro', ['titulo' => 'Erro 404', 'status' => 404, 'mensagem' => 'Projeto não encontrado.', 'detalhe' => null]), 404)];
        }
        $user = Auth::user();
        if (!Project::canAccess($project, $user) || ($edit && !Project::canEdit($project, $user))) {
            return [null, Response::html(View::render('erro', ['titulo' => 'Erro 403', 'status' => 403, 'mensagem' => 'Você não tem permissão para acessar este projeto.', 'detalhe' => null]), 403)];
        }
        return [$project, null];
    }
}
