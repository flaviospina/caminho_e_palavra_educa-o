<?php
// tests/cases/fase2_auth.php — login, bloqueio, CSRF, sessão, permissões, termo, recuperação de senha
declare(strict_types=1);

use App\Auth;
use App\Csrf;
use App\Database;
use App\Models\AuditLog;
use App\Models\Consent;
use App\Models\PasswordReset;
use App\Models\User;
use App\Services\Mailer;

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

Teste::caso('login: senha errada é recusada com mensagem genérica e vai para audit_log', function () {
    $id = teste_criar_usuario('professor', 'prof.login@exemplo.com', 'Senha123');
    teste_logar(null);
    $r = Auth::attempt('prof.login@exemplo.com', 'errada', '10.0.0.1');
    Teste::falso($r['ok']);
    Teste::contem('E-mail ou senha incorretos', $r['mensagem']);
    Teste::contem('Restam 4 tentativa', $r['mensagem']);
    $r = Auth::attempt('ninguem@exemplo.com', 'x');
    Teste::igual('E-mail ou senha incorretos.', $r['mensagem'], 'Não revela se o e-mail existe');
    $log = AuditLog::recent(5, $id);
    Teste::igual('login_falhou', $log[0]['action']);
    Teste::igual('10.0.0.1', $log[0]['ip']);
});

Teste::caso('login: bloqueio após 5 tentativas erradas (15 min) e desbloqueio por senha provisória', function () {
    $id = teste_criar_usuario('professor', 'prof.bloqueio@exemplo.com', 'Senha123');
    for ($i = 1; $i <= 5; $i++) {
        $r = Auth::attempt('prof.bloqueio@exemplo.com', 'errada');
    }
    Teste::contem('Conta bloqueada por 15 minutos', $r['mensagem']);
    $r = Auth::attempt('prof.bloqueio@exemplo.com', 'Senha123');
    Teste::falso($r['ok'], 'Senha certa não entra enquanto bloqueado');
    Teste::contem('Conta bloqueada', $r['mensagem']);
    $u = User::find($id);
    Teste::igual(5, (int) $u['failed_attempts']);
    Teste::verdadeiro(strtotime((string) $u['locked_until']) > time());
    User::updatePassword($id, 'Nova1234', true);
    $r = Auth::attempt('prof.bloqueio@exemplo.com', 'Nova1234');
    Teste::verdadeiro($r['ok'], 'Senha provisória desbloqueia');
    teste_logar(null);
});

Teste::caso('login: sucesso regenera a sessão, zera tentativas e registra IP', function () {
    $id = teste_criar_usuario('professor', 'prof.ok@exemplo.com', 'Senha123');
    Database::get()->exec("UPDATE users SET failed_attempts = 3 WHERE id = {$id}");
    teste_logar(null);
    $_SESSION['marcador'] = 'antes';
    $r = Auth::attempt('prof.ok@exemplo.com', 'Senha123', '192.168.0.9');
    Teste::verdadeiro($r['ok']);
    Teste::igual(1, $_SESSION['_regenerated'] ?? 0, 'session_regenerate_id chamado no login');
    Teste::igual($id, Auth::id());
    Teste::igual('professor', Auth::role());
    $u = User::find($id);
    Teste::igual(0, (int) $u['failed_attempts']);
    Teste::igual('192.168.0.9', $u['last_login_ip']);
    Auth::logout();
    Teste::falso(Auth::check());
    Teste::igual([], $_SESSION, 'Logout limpa a sessão');
});

Teste::caso('login: POST /entrar sem token CSRF é rejeitado; com token entra', function () {
    teste_criar_usuario('professor', 'prof.csrf@exemplo.com', 'Senha123');
    teste_logar(null);
    $r = teste_post('/entrar', ['email' => 'prof.csrf@exemplo.com', 'senha' => 'Senha123'], false);
    Teste::igual(302, $r->status);
    Teste::falso(Auth::check(), 'Sem CSRF não pode logar');
    Teste::contem('Sessão expirada', json_encode($_SESSION['flash'] ?? [], JSON_UNESCAPED_UNICODE));
    unset($_SESSION['flash']);
    $r = teste_post('/entrar', ['email' => 'prof.csrf@exemplo.com', 'senha' => 'Senha123']);
    Teste::igual(302, $r->status);
    Teste::contem('/painel', $r->headers['Location']);
    Teste::verdadeiro(Auth::check());
    Teste::falso(Csrf::check('token-falso'));
    Teste::verdadeiro(Csrf::check(Csrf::token()));
    teste_logar(null);
});

Teste::caso('permissão: professor não acessa rotas de admin (403); anônimo vai para o login; admin acessa', function () {
    $prof = teste_criar_usuario('professor', 'prof.perm@exemplo.com');
    teste_aceitar_termo($prof);
    teste_logar($prof);
    foreach (['/admin/escolas', '/admin/usuarios', '/admin/usuarios/novo', '/admin/bncc', '/admin/escolas/1/editar'] as $rota) {
        $r = app_router()->dispatch('GET', $rota);
        Teste::igual(403, $r->status, "Professor em {$rota}");
        Teste::contem('não tem permissão', $r->body);
    }
    $r = teste_post('/admin/escolas', ['name' => 'Escola do Professor']);
    Teste::igual(403, $r->status, 'POST de admin por professor');
    Teste::igual(200, app_router()->dispatch('GET', '/painel')->status, 'Professor acessa o painel');

    $coord = teste_criar_usuario('coordenador', 'coord.perm@exemplo.com');
    teste_aceitar_termo($coord);
    teste_logar($coord);
    Teste::igual(403, app_router()->dispatch('GET', '/admin/usuarios')->status, 'Coordenador também não é admin');

    teste_logar(null);
    $r = app_router()->dispatch('GET', '/admin/escolas');
    Teste::igual(302, $r->status);
    Teste::contem('/entrar', $r->headers['Location']);

    teste_preparar_admin();
    teste_logar(1);
    teste_aceitar_termo(1);
    foreach (['/admin/escolas', '/admin/usuarios', '/admin/bncc', '/painel'] as $rota) {
        Teste::igual(200, app_router()->dispatch('GET', $rota)->status, "Admin em {$rota}");
    }
    teste_logar(null);
});

Teste::caso('termo: usuário sem aceite é levado a /termo; aceite grava data, hora e IP', function () {
    $id = teste_criar_usuario('professor', 'prof.termo@exemplo.com');
    Database::get()->prepare('DELETE FROM consents WHERE user_id = :u')->execute(['u' => $id]);
    teste_logar($id);
    $r = app_router()->dispatch('GET', '/painel');
    Teste::igual(302, $r->status);
    Teste::contem('/termo', $r->headers['Location']);
    $r = app_router()->dispatch('GET', '/termo');
    Teste::igual(200, $r->status);
    Teste::contem('Li e aceito o termo de uso', $r->body);
    Teste::contem('Nenhum dado de aluno', $r->body);

    $r = teste_post('/termo', []);
    Teste::igual(302, $r->status);
    Teste::falso(Consent::hasCurrent($id), 'Sem marcar a caixa não aceita');

    $_SERVER['REMOTE_ADDR'] = '200.150.10.5';
    $_SERVER['HTTP_USER_AGENT'] = 'Navegador de Teste';
    $r = teste_post('/termo', ['aceito' => '1']);
    Teste::contem('/painel', $r->headers['Location']);
    $c = Consent::latest($id);
    Teste::igual(TERM_VERSION, $c['term_version']);
    Teste::igual('200.150.10.5', $c['ip']);
    Teste::igual('Navegador de Teste', $c['user_agent']);
    Teste::verdadeiro(abs(time() - strtotime((string) $c['accepted_at'])) < 60, 'Data e hora do aceite');
    Teste::igual(200, app_router()->dispatch('GET', '/painel')->status, 'Depois do aceite o painel abre');
    unset($_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT']);
    teste_logar(null);
});

Teste::caso('senha provisória: obriga a troca antes de usar o painel', function () {
    $id = teste_criar_usuario('professor', 'prof.provisoria@exemplo.com', 'Prov1234', true);
    teste_aceitar_termo($id);
    teste_logar($id);
    $r = app_router()->dispatch('GET', '/painel');
    Teste::igual(302, $r->status);
    Teste::contem('/senha/trocar', $r->headers['Location']);
    $r = teste_post('/senha/trocar', ['senha_atual' => 'Prov1234', 'senha' => 'curta', 'senha2' => 'curta']);
    Teste::verdadeiro((bool) User::find($id)['must_change_pass'], 'Senha fraca não é aceita');
    unset($_SESSION['flash']);
    $r = teste_post('/senha/trocar', ['senha_atual' => 'Prov1234', 'senha' => 'Definitiva9', 'senha2' => 'Definitiva9']);
    Teste::contem('/painel', $r->headers['Location']);
    Teste::falso((bool) User::find($id)['must_change_pass']);
    Teste::verdadeiro(Auth::attempt('prof.provisoria@exemplo.com', 'Definitiva9')['ok']);
    teste_logar(null);
});

Teste::caso('recuperação de senha: e-mail com link (registrado em mail.log), token de uso único e expiração', function () {
    $id = teste_criar_usuario('professor', 'prof.reset@exemplo.com', 'Senha123');
    teste_logar(null);
    $log = Mailer::logFile();
    @unlink($log);
    $r = teste_post('/senha/esqueci', ['email' => 'prof.reset@exemplo.com']);
    Teste::igual(302, $r->status);
    Teste::verdadeiro(is_file($log), 'E-mail não registrado em mail.log');
    $mail = (string) file_get_contents($log);
    Teste::contem('Para: prof.reset@exemplo.com', $mail);
    Teste::verdadeiro((bool) preg_match('#/senha/redefinir/([a-f0-9]{64})#', $mail, $m), 'Link não encontrado no e-mail');
    $token = $m[1];
    // E-mail desconhecido: mesma resposta, nenhum e-mail
    $tam = filesize($log);
    teste_post('/senha/esqueci', ['email' => 'nao.existe@exemplo.com']);
    clearstatcache();
    Teste::igual($tam, filesize($log), 'Não envia e-mail para desconhecidos');

    Teste::igual(200, app_router()->dispatch('GET', '/senha/redefinir/' . $token)->status);
    Teste::igual(302, app_router()->dispatch('GET', '/senha/redefinir/' . str_repeat('0', 64))->status, 'Token inválido redireciona');
    $r = teste_post('/senha/redefinir/' . $token, ['senha' => 'NovaSenha1', 'senha2' => 'Diferente1']);
    Teste::falso(Auth::attempt('prof.reset@exemplo.com', 'NovaSenha1')['ok'], 'Senhas diferentes não trocam');
    $r = teste_post('/senha/redefinir/' . $token, ['senha' => 'NovaSenha1', 'senha2' => 'NovaSenha1']);
    Teste::contem('/entrar', $r->headers['Location']);
    Teste::verdadeiro(Auth::attempt('prof.reset@exemplo.com', 'NovaSenha1')['ok']);
    teste_logar(null);
    Teste::igual(null, PasswordReset::userIdFor($token), 'Token não pode ser reutilizado');
    $t2 = PasswordReset::create($id);
    Database::get()->exec('UPDATE password_resets SET expires_at = DATE_SUB(NOW(), INTERVAL 1 MINUTE)');
    Teste::igual(null, PasswordReset::userIdFor($t2), 'Token expirado');
});

Teste::caso('admin: cadastro de escola e de usuário com senha provisória, e-mail único e auditoria', function () {
    teste_preparar_admin();
    teste_logar(1);
    teste_aceitar_termo(1);
    Database::get()->exec("DELETE FROM users WHERE email = 'nova.prof@exemplo.com'");
    Database::get()->exec("DELETE FROM schools WHERE name = 'EMEF Teste Fase 2'");
    $r = teste_post('/admin/escolas', ['name' => 'EMEF Teste Fase 2', 'short_name' => 'EMEF F2', 'inep_code' => '35123456', 'active' => '1']);
    Teste::contem('/admin/escolas', $r->headers['Location']);
    $escola = Database::get()->query("SELECT * FROM schools WHERE name = 'EMEF Teste Fase 2'")->fetch();
    Teste::verdadeiro($escola !== false, 'Escola não criada');
    $r = teste_post('/admin/escolas', ['name' => 'EMEF Teste Fase 2', 'active' => '1']);
    Teste::contem('Já existe uma escola', json_encode($_SESSION['flash'] ?? [], JSON_UNESCAPED_UNICODE));
    unset($_SESSION['flash']);

    @unlink(Mailer::logFile());
    $r = teste_post('/admin/usuarios', ['name' => 'Nova Professora', 'email' => 'nova.prof@exemplo.com', 'role' => 'professor', 'school_id' => (string) $escola['id'], 'active' => '1']);
    $flash = json_encode($_SESSION['flash'] ?? [], JSON_UNESCAPED_UNICODE);
    Teste::contem('Senha provisória: Aula-', $flash);
    preg_match('/Aula-[A-Za-z0-9]{8}/', $flash, $m);
    unset($_SESSION['flash']);
    $u = User::findByEmail('nova.prof@exemplo.com');
    Teste::igual((int) $escola['id'], (int) $u['school_id']);
    Teste::igual(1, (int) $u['must_change_pass']);
    Teste::verdadeiro(password_verify($m[0], (string) $u['password_hash']), 'Senha provisória gravada com hash');
    Teste::contem('Senha provisória: ' . $m[0], (string) file_get_contents(Mailer::logFile()));

    $r = teste_post('/admin/usuarios', ['name' => 'Outra', 'email' => 'nova.prof@exemplo.com', 'role' => 'professor', 'school_id' => (string) $escola['id'], 'active' => '1']);
    Teste::contem('Já existe um usuário com este e-mail', json_encode($_SESSION['flash'] ?? [], JSON_UNESCAPED_UNICODE));
    unset($_SESSION['flash']);
    $r = teste_post('/admin/usuarios', ['name' => 'Sem Escola', 'email' => 'sem.escola@exemplo.com', 'role' => 'professor', 'active' => '1']);
    Teste::contem('precisam estar vinculados a uma escola', json_encode($_SESSION['flash'] ?? [], JSON_UNESCAPED_UNICODE));
    unset($_SESSION['flash']);

    $acoes = array_column(AuditLog::recent(10, 1), 'action');
    Teste::verdadeiro(in_array('usuario_criado', $acoes, true) && in_array('escola_criada', $acoes, true), 'Auditoria das ações do admin');
    $lista = app_router()->dispatch('GET', '/admin/usuarios?q=nova.prof');
    Teste::contem('Nova Professora', $lista->body);
    Teste::contem('senha provisória', $lista->body);
    teste_logar(null);
});

Teste::caso('lgpd: audit_log nunca guarda senha; usuário inativo não entra', function () {
    $rows = Database::get()->query("SELECT details FROM audit_log WHERE details LIKE '%password%' OR details LIKE '%senha%'")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($rows as $d) {
        Teste::falso((bool) preg_match('/"(password|senha|password_hash)"\s*:/', (string) $d), 'Detalhe com senha: ' . $d);
    }
    $id = teste_criar_usuario('professor', 'prof.inativo@exemplo.com', 'Senha123');
    Database::get()->exec("UPDATE users SET active = 0 WHERE id = {$id}");
    $r = Auth::attempt('prof.inativo@exemplo.com', 'Senha123');
    Teste::falso($r['ok']);
    Teste::contem('desativada', $r['mensagem']);
    Database::get()->exec("UPDATE users SET active = 1 WHERE id = {$id}");
});
