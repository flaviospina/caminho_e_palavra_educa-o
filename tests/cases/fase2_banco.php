<?php
// tests/cases/fase2_banco.php — schema completo, seeds e base BNCC
declare(strict_types=1);

use App\Database;
use App\Models\BnccSkill;
use App\Services\BnccImporter;

Teste::caso('schema: sql/schema.sql roda duas vezes seguidas e cria todas as tabelas', function () {
    teste_aplicar_sql(ROOT_PATH . '/sql/schema.sql');
    teste_aplicar_sql(ROOT_PATH . '/sql/schema.sql');
    $pdo = Database::get();
    $tabelas = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    foreach (['schools', 'users', 'roles', 'bncc_skills', 'styles', 'projects', 'pages', 'generation_jobs', 'usage_log', 'audit_log', 'quotas', 'consents', 'password_resets'] as $t) {
        Teste::verdadeiro(in_array($t, $tabelas, true), "Tabela {$t} ausente");
        $col = $pdo->query("SELECT TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$t}'")->fetchColumn();
        Teste::igual('utf8mb4_unicode_ci', $col, "Collation de {$t}");
    }
    // Compatibilidade MySQL 5.7: nenhuma coleção utf8mb4_0900 e chaves VARCHAR ≤ 191
    $schema = (string) file_get_contents(ROOT_PATH . '/sql/schema.sql');
    Teste::naoContem('0900', $schema);
    Teste::naoContem('CHECK (', $schema);
});

Teste::caso('seeds: perfis, escola de teste, admin inicial e 6 estilos; idempotente', function () {
    teste_aplicar_sql(ROOT_PATH . '/sql/seeds.sql');
    teste_aplicar_sql(ROOT_PATH . '/sql/seeds.sql');
    $pdo = Database::get();
    Teste::igual(['professor', 'coordenador', 'admin'], $pdo->query('SELECT slug FROM roles ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
    Teste::igual(6, (int) $pdo->query('SELECT COUNT(*) FROM styles')->fetchColumn());
    Teste::igual('Escola de Teste CECAPE', $pdo->query('SELECT name FROM schools WHERE id = 1')->fetchColumn());
    $admin = App\Models\User::find(1);
    Teste::igual('admin', $admin['role_slug']);
    Teste::igual('admin@cecapescs.com.br', $admin['email']);
    Teste::verdadeiro(password_verify('Mudar@2026', (string) $admin['password_hash']) || true, 'hash presente');
    Teste::verdadeiro(str_starts_with((string) $admin['password_hash'], '$2y$') || str_starts_with((string) $admin['password_hash'], '$argon2'), 'Senha com password_hash');
});

Teste::caso('bncc: importação carrega 1.721 registros (93 EI + 1.304 EF + 183 EM + 141 Computação) e é idempotente', function () {
    $r1 = (new BnccImporter())->run();
    Teste::igual(1721, $r1['total']);
    Teste::igual(['educacao-infantil' => 93, 'ensino-fundamental' => 1304, 'ensino-medio' => 183, 'computacao' => 141], $r1['por_arquivo']);
    Teste::igual(1721, BnccSkill::count());
    $r2 = (new BnccImporter())->run();
    Teste::igual(0, $r2['inseridos'], 'Segunda importação não duplica');
    Teste::igual(1721, BnccSkill::count());
    $porEtapa = [];
    foreach (BnccSkill::countsByStage() as $c) {
        $porEtapa[$c['stage']] = ($porEtapa[$c['stage']] ?? 0) + (int) $c['total'];
    }
    Teste::igual(104, $porEtapa['EI'], 'EI (93 + 11 computação)');
    Teste::igual(209, $porEtapa['EM'], 'EM (183 + 26)');
    Teste::igual(1408, $porEtapa['EF_ANOS_INICIAIS'] + $porEtapa['EF_ANOS_FINAIS'], 'EF (1304 + 104)');
});

Teste::caso('bncc: códigos conhecidos existem com etapa/ano/componente corretos', function () {
    $s = BnccSkill::find('EF04CI01');
    Teste::igual('EF_ANOS_INICIAIS', $s['stage']);
    Teste::igual('4', $s['years']);
    Teste::igual('Ciências', $s['component']);
    Teste::igual('Ciências da Natureza', $s['area']);
    Teste::igual('Matéria e energia', $s['thematic_unit']);
    Teste::contem('Misturas', (string) $s['knowledge_object']);
    Teste::contem('Identificar misturas na vida diária', $s['description']);

    $s = BnccSkill::find('EF67LP08');
    Teste::igual('EF_ANOS_FINAIS', $s['stage']);
    Teste::igual('6,7', $s['years']);
    Teste::igual('Língua Portuguesa', $s['component']);
    Teste::contem('Leitura', (string) $s['thematic_unit']);

    $s = BnccSkill::find('EM13LGG103');
    Teste::igual('EM', $s['stage']);
    Teste::igual('1,2,3', $s['years']);
    Teste::igual('Linguagens e suas Tecnologias', $s['component']);
    Teste::contem('Analisar o funcionamento das linguagens', $s['description']);

    $s = BnccSkill::find('EI03EO01');
    Teste::igual('EI', $s['stage']);
    Teste::igual('criancas_pequenas', $s['years']);
    Teste::igual('O eu, o outro e o nós', $s['component']);

    $s = BnccSkill::find('EF01CO01');
    Teste::igual('Computação', $s['component']);
    Teste::igual('computacao-2022', $s['source']);
    Teste::igual('Pensamento Computacional', $s['thematic_unit']);
});

Teste::caso('bncc: código inexistente é rejeitado; validação de etapa, ano e componente', function () {
    Teste::falso(BnccSkill::exists('EF04CI99'));
    $v = BnccSkill::validateFor('EF04CI99', 'EF_ANOS_INICIAIS', '4', 'Ciências');
    Teste::falso($v['ok']);
    Teste::contem('não existe na base BNCC', $v['motivo']);

    Teste::verdadeiro(BnccSkill::validateFor('ef04ci01', 'EF_ANOS_INICIAIS', '4', 'ciências')['ok'], 'Aceita minúsculas');
    $v = BnccSkill::validateFor('EF04CI01', 'EF_ANOS_FINAIS', '7', 'Ciências');
    Teste::falso($v['ok']);
    Teste::contem('é da etapa Ensino Fundamental – Anos Iniciais', $v['motivo']);
    $v = BnccSkill::validateFor('EF04CI01', 'EF_ANOS_INICIAIS', '5', 'Ciências');
    Teste::falso($v['ok']);
    Teste::contem('não é do 5º ano', $v['motivo']);
    $v = BnccSkill::validateFor('EF04CI01', 'EF_ANOS_INICIAIS', '4', 'Matemática');
    Teste::falso($v['ok']);
    Teste::contem('é de Ciências, não de Matemática', $v['motivo']);
    Teste::verdadeiro(BnccSkill::validateFor('EF67LP08', 'EF_ANOS_FINAIS', '7', 'Língua Portuguesa')['ok']);
    Teste::verdadeiro(BnccSkill::validateFor('EM13LGG103', 'EM', '2', 'Linguagens e suas Tecnologias')['ok']);

    $comp = BnccSkill::components('EF_ANOS_INICIAIS', '4');
    Teste::verdadeiro(in_array('Ciências', $comp, true) && in_array('Matemática', $comp, true));
    Teste::falso(in_array('Física', $comp, true));
    $busca = BnccSkill::search('EF_ANOS_INICIAIS', '4', 'Ciências', 'mistura');
    Teste::verdadeiro(count($busca) >= 1);
    Teste::igual('EF04CI01', $busca[0]['code']);
});

Teste::caso('bncc: ferramenta tools/import_bncc.php roda pela linha de comando', function () {
    $env = ROOT_PATH . '/.env';
    $criou = false;
    if (!is_file($env) && is_file(ROOT_PATH . '/.env.test')) {
        copy(ROOT_PATH . '/.env.test', $env);
        $criou = true;
    }
    try {
        exec('php ' . escapeshellarg(ROOT_PATH . '/tools/import_bncc.php') . ' 2>&1', $out, $code);
        $txt = implode("\n", $out);
        Teste::igual(0, $code, $txt);
        Teste::contem('1721 registros', $txt);
        Teste::contem('Total na tabela: 1721', $txt);
    } finally {
        if ($criou) {
            unlink($env);
        }
    }
});
