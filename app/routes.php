<?php
// app/routes.php
// Mapa de rotas da aplicação. Cada fase acrescenta as suas aqui.
declare(strict_types=1);

use App\Controllers\HealthController;
use App\Controllers\HomeController;
use App\Controllers\PocController;
use App\Router;

return static function (Router $router): void {
    $router->get('/', [new HomeController(), 'index']);
    $router->get('/health', [new HealthController(), 'index']);

    // Fase 1 — prova de conceito dos motores (protegida por POC_ACCESS_KEY até a Fase 2)
    $poc = new PocController();
    $router->get('/poc', [$poc, 'index']);
    $router->post('/poc/entrar', [$poc, 'login']);
    $router->post('/poc/sair', [$poc, 'logout']);
    $router->post('/poc/gerar', [$poc, 'generate']);
    $router->get('/poc/imagem/{run}/{arquivo}', [$poc, 'image']);
};
