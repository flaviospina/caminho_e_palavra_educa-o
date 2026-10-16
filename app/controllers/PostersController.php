<?php
// app/controllers/PostersController.php
// Cartazes de eventos escolares: formulário e criação (a geração vai pela fila).
declare(strict_types=1);

namespace App\Controllers;

use App\Ai\Styles;
use App\Auth;
use App\Csrf;
use App\Models\BnccSkill;
use App\Models\School;
use App\Request;
use App\Response;
use App\Services\GenerationService;
use App\Services\PosterService;
use App\View;
use InvalidArgumentException;

final class PostersController
{
    /** GET /cartazes/novo */
    public function newForm(): Response
    {
        $user = Auth::user();
        return Response::html(View::render('cartazes/novo', [
            'titulo'   => 'Novo cartaz de evento',
            'tipos'    => PosterService::EVENT_TYPES,
            'formatos' => PosterService::FORMATS,
            'estilos'  => Styles::all(),
            'etapas'   => BnccSkill::STAGES,
            'escola'   => $user['school_id'] !== null ? School::find((int) $user['school_id']) : null,
            'valores'  => \App\Session::get('cartaz_valores', []),
            'simulado' => AI_MOCK,
        ]));
    }

    /** POST /cartazes — cria o projeto do cartaz e enfileira a geração. */
    public function create(): Response
    {
        $user = Auth::user();
        if (!Csrf::check(Request::post(Csrf::FIELD))) {
            Request::flash('erro', 'Sessão expirada. Tente de novo.');
            return Response::redirect(url('/cartazes/novo'));
        }
        \App\Session::set('cartaz_valores', array_intersect_key($_POST, array_flip(['tipo_evento', 'titulo', 'data', 'hora', 'local', 'chamada', 'informacoes', 'estilo', 'formatos', 'etapa'])));
        try {
            $school = $user['school_id'] !== null ? School::find((int) $user['school_id']) : null;
            $doc = PosterService::validate($_POST, $school);
            $id = PosterService::create($user, $doc);
            GenerationService::enqueueProject(\App\Models\Project::find($id), (int) $user['id']);
        } catch (InvalidArgumentException $e) {
            Request::flash('erro', $e->getMessage());
            return Response::redirect(url('/cartazes/novo'));
        }
        \App\Session::forget('cartaz_valores');
        Request::flash('ok', 'Cartaz enviado para a fila de geração nos formatos escolhidos. Acompanhe o progresso aqui.');
        return Response::redirect(url('/projetos/' . $id));
    }
}
