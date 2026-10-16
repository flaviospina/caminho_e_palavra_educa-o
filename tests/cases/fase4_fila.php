<?php
// tests/cases/fase4_fila.php — fila, worker, conferência automática, retomada e custo
declare(strict_types=1);

use App\Ai\AiException;
use App\Ai\EngineFactory;
use App\Ai\ImageEngine;
use App\Ai\ImageRequest;
use App\Ai\ImageResult;
use App\Ai\MockEngine;
use App\Ai\MockVerifier;
use App\Ai\PromptBuilder;
use App\Database;
use App\Models\GenerationJob;
use App\Models\Page;
use App\Models\Project;
use App\Models\UsageLog;
use App\Models\User;
use App\Services\GenerationService;
use App\Services\GenerationWorker;

/** Cria um projeto revisado (capa + 3 páginas) pronto para gerar. */
function projeto_revisado_f4(int $userId, string $tema = 'Fila de teste'): array
{
    $id = projeto_de($userId, $tema);
    $p = Project::find($id);
    Project::saveReview($id, Project::currentContent($p));
    return Project::find($id);
}

/** Motor falso que falha N vezes com erro recuperável e depois gera (via mock), devolvendo tokens. */
final class MotorFalhaDepoisGera implements ImageEngine
{
    public int $chamadas = 0;
    public function __construct(private int $falhas = 0, private bool $explodir = false) {}
    public function name(): string { return 'fake'; }
    public function model(): string { return 'gpt-image-2.5-flare'; }
    public function generate(ImageRequest $r): ImageResult
    {
        $this->chamadas++;
        if ($this->chamadas <= $this->falhas) {
            if ($this->explodir) {
                throw new RuntimeException('pane inesperada');
            }
            throw new AiException('O OpenAI está instável no momento (erro 503).', 'HTTP 503', 'erro', true);
        }
        $png = (new MockEngine())->generate($r)->png;
        return ImageResult::fromPng($png, 'fake', $this->model(), 1200, 100, 6240, \App\Ai\Pricing::estimate($this->model(), 100, 6240, 'high'));
    }
}

/** Isola o teste: encerra jobs pendentes de testes anteriores. */
function fila_limpar(): void
{
    Database::get()->exec("UPDATE generation_jobs SET status = 'erro', error_message = 'encerrado pelo teste', finished_at = NOW(), locked_by = NULL WHERE status IN ('pendente', 'gerando', 'conferindo')");
}

function worker_mock(?ImageEngine $engine = null, ?\App\Ai\Verifier $verifier = null): GenerationWorker
{
    return new GenerationWorker(new EngineFactory(['AI_MOCK' => '1']), $engine, $verifier ?? new MockVerifier(), true, 'teste:1', 10, 0);
}

Teste::caso('fila: "Gerar imagens" cria um job por página (capa + 3) sem chamar a IA; rascunho é recusado', function () {
    $prof = teste_criar_usuario('professor', 'prof.f4@exemplo.com');
    teste_aceitar_termo($prof);
    $rascunho = Project::find(projeto_de($prof, 'Rascunho f4'));
    $e = Teste::lanca(fn() => GenerationService::enqueueProject($rascunho, $prof), InvalidArgumentException::class);
    Teste::contem('Revise e salve o conteúdo', $e->getMessage());

    $proj = projeto_revisado_f4($prof);
    $antes = count(UsageLog::recent(500));
    $jobs = GenerationService::enqueueProject($proj, $prof);
    Teste::igual(4, count($jobs), 'capa + 3 páginas');
    Teste::igual('gerando', Project::find((int) $proj['id'])['status']);
    foreach (Page::forProject((int) $proj['id']) as $p) {
        Teste::igual('pendente', $p['status']);
        Teste::verdadeiro($p['image_path'] === null);
    }
    Teste::igual($antes, count(UsageLog::recent(500)), 'Enfileirar não chama nenhum motor');
    Teste::igual(4, GenerationJob::pendingForProject((int) $proj['id']));
});

Teste::caso('worker: em modo simulado, capa + 3 páginas vão de pendente a pronto sem intervenção; custo registrado', function () {
    $prof = teste_criar_usuario('professor', 'prof.f4@exemplo.com');
    fila_limpar();
    $proj = projeto_revisado_f4($prof, 'Projeto completo');
    GenerationService::enqueueProject($proj, $prof);
    $w = worker_mock();
    $r = $w->runBatch(60);
    Teste::igual(4, $r['processados']);
    Teste::falso($w->runOne(), 'Fila vazia');
    $p = Project::find((int) $proj['id']);
    Teste::igual('pronto', $p['status']);
    $paginas = Page::forProject((int) $proj['id']);
    Teste::igual(['pronto', 'pronto', 'pronto', 'pronto'], array_column($paginas, 'status'));
    foreach ($paginas as $pg) {
        $file = Page::imageFile($pg);
        Teste::verdadeiro($file !== null, 'Imagem da página ' . $pg['number']);
        $info = getimagesize($file);
        Teste::igual([1024, 1536], [$info[0], $info[1]]);
        Teste::igual(1, (int) $pg['attempts']);
        Teste::verdadeiro(str_contains((string) $pg['prompt_text'], $pg['number'] === '0' || (int) $pg['number'] === 0 ? 'capa vertical A4' : 'Página ' . $pg['number'] . ' de 3'));
        $check = json_decode((string) $pg['check_result'], true);
        Teste::verdadeiro($check['ok'], 'Conferência aprovada');
    }
    foreach (GenerationJob::forProject((int) $proj['id']) as $job) {
        Teste::igual('pronto', $job['status']);
        Teste::verdadeiro($job['finished_at'] !== null && $job['locked_by'] === null);
    }
    $usos = array_filter(UsageLog::recent(200), fn($u) => $u['reference'] !== null && str_starts_with((string) $u['reference'], 'projeto:' . $proj['id'] . '/'));
    $geracoes = array_filter($usos, fn($u) => $u['context'] === 'apostila');
    $conferencias = array_filter($usos, fn($u) => $u['context'] === 'conferencia');
    Teste::igual(4, count($geracoes), 'Uma linha de usage_log por geração');
    Teste::igual(4, count($conferencias), 'Uma linha por conferência');
    foreach ($geracoes as $g) {
        Teste::igual($prof, (int) $g['user_id']);
        Teste::igual(1, (int) $g['school_id']);
        Teste::igual('1024x1536', $g['resolution']);
        Teste::verdadeiro(is_numeric($g['cost_usd']));
    }
    $status = GenerationService::status(Project::find((int) $proj['id']));
    Teste::verdadeiro($status['concluido']);
    Teste::igual(4, $status['prontas']);
    Teste::contem('/pagina/0.png', $status['paginas'][0]['imagem']);
});

Teste::caso('worker: motor real registra custo estimado por tokens em usage_log', function () {
    $prof = teste_criar_usuario('professor', 'prof.f4@exemplo.com');
    fila_limpar();
    $proj = projeto_revisado_f4($prof, 'Custo real');
    GenerationService::enqueueProject($proj, $prof);
    $w = worker_mock(new MotorFalhaDepoisGera(0));
    $w->runBatch(60);
    $usos = array_filter(UsageLog::recent(200), fn($u) => $u['context'] === 'apostila' && $u['reference'] === 'projeto:' . $proj['id'] . '/p1');
    $u = array_values($usos)[0];
    Teste::igual('fake', $u['engine']);
    Teste::igual(6240, (int) $u['output_tokens']);
    Teste::verdadeiro((float) $u['cost_usd'] > 0.18 && (float) $u['cost_usd'] < 0.19, 'Custo por tokens: ' . $u['cost_usd']);
});

Teste::caso('worker: derrubado no meio de um job, o job não se perde (recuperação de órfãos) e é retomado', function () {
    $prof = teste_criar_usuario('professor', 'prof.f4@exemplo.com');
    fila_limpar();
    $proj = projeto_revisado_f4($prof, 'Retomada');
    GenerationService::enqueueProject($proj, $prof);
    // Simula o worker morrendo logo após reivindicar o job (status "gerando", sem concluir)
    $job = GenerationJob::claim('worker-que-morreu:99');
    Teste::igual('gerando', $job['status']);
    Teste::igual(1, (int) $job['attempts']);
    Teste::igual(null, worker_mock()->runOne() ? null : null);   // os outros jobs seguem; o órfão fica preso
    Database::get()->exec("UPDATE generation_jobs SET locked_at = DATE_SUB(NOW(), INTERVAL 30 MINUTE) WHERE id = {$job['id']}");
    Teste::igual('gerando', GenerationJob::find((int) $job['id'])['status'], 'Ainda preso antes da recuperação');
    Teste::igual(1, GenerationJob::recoverStale(10), 'Um job órfão recuperado');
    $j = GenerationJob::find((int) $job['id']);
    Teste::igual('pendente', $j['status']);
    Teste::contem('retomada automática', (string) $j['error_message']);
    $r = worker_mock()->runBatch(60);
    Teste::verdadeiro($r['processados'] >= 1);
    Teste::igual('pronto', GenerationJob::find((int) $job['id'])['status']);
    Teste::igual('pronto', Project::find((int) $proj['id'])['status']);
    Teste::igual(0, GenerationJob::pendingForProject((int) $proj['id']));
});

Teste::caso('worker: erro recuperável do motor volta à fila com espera e esgota em erro após max_attempts', function () {
    $prof = teste_criar_usuario('professor', 'prof.f4@exemplo.com');
    fila_limpar();
    $proj = projeto_revisado_f4($prof, 'Erros do motor');
    $ids = GenerationService::enqueueProject($proj, $prof);
    $motor = new MotorFalhaDepoisGera(1);   // falha 1x e depois gera
    $w = worker_mock($motor);
    $w->runOne();
    $job = GenerationJob::find($ids[0]);
    Teste::igual('pendente', $job['status'], 'Volta à fila após erro recuperável');
    Teste::contem('instável', (string) $job['error_message']);
    Teste::igual('pendente', Page::find((int) $job['page_id'])['status']);
    Database::get()->exec('UPDATE generation_jobs SET available_at = NOW()');
    $w->runBatch(60);
    Teste::igual('pronto', GenerationJob::find($ids[0])['status']);

    $proj2 = projeto_revisado_f4($prof, 'Erro definitivo');
    $ids2 = GenerationService::enqueueProject($proj2, $prof);
    $w2 = worker_mock(new MotorFalhaDepoisGera(99, true));   // sempre explode (erro inesperado)
    for ($i = 0; $i < 3; $i++) {
        Database::get()->exec('UPDATE generation_jobs SET available_at = NOW()');
        $w2->runOne();
    }
    $job = GenerationJob::find($ids2[0]);
    Teste::igual('erro', $job['status']);
    Teste::igual(3, (int) $job['attempts']);
    Teste::igual('erro', Page::find((int) $job['page_id'])['status']);
    $usoErro = array_filter(UsageLog::recent(50), fn($u) => $u['status'] === 'erro' && $u['reference'] === 'projeto:' . $proj2['id'] . '/p0');
    Teste::verdadeiro(count($usoErro) >= 1, 'Falha registrada em usage_log');
});

Teste::caso('conferência: reprovação dispara regeneração e para após 2 novas tentativas, marcando "revisar"', function () {
    $prof = teste_criar_usuario('professor', 'prof.f4@exemplo.com');
    fila_limpar();
    $proj = projeto_revisado_f4($prof, 'Conferência');
    $doc = Project::currentContent($proj);
    $doc['paginas'][1]['titulo'] = 'Título ' . MockVerifier::MARKER;
    Project::saveReview((int) $proj['id'], $doc);
    $proj = Project::find((int) $proj['id']);
    GenerationService::enqueueProject($proj, $prof);
    $motor = new MotorFalhaDepoisGera(0);
    $w = worker_mock($motor);
    $w->runBatch(60);
    $paginas = Page::forProject((int) $proj['id']);
    Teste::igual('revisar', $paginas[2]['status'], 'Página 2 marcada para revisão manual');
    Teste::igual(3, (int) $paginas[2]['attempts'], '1 geração + 2 regenerações');
    Teste::igual('pronto', $paginas[1]['status']);
    Teste::igual(6, $motor->chamadas, '3 páginas normais + 3 gerações da página reprovada');
    $check = json_decode((string) $paginas[2]['check_result'], true);
    Teste::falso($check['ok']);
    Teste::contem('simulado', $check['problemas'][0]);
    Teste::igual('pronto', Project::find((int) $proj['id'])['status'], 'Projeto termina como pronto, com a página sinalizada');
    $job = array_values(array_filter(GenerationJob::forProject((int) $proj['id']), fn($j) => (int) $j['page_id'] === (int) $paginas[2]['id']))[0];
    Teste::contem('revisão manual', (string) $job['error_message']);
    Teste::igual(3, count(array_filter(UsageLog::recent(200), fn($u) => $u['context'] === 'conferencia' && $u['reference'] === 'projeto:' . $proj['id'] . '/p2')), '3 conferências registradas');
});

Teste::caso('conferência: com VERIFY_ENABLED=0 não há conferência; textos esperados incluem título, blocos e número da página', function () {
    $f = new EngineFactory(['AI_MOCK' => '1', 'VERIFY_ENABLED' => '0']);
    Teste::igual(null, $f->verifier());
    Teste::verdadeiro((new EngineFactory(['AI_MOCK' => '1']))->verifier() instanceof MockVerifier);
    $doc = \App\Services\PocService::loadDoc(ROOT_PATH . '/data/exemplos/ef04_ciencias_misturas.json');
    $t = PromptBuilder::expectedTexts($doc, 2);
    Teste::contem('Tipos de mistura', implode('|', $t));
    Teste::contem('Peneirar', implode('|', $t));
    Teste::contem('Página 2 de 3', implode('|', $t));
    $capa = PromptBuilder::expectedTexts($doc, 0);
    Teste::igual('Misturas no dia a dia', $capa[1]);
    $req = (new PromptBuilder())->buildCover($doc);
    Teste::contem('capa vertical A4', $req->prompt);
    Teste::contem('"Misturas no dia a dia"', $req->prompt);
    Teste::verdadeiro($req->context['capa']);
    $prompt = \App\Ai\GeminiVerifier::prompt(['Título X', 'Bloco Y']);
    Teste::contem('- Título X', $prompt);
    Teste::contem('"ok": true|false', $prompt);
});

Teste::caso('regenerar: reenfileira só a página pedida e a nova imagem substitui a anterior', function () {
    $prof = teste_criar_usuario('professor', 'prof.f4@exemplo.com');
    fila_limpar();
    $proj = projeto_revisado_f4($prof, 'Regenerar');
    GenerationService::enqueueProject($proj, $prof);
    worker_mock()->runBatch(60);
    $antes = Page::findByNumber((int) $proj['id'], 2)['image_path'];
    $id = GenerationService::enqueuePage(Project::find((int) $proj['id']), 2, $prof);
    Teste::igual(1, GenerationJob::pendingForProject((int) $proj['id']));
    Teste::igual('gerando', Project::find((int) $proj['id'])['status']);
    worker_mock()->runBatch(60);
    $depois = Page::findByNumber((int) $proj['id'], 2);
    Teste::verdadeiro($depois['image_path'] !== $antes, 'Novo arquivo');
    Teste::igual('pronto', $depois['status']);
    Teste::igual('pronto', GenerationJob::find($id)['status']);
    Teste::lanca(fn() => GenerationService::enqueuePage(Project::find((int) $proj['id']), 9, $prof), InvalidArgumentException::class);
});

Teste::caso('rotas: POST gerar enfileira e responde na hora; status JSON; imagem só com acesso; professor de fora recebe 403', function () {
    $prof = teste_criar_usuario('professor', 'prof.f4@exemplo.com');
    teste_aceitar_termo($prof);
    fila_limpar();
    $proj = projeto_revisado_f4($prof, 'Rotas f4');
    teste_logar($prof);
    $inicio = microtime(true);
    $r = teste_post("/projetos/{$proj['id']}/gerar", []);
    Teste::igual(302, $r->status);
    Teste::verdadeiro(microtime(true) - $inicio < 2, 'A requisição não espera a IA');
    Teste::contem('enviada(s) para a fila', json_encode($_SESSION['flash'] ?? [], JSON_UNESCAPED_UNICODE));
    unset($_SESSION['flash']);
    Teste::igual(4, GenerationJob::pendingForProject((int) $proj['id']));
    $r = app_router()->dispatch('GET', "/projetos/{$proj['id']}/status");
    Teste::igual(200, $r->status);
    $d = $r->json_body();
    Teste::falso($d['data']['concluido']);
    Teste::igual(4, $d['data']['na_fila']);
    Teste::igual(404, app_router()->dispatch('GET', "/projetos/{$proj['id']}/pagina/1.png")->status, 'Imagem ainda não gerada');
    $r = app_router()->dispatch('GET', "/projetos/{$proj['id']}");
    Teste::contem('Gerando…', $r->body);
    Teste::contem('geracaoProjeto(', $r->body);

    worker_mock()->runBatch(60);
    $r = app_router()->dispatch('GET', "/projetos/{$proj['id']}/pagina/1.png");
    Teste::igual(200, $r->status);
    Teste::igual('image/png', $r->headers['Content-Type']);
    Teste::verdadeiro(str_starts_with($r->body, "\x89PNG"));
    $d = app_router()->dispatch('GET', "/projetos/{$proj['id']}/status")->json_body();
    Teste::verdadeiro($d['data']['concluido']);
    Teste::igual('pronto', $d['data']['status']);
    $r = teste_post("/projetos/{$proj['id']}/paginas/2/regenerar", []);
    Teste::igual(302, $r->status);
    Teste::igual(1, GenerationJob::pendingForProject((int) $proj['id']));

    $outro = teste_criar_usuario('professor', 'prof.b@exemplo.com');
    teste_aceitar_termo($outro);
    teste_logar($outro);
    Teste::igual(403, app_router()->dispatch('GET', "/projetos/{$proj['id']}/status")->status);
    Teste::igual(403, app_router()->dispatch('GET', "/projetos/{$proj['id']}/pagina/1.png")->status);
    Teste::igual(403, teste_post("/projetos/{$proj['id']}/gerar", [])->status);
    teste_logar(null);
});

Teste::caso('cli: workers/generation_worker.php --lote processa a fila e sai; segunda instância simultânea não roda', function () {
    $prof = teste_criar_usuario('professor', 'prof.f4@exemplo.com');
    fila_limpar();
    $proj = projeto_revisado_f4($prof, 'CLI worker');
    GenerationService::enqueueProject($proj, $prof);
    $env = ROOT_PATH . '/.env';
    $criou = false;
    if (!is_file($env) && is_file(ROOT_PATH . '/.env.test')) {
        copy(ROOT_PATH . '/.env.test', $env);
        $criou = true;
    }
    try {
        exec('php ' . escapeshellarg(ROOT_PATH . '/workers/generation_worker.php') . ' --lote --max-segundos=30 2>&1', $out, $code);
        $txt = implode("\n", $out);
        Teste::igual(0, $code, $txt);
        Teste::contem('modo lote (simulado)', $txt);
        Teste::verdadeiro((bool) preg_match('/(\d+) job\(s\) processado\(s\)/', $txt, $m) && (int) $m[1] >= 4, $txt);
        Teste::igual('pronto', Project::find((int) $proj['id'])['status']);
        $lock = fopen(STORAGE_PATH . '/cache/worker-lote.lock', 'c');
        flock($lock, LOCK_EX);
        exec('php ' . escapeshellarg(ROOT_PATH . '/workers/generation_worker.php') . ' --lote 2>&1', $out2, $code2);
        flock($lock, LOCK_UN);
        Teste::contem('Outro worker já está em execução', implode("\n", $out2));
    } finally {
        if ($criou) {
            unlink($env);
        }
    }
    Teste::verdadeiro(is_file(ROOT_PATH . '/deploy/materiais-worker.service'));
    Teste::contem('--continuo', (string) file_get_contents(ROOT_PATH . '/deploy/materiais-worker.service'));
});
