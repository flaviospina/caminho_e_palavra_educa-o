<?php
// tools/import_bncc.php
// Importa (ou atualiza) a base BNCC de data/bncc/ para a tabela bncc_skills.
// Uso: php tools/import_bncc.php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("Execute pela linha de comando ou pela tela Admin → Base BNCC.\n");
}
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Models\BnccSkill;
use App\Services\BnccImporter;

try {
    $r = (new BnccImporter())->run();
    printf("Base BNCC importada em %.1f s: %d registros (%d novos, %d atualizados).\n", $r['segundos'], $r['total'], $r['inseridos'], $r['atualizados']);
    foreach ($r['por_arquivo'] as $arq => $n) {
        printf("  %-20s %5d\n", $arq, $n);
    }
    foreach (BnccSkill::countsByStage() as $c) {
        printf("  %-18s %-16s %5d\n", $c['stage'], $c['source'], $c['total']);
    }
    printf("Total na tabela: %d\n", BnccSkill::count());
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'ERRO: ' . $e->getMessage() . "\n");
    exit(1);
}
