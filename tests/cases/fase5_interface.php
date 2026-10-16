<?php
// tests/cases/fase5_interface.php — identidade visual, cartazes, PDF da apostila, duplicar, estilos, busca BNCC
declare(strict_types=1);

use App\Ai\EngineFactory;
use App\Ai\PromptBuilder;
use App\Database;
use App\Models\Page;
use App\Models\Project;
use App\Models\School;
use App\Services\BookPdfService;
use App\Services\CoverBranding;
use App\Services\GenerationService;
use App\Services\PosterService;
use App\Services\SchemaUpdater;
use App\Upload;

function png_teste(int $w = 200, int $h = 80, string $cor = '#1D4ED8'): string
{
    $img = imagecreatetruecolor($w, $h);
    $rgb = CoverBranding::hex($cor);
    imagefilledrectangle($img, 0, 0, $w, $h, imagecolorallocate($img, $rgb[0], $rgb[1], $rgb[2]));
    ob_start();
    imagepng($img);
    return (string) ob_get_clean();
}

Teste::caso('estrutura: SchemaUpdater aplica as colunas novas de forma idempotente (MySQL 5.7)', function () {
    $u = new SchemaUpdater();
    Teste::igual([], $u->pending(), 'Bootstrap já aplicou');
    Database::get()->exec('ALTER TABLE schools DROP COLUMN tagline');
    Teste::igual([['tabela' => 'schools', 'coluna' => 'tagline']], $u->pending());
    Teste::igual(['schools.tagline'], $u->apply());
    Teste::igual([], $u->apply(), 'Segunda aplicação não faz nada');
    Teste::verdadeiro($u->columnExists('pages', 'format'));
});

Teste::caso('identidade: upload do logotipo valida MIME real, renomeia e grava fora de public/', function () {
    $tmp = sys_get_temp_dir() . '/logo-teste.png';
    file_put_contents($tmp, png_teste());
    $_FILES['logo'] = ['name' => 'qualquer.exe', 'type' => 'application/octet-stream', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => filesize($tmp)];
    $rel = Upload::image('logo', 'schools');
    Teste::verdadeiro((bool) preg_match('#^schools/[a-f0-9]{24}\.png$#', $rel), 'Nome aleatório pela extensão real: ' . $rel);
    Teste::verdadeiro(is_file(STORAGE_PATH . '/' . $rel));
    Teste::falso(str_contains(STORAGE_PATH . '/' . $rel, '/public/'));
    $falso = sys_get_temp_dir() . '/falso.png';
    file_put_contents($falso, '<?php echo 1; ?>');
    $_FILES['logo'] = ['name' => 'falso.png', 'type' => 'image/png', 'tmp_name' => $falso, 'error' => UPLOAD_ERR_OK, 'size' => filesize($falso)];
    $e = Teste::lanca(fn() => Upload::image('logo', 'schools'), InvalidArgumentException::class);
    Teste::contem('PNG, JPG ou WebP', $e->getMessage());
    $_FILES = [];
    Teste::igual(null, Upload::path('../.env'), 'Caminho com .. é recusado');
    School::setLogo(1, $rel);
    $_FILES = [];
});

Teste::caso('identidade: a capa gerada recebe a faixa com logotipo/nome da escola e nome da rede', function () {
    $escola = School::find(1);
    School::update(1, ['name' => $escola['name'], 'short_name' => 'Escola Teste', 'inep_code' => null, 'city' => 'SCS', 'active' => 1, 'primary_color' => '#DC2626', 'tagline' => 'Aprender com alegria']);
    $escola = School::find(1);
    $base = png_teste(1024, 1536, '#FFFFFF');
    $saida = CoverBranding::apply($base, $escola);
    Teste::verdadeiro(strlen($saida) > strlen($base), 'Imagem composta é diferente da original');
    $img = imagecreatefromstring($saida);
    $c = imagecolorsforindex($img, imagecolorat($img, 10, 1536 - 108));   // linha da cor principal no topo da faixa
    Teste::verdadeiro($c['red'] > 180 && $c['green'] < 80, 'Linha com a cor principal da escola: ' . json_encode($c));
    $c2 = imagecolorsforindex($img, imagecolorat($img, 60, 1536 - 60));   // dentro do logo (azul), após a margem de 3%
    Teste::verdadeiro($c2['blue'] > 150 && $c2['red'] < 100, 'Logotipo da escola desenhado na faixa: ' . json_encode($c2));
    // Capa pelo worker: prompt cita escola e rede, imagem final tem a faixa
    $prof = teste_criar_usuario('professor', 'prof.f5@exemplo.com');
    fila_limpar();
    $proj = projeto_revisado_f4($prof, 'Capa com identidade');
    $req = (new PromptBuilder())->buildCover(Project::currentContent($proj), 'high', $escola);
    Teste::contem('"Escola Teste"', $req->prompt);
    Teste::contem(CoverBranding::networkName(), $req->prompt);
    Teste::contem('8% inferiores', $req->prompt);
    GenerationService::enqueueProject($proj, $prof);
    worker_mock()->runBatch(60);
    $capa = Page::findByNumber((int) $proj['id'], 0);
    Teste::igual('pronto', $capa['status']);
    $img = imagecreatefromstring((string) file_get_contents(Page::imageFile($capa)));
    $c = imagecolorsforindex($img, imagecolorat($img, 60, 1536 - 60));
    Teste::verdadeiro($c['blue'] > 150 && $c['red'] < 100, 'Capa do worker com o logotipo: ' . json_encode($c));
});

Teste::caso('cartaz: formulário validado; projeto com uma página por formato; prompt e textos esperados por formato', function () {
    $prof = teste_criar_usuario('professor', 'prof.f5@exemplo.com');
    $user = App\Models\User::find($prof);
    $escola = School::find(1);
    $e = Teste::lanca(fn() => PosterService::validate(['tipo_evento' => 'x'], $escola), InvalidArgumentException::class);
    Teste::contem('tipo de evento', $e->getMessage());
    $base = ['tipo_evento' => 'festa_junina', 'titulo' => 'Festa Junina 2026', 'data' => '2026-06-20', 'hora' => '18:00', 'local' => 'Quadra da escola', 'chamada' => 'Venha com a família!', 'informacoes' => "Entrada gratuita\nComidas típicas", 'estilo' => 'cartoon_infantil', 'formatos' => ['STORY', 'FEED', 'A4']];
    Teste::lanca(fn() => PosterService::validate(array_merge($base, ['data' => '2026-02-31']), $escola), InvalidArgumentException::class);
    Teste::lanca(fn() => PosterService::validate(array_merge($base, ['formatos' => []]), $escola), InvalidArgumentException::class);
    Teste::lanca(fn() => PosterService::validate(array_merge($base, ['titulo' => str_repeat('x', 41)]), $escola), InvalidArgumentException::class);
    $doc = PosterService::validate($base, $escola);
    Teste::igual('cartaz', $doc['tipo']);
    Teste::igual('20/06/2026 às 18:00', $doc['evento']['data_hora']);
    Teste::igual(['STORY', 'FEED', 'A4'], $doc['formatos']);
    $id = PosterService::create($user, $doc);
    $p = Project::find($id);
    Teste::igual('cartaz', $p['type']);
    Teste::igual('revisado', $p['status']);
    $paginas = Page::forProject($id);
    Teste::igual(['STORY', 'FEED', 'A4'], array_column($paginas, 'format'));
    Teste::igual(['cartaz', 'cartaz', 'cartaz'], array_column($paginas, 'kind'));
    $b = new PromptBuilder();
    $story = $b->buildPoster($doc, 'STORY', 'high', $escola);
    Teste::igual('STORY', $story->format);
    Teste::igual('9:16', $story->gemini_aspect());
    Teste::igual('1024x1792', $story->openai_size());
    Teste::contem('proporção 9:16', $story->prompt);
    Teste::contem('"Festa Junina 2026"', $story->prompt);
    Teste::contem('"20/06/2026 às 18:00"', $story->prompt);
    Teste::contem('- "Entrada gratuita"', $story->prompt);
    Teste::igual('1:1', $b->buildPoster($doc, 'FEED')->gemini_aspect());
    Teste::igual('2:3', $b->buildPoster($doc, 'A4')->gemini_aspect());
    Teste::igual(['Festa Junina 2026', '20/06/2026 às 18:00', 'Quadra da escola', 'Venha com a família!', 'Entrada gratuita', 'Comidas típicas'], PosterService::expectedTexts($doc));
});

Teste::caso('cartaz: gerado nos três formatos com as proporções corretas (fila + worker simulado)', function () {
    $prof = teste_criar_usuario('professor', 'prof.f5@exemplo.com');
    $user = App\Models\User::find($prof);
    fila_limpar();
    $doc = PosterService::validate(['tipo_evento' => 'feira_ciencias', 'titulo' => 'Feira de Ciências', 'data' => '2026-09-10', 'local' => 'Pátio', 'estilo' => 'infografico_limpo', 'formatos' => ['story', 'feed', 'a4']], School::find(1));
    $id = PosterService::create($user, $doc);
    $jobs = GenerationService::enqueueProject(Project::find($id), $prof);
    Teste::igual(3, count($jobs));
    worker_mock()->runBatch(60);
    $esperado = ['STORY' => [1080, 1920], 'FEED' => [1080, 1080], 'A4' => [1024, 1536]];
    foreach (Page::forProject($id) as $pg) {
        Teste::igual('pronto', $pg['status'], 'Formato ' . $pg['format']);
        $info = getimagesize(Page::imageFile($pg));
        Teste::igual($esperado[$pg['format']], [$info[0], $info[1]], 'Proporção do formato ' . $pg['format']);
    }
    Teste::igual('pronto', Project::find($id)['status']);
    $st = GenerationService::status(Project::find($id));
    Teste::igual(['STORY', 'FEED', 'A4'], array_column($st['paginas'], 'formato'));
});

Teste::caso('cartaz: rotas — formulário, criação enfileira e redireciona ao projeto, página mostra os dados do evento', function () {
    $prof = teste_criar_usuario('professor', 'prof.f5@exemplo.com');
    teste_aceitar_termo($prof);
    teste_logar($prof);
    fila_limpar();
    $r = app_router()->dispatch('GET', '/cartazes/novo');
    Teste::igual(200, $r->status);
    Teste::contem('Festa junina', $r->body);
    Teste::contem('Story (9:16)', $r->body);
    $r = teste_post('/cartazes', ['tipo_evento' => 'reuniao_pais', 'titulo' => 'Reunião de pais', 'data' => '2026-11-05', 'hora' => '19:00', 'local' => 'Sala 3', 'estilo' => 'infografico_limpo', 'formatos' => ['A4']]);
    Teste::igual(302, $r->status);
    Teste::verdadeiro((bool) preg_match('#/projetos/(\d+)$#', $r->headers['Location'], $m), $r->headers['Location']);
    $id = (int) $m[1];
    Teste::igual(1, App\Models\GenerationJob::pendingForProject($id));
    $r = app_router()->dispatch('GET', "/projetos/{$id}");
    Teste::contem('Dados do evento', $r->body);
    Teste::contem('05/11/2026 às 19:00', $r->body);
    Teste::naoContem('Plano de aula', $r->body, 'Cartaz não tem plano de aula');
    $r = teste_post('/cartazes', ['tipo_evento' => 'reuniao_pais', 'titulo' => 'x', 'data' => '', 'local' => '', 'estilo' => 'infografico_limpo']);
    Teste::contem('/cartazes/novo', $r->headers['Location']);
    unset($_SESSION['flash']);
    teste_logar(null);
});

Teste::caso('pdf: montagem no servidor respeita a ordem pedida, capa primeiro, páginas A4', function () {
    $prof = teste_criar_usuario('professor', 'prof.f5@exemplo.com');
    teste_aceitar_termo($prof);
    fila_limpar();
    $proj = projeto_revisado_f4($prof, 'Apostila em PDF');
    GenerationService::enqueueProject($proj, $prof);
    worker_mock()->runBatch(60);
    $pages = Page::forProject((int) $proj['id']);
    Teste::igual([0, 3, 1, 2], BookPdfService::normalizeOrder($pages, [3, 1, 2, 0]));
    Teste::igual([0, 2, 1, 3], BookPdfService::normalizeOrder($pages, [2, 0, 1]), 'Capa forçada em primeiro; página faltante vai ao fim');
    Teste::igual([0, 1, 2, 3], BookPdfService::normalizeOrder($pages, [9, 9]));
    $bytes = (new BookPdfService())->build(Project::find((int) $proj['id']), [3, 1, 2]);
    Teste::verdadeiro(str_starts_with($bytes, '%PDF-'));
    $tmp = sys_get_temp_dir() . '/apostila-teste.pdf';
    file_put_contents($tmp, $bytes);
    $info = (string) shell_exec('pdfinfo ' . escapeshellarg($tmp) . ' 2>/dev/null');
    Teste::contem('Pages:           4', $info);
    Teste::verdadeiro((bool) preg_match('/Page size:\s+595\.2\d+ x 841\.8\d+ pts \(A4\)/', $info), 'Página A4: ' . $info);
    $texto = (string) shell_exec('pdftotext -layout ' . escapeshellarg($tmp) . ' - 2>/dev/null');
    $linhas = array_values(array_filter(array_map('trim', explode("\n", $texto)), fn($l) => str_contains($l, 'projeto ' . $proj['id'])));
    Teste::igual(4, count($linhas), 'Rodapé em cada página: ' . json_encode($linhas));
    Teste::contem('Capa', $linhas[0]);
    Teste::contem('Página 1 de 3', $linhas[1]);
    $ordemPdf = Page::forProject((int) $proj['id']);
    unlink($tmp);
    // Rota: POST com ordem
    teste_logar($prof);
    $r = teste_post("/projetos/{$proj['id']}/apostila.pdf", ['ordem' => '3,1,2']);
    Teste::igual(200, $r->status);
    Teste::igual('application/pdf', $r->headers['Content-Type']);
    Teste::contem('attachment', $r->headers['Content-Disposition']);
    $r = app_router()->dispatch('GET', "/projetos/{$proj['id']}");
    Teste::contem('Montar o PDF da apostila', $r->body);
    Teste::contem('montagemPdf(', $r->body);
    Teste::contem('pdf-lib@1.17.1', (string) file_get_contents(ROOT_PATH . '/public/assets/js/app.js'));
    teste_logar(null);
});

Teste::caso('duplicar: cria uma cópia como rascunho com o conteúdo vigente e sem imagens', function () {
    $prof = teste_criar_usuario('professor', 'prof.f5@exemplo.com');
    teste_aceitar_termo($prof);
    teste_logar($prof);
    $id = projeto_de($prof, 'Original');
    Project::saveReview($id, array_merge(Project::currentContent(Project::find($id)), ['tema' => 'Original revisado']));
    $r = teste_post("/projetos/{$id}/duplicar", []);
    Teste::igual(302, $r->status);
    preg_match('#/projetos/(\d+)$#', $r->headers['Location'], $m);
    $novo = Project::find((int) $m[1]);
    Teste::verdadeiro((int) $novo['id'] !== $id);
    Teste::igual('rascunho', $novo['status']);
    Teste::contem('Cópia de', $novo['title']);
    Teste::igual('Original revisado', Project::currentContent($novo)['tema'], 'Cópia parte do conteúdo revisado');
    Teste::igual($prof, (int) $novo['user_id']);
    foreach (Page::forProject((int) $novo['id']) as $p) {
        Teste::verdadeiro($p['image_path'] === null);
    }
    // Cartaz: a cópia nasce revisada (pronta para gerar), com o mesmo título na arte e "Cópia de" só na lista
    $escola = School::find(1);
    $doc = PosterService::validate(['tipo_evento' => 'palestra', 'titulo' => 'Palestra sobre leitura', 'data' => '2026-08-12', 'local' => 'Auditório', 'estilo' => 'infografico_limpo', 'formatos' => ['A4']], $escola);
    $cartaz = PosterService::create(App\Models\User::find($prof), $doc);
    $r = teste_post("/projetos/{$cartaz}/duplicar", []);
    preg_match('#/projetos/(\d+)$#', $r->headers['Location'], $m);
    $copia = Project::find((int) $m[1]);
    Teste::igual('revisado', $copia['status']);
    Teste::igual('Cópia de Palestra sobre leitura', $copia['title']);
    Teste::igual('Palestra sobre leitura', Project::currentContent($copia)['evento']['titulo'], 'A arte mantém o título original');
    Teste::igual(['A4'], array_column(Page::forProject((int) $copia['id']), 'format'));
    teste_logar(null);
});

Teste::caso('estilos: biblioteca por faixa etária com amostra e prévia real', function () {
    $prof = teste_criar_usuario('professor', 'prof.f5@exemplo.com');
    teste_aceitar_termo($prof);
    teste_logar($prof);
    $r = app_router()->dispatch('GET', '/estilos');
    Teste::igual(200, $r->status);
    foreach (['Educação Infantil', 'Fundamental – Anos Iniciais', 'Fundamental – Anos Finais', 'Ensino Médio'] as $f) {
        Teste::contem($f, $r->body);
    }
    Teste::contem('assets/img/estilos/doodle_escolar.jpg', $r->body);
    Teste::contem('Página gerada', $r->body, 'Prévia real de um projeto já gerado pelo professor');
    foreach (App\Ai\Styles::all() as $e) {
        Teste::verdadeiro(!empty($e['faixas']), $e['chave'] . ' sem faixas');
    }
    teste_logar(null);
});

Teste::caso('busca: Meus projetos encontra pelo código BNCC e pelo tipo cartaz', function () {
    $prof = teste_criar_usuario('professor', 'prof.f5@exemplo.com');
    teste_aceitar_termo($prof);
    teste_logar($prof);
    $id = projeto_de($prof, 'Busca por código');
    Teste::contem('Busca por código', app_router()->dispatch('GET', '/projetos?habilidade=ef04ci01')->body, 'Código em minúsculas');
    Teste::naoContem('Busca por código', app_router()->dispatch('GET', '/projetos?habilidade=EF05CI01')->body);
    Teste::contem('Reunião de pais', app_router()->dispatch('GET', '/projetos?q=Reuni')->body);
    teste_logar(null);
});
