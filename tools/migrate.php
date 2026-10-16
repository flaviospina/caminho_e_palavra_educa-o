<?php
// tools/migrate.php
// Aplica o schema.sql, o seeds.sql e as atualizações de estrutura pendentes.
// Uso: php tools/migrate.php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("Execute pela linha de comando ou por Admin → Banco de dados.\n");
}
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Database;
use App\Services\SchemaUpdater;

try {
    $n = Database::applySqlFile(ROOT_PATH . '/sql/schema.sql');
    echo "schema.sql aplicado ({$n} comandos).\n";
    $n = Database::applySqlFile(ROOT_PATH . '/sql/seeds.sql');
    echo "seeds.sql aplicado ({$n} comandos).\n";
    $done = (new SchemaUpdater())->apply();
    echo $done === [] ? "Estrutura já atualizada.\n" : 'Colunas acrescentadas: ' . implode(', ', $done) . "\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'ERRO: ' . $e->getMessage() . "\n");
    exit(1);
}
