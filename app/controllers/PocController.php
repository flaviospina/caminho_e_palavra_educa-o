<?php
// app/controllers/PocController.php
// Página /poc: comparação lado a lado dos motores de imagem. Acesso restrito a
// administradores (guarda de rota em app/routes.php).
declare(strict_types=1);

namespace App\Controllers;

use App\Ai\AiException;
use App\Ai\EngineFactory;
use App\Csrf;
use App\Models\UsageLog;
use App\Request;
use App\Response;
use App\Services\PocService;
use App\Session;
use App\View;

final class PocController
{
    public function __construct(private ?PocService $service = null)
    {
        $this->service ??= new PocService();
    }

    public function index(): Response
    {
        $factory = new EngineFactory();
        $resultado = Session::get('poc_resultado');
        Session::forget('poc_resultado');
        return Response::html(View::render('poc', [
            'titulo'     => 'Prova de conceito dos motores',
            'exemplos'   => array_keys(PocService::examples()),
            'motores'    => $factory->enabledNames(),
            'simulado'   => $factory->mock(),
            'modelos'    => ['gemini' => $factory->setting('GEMINI_IMAGE_MODEL'), 'openai' => $factory->setting('OPENAI_IMAGE_MODEL')],
            'execucoes'  => $this->service->runs(8),
            'tabela_ok'  => UsageLog::tableExists(),
            'usos'       => UsageLog::recent(12),
            'resultado'  => $resultado,
        ]));
    }

    /** POST /poc/gerar — gera a página escolhida nos motores marcados (síncrono, só na PoC). */
    public function generate(): Response
    {
        if (!Csrf::check(Request::post(Csrf::FIELD))) {
            Request::flash('erro', 'Sessão expirada. Tente de novo.');
            return Response::redirect(url('/poc'));
        }
        $exemplos = PocService::examples();
        $nome = Request::post('exemplo');
        $pagina = max(1, (int) Request::post('pagina', '1'));
        $motores = array_values(array_filter(array_map('strval', (array) ($_POST['motores'] ?? [])), fn($m) => in_array($m, ['gemini', 'openai', 'mock'], true)));
        if (!isset($exemplos[$nome])) {
            Request::flash('erro', 'Escolha um exemplo válido.');
            return Response::redirect(url('/poc'));
        }
        if ($motores === []) {
            Request::flash('erro', 'Marque pelo menos um motor.');
            return Response::redirect(url('/poc'));
        }
        @set_time_limit((int) env('AI_TIMEOUT', '360') * count($motores) + 60);
        try {
            $doc = PocService::loadDoc($exemplos[$nome]);
            $run = $this->service->run($doc, $pagina, $motores);
            Session::set('poc_resultado', $run['run_id']);
            $ok = count(array_filter($run['resultados'], fn($r) => $r['ok']));
            Request::flash($ok > 0 ? 'ok' : 'erro', sprintf('Execução %s concluída: %d de %d motor(es) gerou imagem.', $run['run_id'], $ok, count($run['resultados'])));
        } catch (AiException $e) {
            Request::flash('erro', $e->getMessage());
        }
        return Response::redirect(url('/poc'));
    }

    /** GET /poc/imagem/{run}/{arquivo} — serve o PNG de storage/poc (pasta privada). */
    public function image(array $args): Response
    {
        $path = $this->service->imagePath((string) ($args['run'] ?? ''), (string) ($args['arquivo'] ?? ''));
        if ($path === null) {
            return new Response('Imagem não encontrada.', 404, ['Content-Type' => 'text/plain; charset=utf-8']);
        }
        return new Response((string) file_get_contents($path), 200, ['Content-Type' => 'image/png', 'Cache-Control' => 'private, max-age=3600']);
    }
}
