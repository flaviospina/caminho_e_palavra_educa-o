<?php
// tests/cases/fase3_conteudo.php — validador, prompt, geração simulada, revisão e plano de aula
declare(strict_types=1);

use App\Ai\AiException;
use App\Ai\ContentPromptBuilder;
use App\Ai\ContentValidator;
use App\Ai\EngineFactory;
use App\Ai\GeminiText;
use App\Ai\MockEngine;
use App\Models\Project;
use App\Services\ContentService;
use App\Services\LessonPlanService;
use App\Services\PocService;

function pedido_fase3(array $extra = []): array
{
    return ContentService::validateRequest(array_merge([
        'etapa' => 'EF_ANOS_INICIAIS', 'ano' => '4', 'componente' => 'Ciências', 'habilidades' => ['EF04CI01'],
        'tema' => 'Misturas no dia a dia', 'finalidade' => 'introducao', 'paginas' => '3', 'estilo' => 'doodle_escolar',
    ], $extra));
}

Teste::caso('validador: os 6 exemplos do kit passam na validação completa (BNCC + limites)', function () {
    $v = new ContentValidator();
    foreach (PocService::examples() as $nome => $file) {
        $erros = $v->validate(PocService::loadDoc($file));
        Teste::igual([], $erros, "{$nome}: " . implode(' | ', $erros));
    }
});

Teste::caso('validador: JSON inválido é rejeitado com motivos em português', function () {
    $v = new ContentValidator();
    $erros = $v->validate(['tipo' => 'apostila', 'paginas' => []]);
    Teste::contem('Falta o campo "etapa"', implode(' ', $erros));
    $doc = PocService::loadDoc(ROOT_PATH . '/data/exemplos/ef04_ciencias_misturas.json');
    $doc['paginas'][0]['blocos'][0]['texto'] = str_repeat('a', 300);
    $doc['paginas'][1]['titulo'] = '';
    $doc['paginas'][2]['numero'] = 9;
    $doc['paginas'][0]['blocos'][1]['itens'] = ['a', 'b', 'c', 'd', 'e', 'f', 'g'];
    $erros = $v->validate($doc);
    $txt = implode("\n", $erros);
    Teste::contem('Página 1 › bloco 1 › texto tem 300 caracteres; o máximo para este estilo é 200', $txt);
    Teste::contem('Página 2 › título está vazio', $txt);
    Teste::contem('A página 3 está numerada como "9"', $txt);
    Teste::contem('bloco 2 tem 7 itens; o máximo é 5', $txt);
});

Teste::caso('validador: habilidade fora do recorte (ano/componente/etapa) ou inexistente é rejeitada', function () {
    $v = new ContentValidator();
    $doc = PocService::loadDoc(ROOT_PATH . '/data/exemplos/ef04_ciencias_misturas.json');
    $doc['habilidades'] = [['codigo' => 'EF07CI01', 'resumo' => 'x']];
    $txt = implode("\n", $v->validate($doc));
    Teste::contem('EF07CI01 é da etapa Ensino Fundamental – Anos Finais', $txt);
    $doc['habilidades'] = [['codigo' => 'EF04MA03', 'resumo' => 'x']];
    Teste::contem('EF04MA03 é de Matemática, não de Ciências', implode("\n", $v->validate($doc)));
    $doc['habilidades'] = [['codigo' => 'EF04CI99', 'resumo' => 'x']];
    Teste::contem('EF04CI99 não existe na base BNCC', implode("\n", $v->validate($doc)));
    $doc['habilidades'] = [['codigo' => 'EF05CI01', 'resumo' => 'x']];
    Teste::contem('EF05CI01 não é do 4º ano', implode("\n", $v->validate($doc)));
    // Coerência com o que o professor escolheu
    $doc['habilidades'] = [['codigo' => 'EF04CI01', 'resumo' => 'x']];
    $erros = $v->validate($doc, ['etapa' => 'EF_ANOS_INICIAIS', 'ano' => '4', 'componente' => 'Ciências', 'habilidades' => ['EF04CI02'], 'estilo' => 'doodle_escolar']);
    Teste::contem('não são as que você escolheu (EF04CI02)', implode("\n", $erros));
});

Teste::caso('pedido: validação dos passos 1–3 (recorte BNCC, tema, páginas, estilo)', function () {
    $req = pedido_fase3();
    Teste::igual('EF04CI01', $req['habilidades'][0]['code']);
    Teste::contem('Identificar misturas', $req['habilidades'][0]['description']);
    $e = Teste::lanca(fn() => pedido_fase3(['habilidades' => ['EF07CI01']]), InvalidArgumentException::class);
    Teste::contem('é da etapa', $e->getMessage());
    $e = Teste::lanca(fn() => pedido_fase3(['habilidades' => []]), InvalidArgumentException::class);
    Teste::contem('pelo menos uma habilidade', $e->getMessage());
    $e = Teste::lanca(fn() => pedido_fase3(['tema' => 'ab']), InvalidArgumentException::class);
    Teste::contem('tema', $e->getMessage());
    $e = Teste::lanca(fn() => pedido_fase3(['paginas' => '9']), InvalidArgumentException::class);
    Teste::contem('entre 1 e 6', $e->getMessage());
    $e = Teste::lanca(fn() => pedido_fase3(['estilo' => 'x']), InvalidArgumentException::class);
    Teste::contem('estilo', $e->getMessage());
    Teste::lanca(fn() => pedido_fase3(['habilidades' => ['EF04CI01', 'EF04CI02', 'EF04CI03', 'EF04CI04']]), InvalidArgumentException::class);
});

Teste::caso('prompt: habilidades oficiais, limites do estilo e esquema no prompt de texto', function () {
    $p = (new ContentPromptBuilder())->build(pedido_fase3());
    Teste::contem('EF04CI01: Identificar misturas na vida diária', $p['user']);
    Teste::contem('"tipo": "apostila"', $p['system']);
    Teste::contem('título de bloco até 28;', $p['system'], 'limite do doodle_escolar');
    Teste::contem('nunca invente códigos', $p['system']);
    Teste::contem('Dados do pedido (JSON):', $p['user']);
    Teste::contem('abertura, explicacao, pratica', $p['user']);
    $g = new GeminiText('k', 'gemini-3.8-flash');
    $body = $g->buildBody($p['system'], $p['user']);
    Teste::igual('application/json', $body['generationConfig']['responseMimeType']);
    Teste::igual($p['system'], $body['systemInstruction']['parts'][0]['text']);
    Teste::igual(['a' => 1], GeminiText::decodeJson("```json\n{\"a\": 1}\n```"));
    Teste::igual(['b' => 2], GeminiText::decodeJson('Aqui está: {"b": 2} fim'));
    Teste::lanca(fn() => GeminiText::parseResponse(['candidates' => [['content' => ['parts' => [['text' => 'não é json']]]]]]), AiException::class);
});

Teste::caso('geração simulada: JSON coerente com o pedido, válido, salvo como rascunho com páginas', function () {
    $req = pedido_fase3(['tema' => 'Separação de misturas', 'paginas' => '2']);
    $svc = new ContentService(new EngineFactory(['AI_MOCK' => '1']));
    $r = $svc->generate($req, new MockEngine());
    Teste::igual([], $r['erros'], implode(' | ', $r['erros']));
    Teste::igual('Separação de misturas', $r['doc']['tema']);
    Teste::igual(['EF04CI01'], array_column($r['doc']['habilidades'], 'codigo'));
    Teste::igual(2, count($r['doc']['paginas']));
    Teste::igual('pratica', $r['doc']['paginas'][1]['tipo']);
    Teste::igual(1, $r['uso']['chamadas']);

    $prof = teste_criar_usuario('professor', 'prof.f3@exemplo.com');
    $user = App\Models\User::find($prof);
    $id = $svc->createProject($user, $req);
    $p = Project::find($id);
    Teste::igual('rascunho', $p['status']);
    Teste::igual('EF04CI01', $p['skill_codes']);
    Teste::igual($prof, (int) $p['user_id']);
    Teste::igual(1, (int) $p['school_id']);
    Teste::verdadeiro($p['reviewed_json'] === null);
    $paginas = App\Models\Page::forProject($id);
    Teste::igual([0, 1, 2], array_map('intval', array_column($paginas, 'number')), 'capa + 2 páginas');
    Teste::igual('capa', $paginas[0]['kind']);
    $usos = App\Models\UsageLog::recent(3);
    Teste::igual('conteudo', $usos[0]['context']);
});

Teste::caso('geração simulada: conteúdo inválido vindo da IA é rejeitado com os motivos (após 1 correção)', function () {
    $req = pedido_fase3(['observacoes' => 'SIMULAR_JSON_INVALIDO']);
    $svc = new ContentService(new EngineFactory(['AI_MOCK' => '1']));
    $r = $svc->generate($req, new MockEngine());
    Teste::verdadeiro($r['erros'] !== [], 'Deveria ter erros');
    Teste::igual(2, $r['uso']['chamadas'], 'Uma nova tentativa de correção');
    $user = App\Models\User::find(teste_criar_usuario('professor', 'prof.f3@exemplo.com'));
    $e = Teste::lanca(fn() => $svc->createProject($user, $req), AiException::class);
    Teste::contem('não passou na validação', $e->getMessage());
    Teste::contem('Falta o campo', $e->getMessage());
});

Teste::caso('revisão: o professor edita qualquer bloco e salva; versão gerada e revisada ficam separadas; reverter', function () {
    $svc = new ContentService(new EngineFactory(['AI_MOCK' => '1']));
    $user = App\Models\User::find(teste_criar_usuario('professor', 'prof.f3@exemplo.com'));
    $id = $svc->createProject($user, pedido_fase3());
    $p = Project::find($id);
    $doc = Project::currentContent($p);
    $form = ['tema' => 'Misturas na cozinha', 'paginas' => [0 => ['titulo' => 'Novo título', 'blocos' => [0 => ['titulo' => 'Objetivo', 'texto' => 'Texto revisado pelo professor.'], 1 => ['titulo' => 'O que você vai aprender', 'itens' => "Item A\nItem B\n\nItem C"]]]]];
    $r = $svc->applyReview($doc, $form);
    Teste::igual([], $r['erros'], implode(' | ', $r['erros']));
    Teste::igual('Novo título', $r['doc']['paginas'][0]['titulo']);
    Teste::igual('Texto revisado pelo professor.', $r['doc']['paginas'][0]['blocos'][0]['texto']);
    Teste::igual(['Item A', 'Item B', 'Item C'], $r['doc']['paginas'][0]['blocos'][1]['itens']);
    Project::saveReview($id, $r['doc']);
    $p = Project::find($id);
    Teste::igual('revisado', $p['status']);
    Teste::igual('Misturas na cozinha', Project::currentContent($p)['tema']);
    Teste::igual('Misturas no dia a dia', Project::generatedContent($p)['tema'], 'Versão gerada preservada');
    Teste::verdadeiro($p['reviewed_at'] !== null);
    // Limite excedido é recusado
    $r = $svc->applyReview(Project::currentContent($p), ['paginas' => [0 => ['titulo' => str_repeat('x', 60)]]]);
    Teste::contem('Página 1 › título tem 60 caracteres', implode(' ', $r['erros']));
    Project::revertReview($id);
    $p = Project::find($id);
    Teste::igual('rascunho', $p['status']);
    Teste::verdadeiro($p['reviewed_json'] === null);
});

Teste::caso('plano de aula: gerado do mesmo JSON sem IA e exportado em PDF com texto selecionável', function () {
    $doc = PocService::loadDoc(ROOT_PATH . '/data/exemplos/ef04_ciencias_misturas.json');
    $svc = new LessonPlanService();
    $plan = $svc->build($doc, ['school_name' => 'Escola de Teste', 'user_name' => 'Professora Ana']);
    Teste::contem('Plano de aula', $plan['titulo']);
    Teste::igual('EF04CI01', $plan['habilidades'][0]['codigo']);
    Teste::contem('Identificar misturas na vida diária', $plan['habilidades'][0]['texto'], 'Texto oficial da habilidade');
    Teste::igual(3, count($plan['momentos']));
    Teste::contem('Momento 3', $plan['momentos'][2]['titulo']);
    Teste::verdadeiro(count($plan['avaliacao']) >= 1);
    $html = $svc->html($plan);
    Teste::contem('<h2>Habilidades BNCC</h2>', $html);
    $usosAntes = count(App\Models\UsageLog::recent(100));
    $pdf = $svc->pdf($plan);
    Teste::verdadeiro(str_starts_with($pdf, '%PDF-'), 'Cabeçalho PDF');
    Teste::verdadeiro(strlen($pdf) > 5000, 'PDF muito pequeno');
    Teste::contem('/Font', $pdf, 'Fonte embutida (texto real, não imagem)');
    Teste::igual($usosAntes, count(App\Models\UsageLog::recent(100)), 'Plano não consome IA');
    $tmp = sys_get_temp_dir() . '/plano-teste.pdf';
    file_put_contents($tmp, $pdf);
    if (trim((string) shell_exec('which pdftotext')) !== '') {
        $texto = (string) shell_exec('pdftotext ' . escapeshellarg($tmp) . ' - 2>/dev/null');
        Teste::contem('Plano de aula', $texto, 'Texto extraível do PDF');
        Teste::contem('EF04CI01', $texto);
        Teste::contem('Avaliação', $texto);
    }
    unlink($tmp);
});
