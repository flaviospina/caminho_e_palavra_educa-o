<?php
// tests/helpers.php
// Funções de apoio compartilhadas entre os arquivos de teste (carregadas pelo
// bootstrap, para que qualquer filtro — ex.: `php tests/run.php fase5` — rode
// isolado). Helpers específicos de um arquivo continuam no próprio arquivo.
declare(strict_types=1);

use App\Ai\EngineFactory;
use App\Ai\ImageEngine;
use App\Ai\MockVerifier;
use App\Ai\Verifier;
use App\Csrf;
use App\Database;
use App\Models\Project;
use App\Models\User;
use App\Services\ContentService;
use App\Services\GenerationWorker;

/** Faz um POST pelo roteador com o token CSRF da sessão (Fase 2). */
function teste_post(string $path, array $post, bool $csrf = true): App\Response
{
    $_POST = $post;
    if ($csrf) {
        $_POST[Csrf::FIELD] = Csrf::token();
    }
    $r = app_router()->dispatch('POST', $path);
    $_POST = [];
    return $r;
}

/** Pedido válido do assistente (Fase 3): 4º ano, Ciências, EF04CI01, 3 páginas. */
function pedido_fase3(array $extra = []): array
{
    return ContentService::validateRequest(array_merge([
        'etapa' => 'EF_ANOS_INICIAIS', 'ano' => '4', 'componente' => 'Ciências', 'habilidades' => ['EF04CI01'],
        'tema' => 'Misturas no dia a dia', 'finalidade' => 'introducao', 'paginas' => '3', 'estilo' => 'doodle_escolar',
    ], $extra));
}

/** Cria um projeto (rascunho) com conteúdo simulado para o usuário (Fase 3). */
function projeto_de(int $userId, string $tema = 'Tema de teste'): int
{
    $svc = new ContentService(new EngineFactory(['AI_MOCK' => '1']));
    return $svc->createProject(User::find($userId), pedido_fase3(['tema' => $tema]));
}

/** Cria um projeto revisado (capa + 3 páginas) pronto para gerar (Fase 4). */
function projeto_revisado_f4(int $userId, string $tema = 'Fila de teste'): array
{
    $id = projeto_de($userId, $tema);
    $p = Project::find($id);
    Project::saveReview($id, Project::currentContent($p));
    return Project::find($id);
}

/** Isola o teste: encerra jobs pendentes de testes anteriores (Fase 4). */
function fila_limpar(): void
{
    Database::get()->exec("UPDATE generation_jobs SET status = 'erro', error_message = 'encerrado pelo teste', finished_at = NOW(), locked_by = NULL WHERE status IN ('pendente', 'gerando', 'conferindo')");
}

/** Worker em modo simulado, com conferência simulada e sem espera entre tentativas (Fase 4). */
function worker_mock(?ImageEngine $engine = null, ?Verifier $verifier = null): GenerationWorker
{
    return new GenerationWorker(new EngineFactory(['AI_MOCK' => '1']), $engine, $verifier ?? new MockVerifier(), true, 'teste:1', 10, 0);
}
