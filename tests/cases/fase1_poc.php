<?php
// tests/cases/fase1_poc.php — serviço da PoC, usage_log, ferramenta CLI e página /poc
declare(strict_types=1);

use App\Ai\EngineFactory;
use App\Database;
use App\Models\UsageLog;
use App\Services\PocService;

function poc_teste(): PocService
{
    $dir = sys_get_temp_dir() . '/poc-teste-' . bin2hex(random_bytes(3));
    return new PocService(new EngineFactory(['AI_MOCK' => '1', 'IMAGE_ENGINES' => 'gemini,openai']), $dir);
}

Teste::caso('schema: usage_log existe com utf8mb4_unicode_ci (schema aplicado pelo bootstrap)', function () {
    $pdo = Database::get();
    Teste::verdadeiro(UsageLog::tableExists($pdo));
    $charset = $pdo->query("SELECT TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usage_log'")->fetchColumn();
    Teste::igual('utf8mb4_unicode_ci', $charset);
});

Teste::caso('poc: gera a página nos dois motores simulados, grava PNG, prompt e meta.json', function () {
    $svc = poc_teste();
    $doc = PocService::loadDoc(ROOT_PATH . '/data/exemplos/ef07_matematica_proporcionalidade.json');
    $run = $svc->run($doc, 1, null, 'teste-' . bin2hex(random_bytes(2)));
    Teste::igual(2, count($run['resultados']));
    foreach ($run['resultados'] as $r) {
        Teste::verdadeiro($r['ok'], $r['mensagem'] ?? '');
        Teste::verdadeiro(is_file($r['caminho']), 'PNG não gravado');
        $info = getimagesize($r['caminho']);
        Teste::igual('image/png', $info['mime']);
        Teste::igual([1024, 1536], [$info[0], $info[1]]);
    }
    Teste::verdadeiro(is_file($run['dir'] . '/meta.json'));
    Teste::verdadeiro(is_file($run['dir'] . '/prompt.txt'));
    $meta = json_decode((string) file_get_contents($run['dir'] . '/meta.json'), true);
    Teste::igual('Proporcionalidade', $meta['titulo']);
    Teste::verdadeiro($meta['simulado']);
    Teste::igual(1, count($svc->runs()));
    Teste::verdadeiro($svc->imagePath($run['run_id'], 'gemini.png') !== null);
    Teste::igual(null, $svc->imagePath('../../etc', 'passwd.png'), 'Caminho inseguro recusado');
    // Registro em usage_log
    $rows = UsageLog::recent(5, $run['run_id']);
    Teste::igual(2, count($rows));
    Teste::igual(['ok', 'ok'], array_column($rows, 'status'));
    Teste::igual('1024x1536', $rows[0]['resolution']);
    Teste::verdadeiro((float) $rows[0]['cost_usd'] === 0.0);
});

Teste::caso('poc: erro de um motor não derruba a execução e fica registrado como erro', function () {
    $svc = new PocService(new EngineFactory(['AI_MOCK' => '0', 'IMAGE_ENGINES' => 'gemini', 'AI_RETRIES' => '0']), sys_get_temp_dir() . '/poc-erro-' . bin2hex(random_bytes(3)));
    $doc = PocService::loadDoc(ROOT_PATH . '/data/exemplos/ef04_ciencias_misturas.json');
    $_ENV['GEMINI_API_KEY'] = '';
    $run = $svc->run($doc, 1, ['gemini'], 'erro-' . bin2hex(random_bytes(2)));
    unset($_ENV['GEMINI_API_KEY']);
    Teste::igual(1, count($run['resultados']));
    Teste::falso($run['resultados'][0]['ok']);
    Teste::igual('chave_invalida', $run['resultados'][0]['tipo_erro']);
    Teste::contem('não está configurada', $run['resultados'][0]['mensagem']);
    $rows = UsageLog::recent(5, $run['run_id']);
    Teste::igual('erro', $rows[0]['status']);
    Teste::contem('não está configurada', (string) $rows[0]['error_message']);
});

Teste::caso('poc: JSON inválido ou sem campos obrigatórios é rejeitado', function () {
    $f = sys_get_temp_dir() . '/poc-invalido.json';
    file_put_contents($f, '{"tipo":"apostila"}');
    $e = Teste::lanca(fn() => PocService::loadDoc($f), App\Ai\AiException::class);
    Teste::contem('campo obrigatório "etapa"', $e->getMessage());
    file_put_contents($f, '{não é json');
    Teste::lanca(fn() => PocService::loadDoc($f), App\Ai\AiException::class);
    unlink($f);
});

Teste::caso('cli: tools/poc_generate.php gera PNG em modo simulado e sai com código 0', function () {
    $env = ROOT_PATH . '/.env';
    $criou = false;
    if (!is_file($env) && is_file(ROOT_PATH . '/.env.test')) {
        copy(ROOT_PATH . '/.env.test', $env);
        $criou = true;
    }
    try {
        $cmd = 'php ' . escapeshellarg(ROOT_PATH . '/tools/poc_generate.php') . ' ' . escapeshellarg(ROOT_PATH . '/data/exemplos/ef04_portugues_ideia_central.json') . ' --pagina=3 --motores=mock 2>&1';
        exec($cmd, $out, $code);
        $txt = implode("\n", $out);
        Teste::igual(0, $code, $txt);
        Teste::contem('SIMULADO', $txt);
        Teste::contem('OK     mock', $txt);
        Teste::contem('1024x1536', $txt);
        Teste::verdadeiro((bool) preg_match('#(storage/poc/[\w\-]+/mock\.png)#', $txt, $m), 'Caminho do PNG não impresso');
        Teste::verdadeiro(is_file(ROOT_PATH . '/' . $m[1]) || is_file($m[1]));
    } finally {
        if ($criou) {
            unlink($env);
        }
    }
});

Teste::caso('/poc: anônimo é redirecionado ao login; professor recebe 403; admin acessa', function () {
    teste_logar(null);
    $r = app_router()->dispatch('GET', '/poc');
    Teste::igual(302, $r->status);
    Teste::contem('/entrar', $r->headers['Location'] ?? '');
    $r = app_router()->dispatch('GET', '/poc/imagem/abc123/x.png');
    Teste::igual(302, $r->status, 'Imagem sem sessão redireciona');

    $prof = teste_criar_usuario('professor');
    teste_logar($prof);
    $r = app_router()->dispatch('GET', '/poc');
    Teste::igual(403, $r->status, 'Professor não acessa a PoC');

    teste_preparar_admin();
    teste_logar(1);   // admin inicial (seeds)
    teste_aceitar_termo(1);
    $r = app_router()->dispatch('GET', '/poc');
    Teste::igual(200, $r->status);
    Teste::contem('Gerar nesta página', $r->body);
    $r = app_router()->dispatch('POST', '/poc/gerar');
    Teste::igual(302, $r->status, 'POST sem CSRF redireciona sem gerar');
    teste_logar(null);
});
