<?php
// tests/cases/fase0_roteador.php — roteador, páginas e tratamento de erro
declare(strict_types=1);

use App\Response;
use App\Router;

Teste::caso('roteador: GET / devolve a página inicial em HTML', function () {
    $r = app_router()->dispatch('GET', '/');
    Teste::igual(200, $r->status);
    Teste::contem('text/html', $r->headers['Content-Type'] ?? '');
    Teste::contem('<html lang="pt-BR">', $r->body);
    Teste::contem('Materiais visuais com IA', $r->body);
    Teste::contem('alpinejs', $r->body, 'Alpine.js via CDN');
});

Teste::caso('roteador: barra final e query string são ignoradas', function () {
    Teste::igual(200, app_router()->dispatch('GET', '/health/?x=1')->status);
    Teste::igual(200, app_router()->dispatch('HEAD', '/')->status);
});

Teste::caso('roteador: rota inexistente devolve 404 em português', function () {
    $r = app_router()->dispatch('GET', '/nao-existe');
    Teste::igual(404, $r->status);
    Teste::contem('Página não encontrada', $r->body);
});

Teste::caso('roteador: método errado devolve 405', function () {
    $r = app_router()->dispatch('POST', '/health');
    Teste::igual(405, $r->status);
    Teste::contem('Método não permitido', $r->body);
});

Teste::caso('roteador: parâmetros {nome} chegam ao controlador decodificados', function () {
    $router = new Router();
    $router->get('/projetos/{id}/paginas/{n}', fn(array $a) => Response::json($a));
    $r = $router->dispatch('GET', '/projetos/42/paginas/p%C3%A1g');
    Teste::igual(200, $r->status);
    Teste::igual(['id' => '42', 'n' => 'pág'], $r->json_body());
});

Teste::caso('roteador: exceção no controlador vira 500 amigável e vai para o log', function () {
    $log = LOG_PATH . '/app.log';
    $tamanhoAntes = is_file($log) ? filesize($log) : 0;
    $router = new Router();
    $router->get('/quebra', function (): never {
        throw new RuntimeException('detalhe técnico secreto');
    });
    $r = $router->dispatch('GET', '/quebra');
    Teste::igual(500, $r->status);
    Teste::contem('erro interno', $r->body);
    if (!APP_DEBUG) {
        Teste::naoContem('detalhe técnico secreto', $r->body, 'Detalhe técnico não pode vazar em produção');
    }
    clearstatcache();
    Teste::verdadeiro(is_file($log) && filesize($log) > $tamanhoAntes, 'Erro não foi registrado no log');
    Teste::contem('detalhe técnico secreto', (string) file_get_contents($log));
});

Teste::caso('roteador: saída HTML escapa dados (função e)', function () {
    Teste::igual('&lt;b&gt;&quot;x&quot;&#039;', e('<b>"x"\''));
});

Teste::caso('roteador: prefixo de subpasta (BASE_PATH) é removido do caminho', function () {
    Teste::igual('/health', Router::stripBasePath('/materiais/health', '/materiais'));
    Teste::igual('/', Router::stripBasePath('/materiais', '/materiais'));
    Teste::igual('/', Router::stripBasePath('/materiais/', '/materiais/'));
    Teste::igual('/outro/materiais', Router::stripBasePath('/outro/materiais', '/materiais'), 'Só remove no início');
    Teste::igual('/health', Router::stripBasePath('/health', ''), 'Sem subpasta nada muda');
    Teste::igual(200, app_router()->dispatch('GET', '/materiais/health', '/materiais')->status);
    Teste::igual(200, app_router()->dispatch('GET', '/materiais', '/materiais')->status, 'Raiz da subpasta');
    Teste::igual(404, app_router()->dispatch('GET', '/materiais/nada', '/materiais')->status);
});

Teste::caso('roteador: asset() acrescenta a versão (data do arquivo) à URL do CSS/JS', function () {
    $u = asset('/assets/js/app.js');
    Teste::verdadeiro((bool) preg_match('#/assets/js/app\\.js\\?v=\\d{9,}$#', $u), $u);
    Teste::contem('?v=', asset('/assets/css/app.css'));
    $html = app_router()->dispatch('GET', '/')->body;
    Teste::contem('/assets/js/app.js?v=', $html);
    Teste::contem('/assets/css/app.css?v=', $html);
});

Teste::caso('layout: app.js é carregado antes do Alpine (os componentes são registrados no alpine:init)', function () {
    $html = app_router()->dispatch('GET', '/')->body;
    $posApp = strpos($html, '/assets/js/app.js');
    $posAlpine = strpos($html, 'alpinejs@');
    Teste::verdadeiro($posApp !== false && $posAlpine !== false && $posApp < $posAlpine, 'app.js deve vir antes do script do Alpine');
    $js = (string) file_get_contents(ROOT_PATH . '/public/assets/js/app.js');
    Teste::contem("Alpine.data('assistente'", $js);
    Teste::contem("Alpine.data('estadoSistema'", $js);
    Teste::contem('registrarAlpine(', $js);
    Teste::naoContem("document.addEventListener('alpine:init', () =>", $js);
});

Teste::caso('roteador: url() monta endereços com BASE_URL', function () {
    $base = BASE_URL !== '' ? BASE_URL : BASE_PATH;
    Teste::igual($base . '/health', url('/health'));
    Teste::igual($base . '/health', url('health'));
    Teste::igual($base . '/', url('/'));
});
