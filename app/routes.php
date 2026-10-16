<?php
// app/routes.php
// Mapa de rotas da aplicação. Cada fase acrescenta as suas aqui.
declare(strict_types=1);

use App\Controllers\Admin\BnccController;
use App\Controllers\Admin\SchoolsController;
use App\Controllers\Admin\UsersController;
use App\Controllers\AuthController;
use App\Controllers\DashboardController;
use App\Controllers\HealthController;
use App\Controllers\HomeController;
use App\Controllers\Api\BnccApiController;
use App\Controllers\PocController;
use App\Controllers\PostersController;
use App\Controllers\ProjectsController;
use App\Controllers\StylesController;
use App\Controllers\Admin\BrandingController;
use App\Controllers\TermController;
use App\Guard;
use App\Router;

return static function (Router $router): void {
    // Público
    $router->get('/', [new HomeController(), 'index']);
    $router->get('/health', [new HealthController(), 'index']);

    // Autenticação (Fase 2)
    $auth = new AuthController();
    $router->get('/entrar', [$auth, 'loginForm']);
    $router->post('/entrar', [$auth, 'login']);
    $router->post('/sair', [$auth, 'logout']);
    $router->get('/senha/esqueci', [$auth, 'forgotForm']);
    $router->post('/senha/esqueci', [$auth, 'forgot']);
    $router->get('/senha/redefinir/{token}', [$auth, 'resetForm']);
    $router->post('/senha/redefinir/{token}', [$auth, 'reset']);
    // Troca de senha e termo: exigem login, mas não exigem termo aceito (senão não há como aceitar)
    $router->get('/senha/trocar', Guard::roles([], [$auth, 'changeForm'], false));
    $router->post('/senha/trocar', Guard::roles([], [$auth, 'change'], false));
    $term = new TermController();
    $router->get('/termo', Guard::roles([], [$term, 'show'], false));
    $router->post('/termo', Guard::roles([], [$term, 'accept'], false));

    // Área logada
    $router->get('/painel', Guard::auth([new DashboardController(), 'index']));

    // Projetos — assistente de conteúdo e revisão (Fase 3)
    $projects = new ProjectsController();
    $router->get('/projetos', Guard::auth([$projects, 'index']));
    $router->get('/projetos/novo', Guard::auth([$projects, 'newForm']));
    $router->post('/projetos', Guard::auth([$projects, 'create']));
    $router->get('/projetos/{id}', Guard::auth([$projects, 'show']));
    $router->get('/projetos/{id}/revisar', Guard::auth([$projects, 'reviewForm']));
    $router->post('/projetos/{id}/revisar', Guard::auth([$projects, 'saveReview']));
    $router->post('/projetos/{id}/reverter', Guard::auth([$projects, 'revert']));
    $router->get('/projetos/{id}/plano', Guard::auth([$projects, 'plan']));
    $router->get('/projetos/{id}/plano.pdf', Guard::auth([$projects, 'planPdf']));
    $router->post('/projetos/{id}/excluir', Guard::auth([$projects, 'delete']));
    // Fase 4 — fila de geração de imagens
    $router->post('/projetos/{id}/gerar', Guard::auth([$projects, 'generate']));
    $router->post('/projetos/{id}/paginas/{n}/regenerar', Guard::auth([$projects, 'regeneratePage']));
    $router->get('/projetos/{id}/status', Guard::auth([$projects, 'status']));
    $router->get('/projetos/{id}/pagina/{n}.png', Guard::auth([$projects, 'pageImage']));
    // Fase 5 — cartazes, duplicar, PDF da apostila, estilos, identidade
    $router->post('/projetos/{id}/duplicar', Guard::auth([$projects, 'duplicate']));
    $router->post('/projetos/{id}/apostila.pdf', Guard::auth([$projects, 'bookPdf']));
    $posters = new PostersController();
    $router->get('/cartazes/novo', Guard::auth([$posters, 'newForm']));
    $router->post('/cartazes', Guard::auth([$posters, 'create']));
    $router->get('/estilos', Guard::auth([new StylesController(), 'index']));
    $router->get('/escolas/{id}/logo', Guard::auth([$schools = new SchoolsController(), 'logo']));
    $branding = new BrandingController();
    $router->get('/identidade/logo', Guard::auth([$branding, 'logo']));
    $router->get('/admin/identidade', Guard::admin([$branding, 'index']));
    $router->post('/admin/identidade', Guard::admin([$branding, 'save']));
    $router->post('/admin/banco/atualizar', Guard::admin([$branding, 'updateSchema']));
    $api = new BnccApiController();
    $router->get('/api/bncc/componentes', Guard::auth([$api, 'components']));
    $router->get('/api/bncc/habilidades', Guard::auth([$api, 'skills']));

    // Admin (Secretaria/CECAPE)
    $router->get('/admin/escolas', Guard::admin([$schools, 'index']));
    $router->get('/admin/escolas/nova', Guard::admin([$schools, 'createForm']));
    $router->post('/admin/escolas', Guard::admin([$schools, 'create']));
    $router->get('/admin/escolas/{id}/editar', Guard::admin([$schools, 'editForm']));
    $router->post('/admin/escolas/{id}', Guard::admin([$schools, 'update']));

    $users = new UsersController();
    $router->get('/admin/usuarios', Guard::admin([$users, 'index']));
    $router->get('/admin/usuarios/novo', Guard::admin([$users, 'createForm']));
    $router->post('/admin/usuarios', Guard::admin([$users, 'create']));
    $router->get('/admin/usuarios/{id}/editar', Guard::admin([$users, 'editForm']));
    $router->post('/admin/usuarios/{id}', Guard::admin([$users, 'update']));
    $router->post('/admin/usuarios/{id}/senha', Guard::admin([$users, 'resetPassword']));

    $bncc = new BnccController();
    $router->get('/admin/bncc', Guard::admin([$bncc, 'index']));
    $router->post('/admin/bncc/importar', Guard::admin([$bncc, 'import']));

    // Fase 1 — prova de conceito dos motores (desde a Fase 2, só admin)
    $poc = new PocController();
    $router->get('/poc', Guard::admin([$poc, 'index']));
    $router->post('/poc/gerar', Guard::admin([$poc, 'generate']));
    $router->get('/poc/imagem/{run}/{arquivo}', Guard::admin([$poc, 'image']));
};
