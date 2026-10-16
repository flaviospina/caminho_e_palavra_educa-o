<?php
// tests/cases/fase0_seguranca.php — .env e app/ fora do alcance do navegador
declare(strict_types=1);

Teste::caso('segurança: nenhum .env, .sql, .md ou .log dentro de public/', function () {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(ROOT_PATH . '/public', FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        /** @var SplFileInfo $f */
        $nome = $f->getFilename();
        Teste::falso((bool) preg_match('/^\.env|\.(sql|md|log|env)$/i', $nome), 'Arquivo sensível em public/: ' . $f->getPathname());
    }
});

Teste::caso('segurança: public/storage é link para storage/public e não contém PHP', function () {
    $link = ROOT_PATH . '/public/storage';
    Teste::verdadeiro(is_link($link), 'public/storage deve ser um link simbólico');
    Teste::igual(realpath(ROOT_PATH . '/storage/public'), realpath($link), 'Destino do link');
    foreach (glob(ROOT_PATH . '/storage/public/*.php') ?: [] as $php) {
        throw new FalhaDeTeste('PHP em pasta pública gerada: ' . $php);
    }
});

Teste::caso('segurança: .gitignore ignora .env e arquivos gerados', function () {
    $gi = file_get_contents(ROOT_PATH . '/.gitignore');
    Teste::contem("\n.env\n", "\n" . $gi);
    Teste::contem("\n.env.test\n", "\n" . $gi);
    Teste::contem("\nstorage/*\n", "\n" . $gi);
    Teste::naoContem("\nvendor/\n", "\n" . $gi, 'vendor/ deve ser versionado');
    Teste::naoContem("\nvendor\n", "\n" . $gi, 'vendor/ deve ser versionado');
    Teste::contem("\nvendor/mpdf/mpdf/ttfonts/\n", "\n" . $gi, 'Fontes do mPDF ficam fora do git');
});

Teste::caso('segurança: nginx.conf de exemplo publica só public/ e nega arquivos sensíveis', function () {
    $conf = file_get_contents(ROOT_PATH . '/deploy/nginx.conf.example');
    Teste::verdadeiro((bool) preg_match('#^\s*root\s+\S+/public;#m', $conf), 'root deve terminar em /public');
    Teste::verdadeiro((bool) preg_match('#location\s+~\s+/\\\\\.[^{]*\{[^}]*deny\s+all#s', $conf), 'Deve negar arquivos iniciados por ponto (.env, .git)');
    Teste::verdadeiro((bool) preg_match('#location\s+~\*\s+\\\\\.\(env\|sql\|md\|log\|lock\|json\)\$[^{]*\{[^}]*deny\s+all#s', $conf), 'Deve negar .env/.sql/.md/.log/.lock/.json');
    Teste::verdadeiro((bool) preg_match('#location\s+~\*\s+\^/storage/\.\*\\\\\.php\$[^{]*\{[^}]*deny\s+all#s', $conf), 'Deve negar PHP em /storage/');
    Teste::contem('try_files $uri $uri/ /index.php?$args;', $conf);
    Teste::naoContem('listen 80', $conf, 'Só porta 443 (regra do DTI)');
});

Teste::caso('segurança: cookies de sessão HttpOnly e SameSite=Lax', function () {
    Teste::igual('1', ini_get('session.cookie_httponly'));
    Teste::igual('Lax', ini_get('session.cookie_samesite'));
    Teste::igual('1', ini_get('session.use_strict_mode'));
});

Teste::caso('segurança: .htaccess da raiz (HostGator) encaminha para public/ e nega pastas privadas', function () {
    $ht = file_get_contents(ROOT_PATH . '/.htaccess');
    Teste::contem('RewriteEngine On', $ht);
    Teste::contem('Options -Indexes', $ht);
    Teste::verdadeiro((bool) preg_match('#RewriteRule\s+\(\^\|/\)\\\\\.\(env\|git[^)]*\)\s+-\s+\[R=404#', $ht), 'Nega .env e .git');
    Teste::verdadeiro((bool) preg_match('#RewriteRule\s+\^\(app\|vendor\|tests\|sql\|data\|deploy\|docs\|templates\|workers\|tools\)\(/\|\$\)\s+-\s+\[R=404#', $ht), 'Nega pastas privadas');
    Teste::verdadeiro((bool) preg_match('#RewriteRule\s+\^storage\(/\(\?!public/\)\.\*\)\?\$\s+-\s+\[R=404#', $ht), 'Nega storage/ exceto storage/public');
    Teste::verdadeiro((bool) preg_match('#RewriteRule\s+\^storage/public/\.\+\\\\\.\(php\|phtml\|phar\)\$\s+-\s+\[R=404#', $ht), 'Nega PHP em storage/public');
    Teste::contem('public/$1 [L]', $ht, 'Encaminha o resto para public/');
});

Teste::caso('segurança: .htaccess de public/ tem controlador frontal; pastas privadas têm deny', function () {
    $ht = file_get_contents(ROOT_PATH . '/public/.htaccess');
    Teste::contem('RewriteCond %{REQUEST_FILENAME} !-f', $ht);
    Teste::contem('RewriteRule ^ index.php [L]', $ht);
    Teste::contem('/public(/[^\\s?]*)?', $ht, 'Redireciona /public/ para a raiz da subpasta');
    Teste::contem('[R=301,L,QSA,NE]', $ht);
    $st = (string) file_get_contents(ROOT_PATH . '/storage/.htaccess');
    Teste::contem('RewriteRule ^$ - [R=404,L]', $st, 'storage/ raiz → 404');
    Teste::contem('RewriteRule ^(?!public/)(.+)$ public/$1 [L]', $st, 'storage/x → storage/public/x');
    Teste::contem('RewriteRule ^public/.+\.(php|phtml|phar)$ - [R=404,L]', $st);
    foreach (['app', 'vendor', 'templates', 'tests', 'deploy', 'docs'] as $pasta) {
        $arq = ROOT_PATH . '/' . $pasta . '/.htaccess';
        Teste::verdadeiro(is_file($arq), "Falta {$pasta}/.htaccess");
        Teste::contem('Require all denied', (string) file_get_contents($arq), "{$pasta}/.htaccess sem deny");
    }
    $pub = (string) file_get_contents(ROOT_PATH . '/storage/public/.htaccess');
    Teste::contem('Require all granted', $pub);
    Teste::contem('FilesMatch "\.(php|phtml|phar)$"', $pub);
});
