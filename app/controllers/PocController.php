<?php
// app/controllers/PocController.php
// Página /poc: comparação lado a lado dos motores de imagem. Até a Fase 2 não
// há login, então o acesso é protegido pela chave POC_ACCESS_KEY do .env.
declare(strict_types=1);

namespace App\Controllers;

use App\Ai\AiException;
use App\Ai\EngineFactory;
use App\Csrf;
use App\Models\UsageLog;
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

    /** Chave configurada; vazia desliga a página (404). */
    private function accessKey(): string
    {
        return trim((string) env('POC_ACCESS_KEY', ''));
    }

    private function authorized(): bool
    {
        if ($this->accessKey() === '') {
            return false;
        }
        Session::start();
        return Session::get('poc_ok') === true;
    }

    public function index(): Response
    {
        if ($this->accessKey() === '') {
            return Response::html(View::render('erro', ['titulo' => 'Erro 404', 'status' => 404, 'mensagem' => 'Página não encontrada.', 'detalhe' => null]), 404);
        }
        if (!$this->authorized()) {
            return Response::html(View::render('poc_login', ['titulo' => 'Prova de conceito', 'erro' => Session::get('poc_erro')]));
        }
        Session::forget('poc_erro');
        $factory = new EngineFactory();
        return Response::html(View::render('poc', [
            'titulo'     => 'Prova de conceito dos motores',
            'exemplos'   => array_keys(PocService::examples()),
            'motores'    => $factory->enabledNames(),
            'simulado'   => $factory->mock(),
            'modelos'    => ['gemini' => $factory->setting('GEMINI_IMAGE_MODEL'), 'openai' => $factory->setting('OPENAI_IMAGE_MODEL')],
            'execucoes'  => $this->service->runs(8),
            'tabela_ok'  => UsageLog::tableExists(),
            'usos'       => UsageLog::recent(12),
            'resultado'  => Session::get('poc_resultado'),
            'mensagem'   => Session::get('poc_mensagem'),
        ]));
    }

    /** POST /poc/entrar — confere a chave. */
    public function login(): Response
    {
        if ($this->accessKey() === '') {
            return Response::redirect(url('/poc'));
        }
        Session::start();
        if (!Csrf::check($_POST[Csrf::FIELD] ?? null)) {
            Session::set('poc_erro', 'Sessão expirada. Tente de novo.');
            return Response::redirect(url('/poc'));
        }
        $chave = (string) ($_POST['chave'] ?? '');
        if (hash_equals($this->accessKey(), $chave)) {
            session_regenerate_id(true);
            Session::set('poc_ok', true);
            Session::forget('poc_erro');
        } else {
            Session::set('poc_erro', 'Chave incorreta.');
        }
        return Response::redirect(url('/poc'));
    }

    /** POST /poc/sair */
    public function logout(): Response
    {
        Session::start();
        Session::forget('poc_ok');
        session_regenerate_id(true);
        return Response::redirect(url('/poc'));
    }

    /** POST /poc/gerar — gera a página escolhida nos motores marcados (síncrono, só na PoC). */
    public function generate(): Response
    {
        if (!$this->authorized()) {
            return Response::redirect(url('/poc'));
        }
        if (!Csrf::check($_POST[Csrf::FIELD] ?? null)) {
            Session::set('poc_mensagem', 'Sessão expirada. Tente de novo.');
            return Response::redirect(url('/poc'));
        }
        $exemplos = PocService::examples();
        $nome = (string) ($_POST['exemplo'] ?? '');
        $pagina = max(1, (int) ($_POST['pagina'] ?? 1));
        $motores = array_values(array_filter(array_map('strval', (array) ($_POST['motores'] ?? [])), fn($m) => in_array($m, ['gemini', 'openai', 'mock'], true)));
        if (!isset($exemplos[$nome])) {
            Session::set('poc_mensagem', 'Escolha um exemplo válido.');
            return Response::redirect(url('/poc'));
        }
        if ($motores === []) {
            Session::set('poc_mensagem', 'Marque pelo menos um motor.');
            return Response::redirect(url('/poc'));
        }
        @set_time_limit((int) env('AI_TIMEOUT', '360') * count($motores) + 60);
        try {
            $doc = PocService::loadDoc($exemplos[$nome]);
            $run = $this->service->run($doc, $pagina, $motores);
            Session::set('poc_resultado', $run['run_id']);
            $ok = count(array_filter($run['resultados'], fn($r) => $r['ok']));
            Session::set('poc_mensagem', sprintf('Execução %s concluída: %d de %d motor(es) gerou imagem.', $run['run_id'], $ok, count($run['resultados'])));
        } catch (AiException $e) {
            Session::set('poc_mensagem', $e->getMessage());
        }
        return Response::redirect(url('/poc'));
    }

    /** GET /poc/imagem/{run}/{arquivo} — serve o PNG de storage/poc (pasta privada). */
    public function image(array $args): Response
    {
        if (!$this->authorized()) {
            return new Response('Acesso negado.', 403, ['Content-Type' => 'text/plain; charset=utf-8']);
        }
        $path = $this->service->imagePath((string) ($args['run'] ?? ''), (string) ($args['arquivo'] ?? ''));
        if ($path === null) {
            return new Response('Imagem não encontrada.', 404, ['Content-Type' => 'text/plain; charset=utf-8']);
        }
        return new Response((string) file_get_contents($path), 200, ['Content-Type' => 'image/png', 'Cache-Control' => 'private, max-age=3600']);
    }
}
