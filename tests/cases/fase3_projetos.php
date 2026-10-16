<?php
// tests/cases/fase3_projetos.php — rotas do assistente, API BNCC e permissões por perfil
declare(strict_types=1);

use App\Ai\EngineFactory;
use App\Database;
use App\Models\Project;
use App\Models\User;
use App\Services\ContentService;

Teste::caso('assistente: GET /projetos/novo mostra os passos; POST /projetos gera e redireciona para a revisão', function () {
    $prof = teste_criar_usuario('professor', 'prof.rotas@exemplo.com');
    teste_aceitar_termo($prof);
    teste_logar($prof);
    $r = app_router()->dispatch('GET', '/projetos/novo');
    Teste::igual(200, $r->status);
    Teste::contem('Novo material', $r->body);
    Teste::contem('Habilidades BNCC', $r->body);
    Teste::contem('doodle_escolar', $r->body);
    $r = teste_post('/projetos', ['etapa' => 'EF_ANOS_INICIAIS', 'ano' => '4', 'componente' => 'Ciências', 'habilidades' => ['EF04CI01'], 'tema' => 'Misturas em casa', 'finalidade' => 'introducao', 'paginas' => '3', 'estilo' => 'doodle_escolar']);
    Teste::igual(302, $r->status);
    Teste::verdadeiro((bool) preg_match('#/projetos/(\d+)/revisar$#', $r->headers['Location'], $m), 'Redireciona para a revisão: ' . $r->headers['Location']);
    $id = (int) $m[1];
    $r = app_router()->dispatch('GET', "/projetos/{$id}/revisar");
    Teste::igual(200, $r->status);
    Teste::contem('data-max=', $r->body, 'Campos com limite para o contador');
    Teste::contem('Misturas em casa', $r->body);
    // Pedido inválido volta ao formulário com o motivo
    $r = teste_post('/projetos', ['etapa' => 'EF_ANOS_INICIAIS', 'ano' => '4', 'componente' => 'Ciências', 'habilidades' => ['EF07CI01'], 'tema' => 'x', 'finalidade' => 'introducao', 'paginas' => '3', 'estilo' => 'doodle_escolar']);
    Teste::contem('/projetos/novo', $r->headers['Location']);
    Teste::contem('é da etapa', json_encode($_SESSION['flash'] ?? [], JSON_UNESCAPED_UNICODE));
    unset($_SESSION['flash']);
    teste_logar(null);
});

Teste::caso('assistente: salvar revisão pela rota, ver projeto (passo 5), plano e PDF', function () {
    $prof = teste_criar_usuario('professor', 'prof.rotas@exemplo.com');
    teste_aceitar_termo($prof);
    teste_logar($prof);
    $id = projeto_de($prof, 'Rochas e minerais');
    $r = teste_post("/projetos/{$id}/revisar", ['tema' => 'Rochas e minerais', 'paginas' => [0 => ['titulo' => 'Título revisado']]]);
    Teste::contem("/projetos/{$id}", $r->headers['Location']);
    Teste::igual('revisado', Project::find($id)['status']);
    $r = app_router()->dispatch('GET', "/projetos/{$id}");
    Teste::igual(200, $r->status);
    Teste::contem('Título revisado', $r->body);
    Teste::contem('revisada pelo professor', $r->body);
    $r = app_router()->dispatch('GET', "/projetos/{$id}/plano");
    Teste::igual(200, $r->status);
    Teste::contem('Plano de aula', $r->body);
    $r = app_router()->dispatch('GET', "/projetos/{$id}/plano.pdf");
    Teste::igual(200, $r->status);
    Teste::igual('application/pdf', $r->headers['Content-Type']);
    Teste::verdadeiro(str_starts_with($r->body, '%PDF-'));
    // Revisão com limite estourado não salva
    $r = teste_post("/projetos/{$id}/revisar", ['paginas' => [0 => ['titulo' => str_repeat('y', 80)]]]);
    Teste::contem("/projetos/{$id}/revisar", $r->headers['Location']);
    Teste::contem('tem 80 caracteres', json_encode($_SESSION['revisao_erros'] ?? [], JSON_UNESCAPED_UNICODE));
    unset($_SESSION['flash'], $_SESSION['revisao_erros']);
    teste_logar(null);
});

Teste::caso('permissão: professor só vê os próprios projetos; coordenador vê os da escola; admin vê todos', function () {
    Database::get()->exec("UPDATE projects SET deleted_at = NOW() WHERE deleted_at IS NULL");
    $escola2 = Database::get()->query("SELECT id FROM schools WHERE name = 'EMEF Teste Fase 2'")->fetchColumn();
    if ($escola2 === false) {
        App\Models\School::create(['name' => 'EMEF Teste Fase 2', 'short_name' => 'F2', 'inep_code' => null, 'city' => 'SCS', 'active' => 1]);
        $escola2 = Database::get()->query("SELECT id FROM schools WHERE name = 'EMEF Teste Fase 2'")->fetchColumn();
    }
    $profA = teste_criar_usuario('professor', 'prof.a@exemplo.com');
    $profB = teste_criar_usuario('professor', 'prof.b@exemplo.com');
    Database::get()->exec("UPDATE users SET school_id = {$escola2} WHERE id = {$profB}");
    $coord = teste_criar_usuario('coordenador', 'coord.escola1@exemplo.com');
    foreach ([$profA, $profB, $coord, 1] as $u) {
        teste_aceitar_termo($u);
    }
    teste_preparar_admin();
    $pa = projeto_de($profA, 'Projeto do A');
    $pb = projeto_de($profB, 'Projeto do B');

    teste_logar($profA);
    $r = app_router()->dispatch('GET', '/projetos');
    Teste::contem('Projeto do A', $r->body);
    Teste::naoContem('Projeto do B', $r->body);
    Teste::igual(200, app_router()->dispatch('GET', "/projetos/{$pa}")->status);
    Teste::igual(403, app_router()->dispatch('GET', "/projetos/{$pb}")->status, 'Professor não vê projeto de outro');
    Teste::igual(403, app_router()->dispatch('GET', "/projetos/{$pb}/revisar")->status);
    Teste::igual(403, app_router()->dispatch('GET', "/projetos/{$pb}/plano.pdf")->status);
    Teste::igual(403, teste_post("/projetos/{$pb}/excluir", [])->status);

    teste_logar($coord);   // escola 1
    $r = app_router()->dispatch('GET', '/projetos');
    Teste::contem('Projeto do A', $r->body, 'Coordenador vê projeto da própria escola');
    Teste::naoContem('Projeto do B', $r->body, 'Coordenador não vê projeto de outra escola');
    Teste::igual(200, app_router()->dispatch('GET', "/projetos/{$pa}")->status);
    Teste::igual(403, app_router()->dispatch('GET', "/projetos/{$pa}/revisar")->status, 'Coordenador só visualiza');
    Teste::igual(403, app_router()->dispatch('GET', "/projetos/{$pb}")->status);

    teste_logar(1);
    $r = app_router()->dispatch('GET', '/projetos');
    Teste::contem('Projeto do A', $r->body);
    Teste::contem('Projeto do B', $r->body);
    Teste::igual(200, app_router()->dispatch('GET', "/projetos/{$pb}/revisar")->status);

    teste_logar(null);
    Teste::igual(302, app_router()->dispatch('GET', '/projetos')->status);
});

Teste::caso('busca: Meus projetos filtra por componente, ano e código BNCC; excluir some da lista', function () {
    $profA = teste_criar_usuario('professor', 'prof.a@exemplo.com');
    teste_aceitar_termo($profA);
    teste_logar($profA);
    $id = projeto_de($profA, 'Projeto filtrado');
    Teste::contem('Projeto filtrado', app_router()->dispatch('GET', '/projetos?habilidade=EF04CI01')->body);
    Teste::naoContem('Projeto filtrado', app_router()->dispatch('GET', '/projetos?habilidade=EF04CI02')->body);
    Teste::contem('Projeto filtrado', app_router()->dispatch('GET', '/projetos?componente=Ci%C3%AAncias&ano=4')->body);
    Teste::naoContem('Projeto filtrado', app_router()->dispatch('GET', '/projetos?ano=7')->body);
    Teste::contem('Projeto filtrado', app_router()->dispatch('GET', '/projetos?q=filtrado')->body);
    $r = teste_post("/projetos/{$id}/excluir", []);
    Teste::contem('/projetos', $r->headers['Location']);
    Teste::naoContem('Projeto filtrado', app_router()->dispatch('GET', '/projetos')->body);
    Teste::igual(404, app_router()->dispatch('GET', "/projetos/{$id}")->status);
    teste_logar(null);
});

Teste::caso('api: componentes e habilidades do recorte em JSON (só logado)', function () {
    teste_logar(null);
    Teste::igual(302, app_router()->dispatch('GET', '/api/bncc/componentes?etapa=EF_ANOS_INICIAIS&ano=4')->status);
    $prof = teste_criar_usuario('professor', 'prof.api@exemplo.com');
    teste_aceitar_termo($prof);
    teste_logar($prof);
    $r = app_router()->dispatch('GET', '/api/bncc/componentes?etapa=EF_ANOS_INICIAIS&ano=4');
    Teste::igual(200, $r->status);
    $d = $r->json_body();
    Teste::verdadeiro($d['ok'] && in_array('Ciências', $d['data'], true));
    $r = app_router()->dispatch('GET', '/api/bncc/habilidades?etapa=EF_ANOS_INICIAIS&ano=4&componente=Ci%C3%AAncias&q=mistura');
    $d = $r->json_body();
    Teste::igual('EF04CI01', $d['data'][0]['codigo']);
    Teste::contem('Identificar misturas', $d['data'][0]['texto']);
    Teste::igual(422, app_router()->dispatch('GET', '/api/bncc/habilidades?etapa=XX')->status);
    teste_logar(null);
});
