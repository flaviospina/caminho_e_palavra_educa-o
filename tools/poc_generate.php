<?php
// tools/poc_generate.php
// Prova de conceito pela linha de comando: gera UMA página de um JSON de
// exemplo em cada motor configurado e grava em storage/poc/<execucao>/.
//
// Uso: php tools/poc_generate.php data/exemplos/ef04_ciencias_misturas.json [--pagina=1] [--motores=gemini,openai,mock] [--todos]
//   --todos  gera a página 1 de TODOS os exemplos de data/exemplos (kit de teste cego)
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("Execute pela linha de comando.\n");
}
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Ai\AiException;
use App\Ai\EngineFactory;
use App\Models\UsageLog;
use App\Services\PocService;

$args = array_slice($argv, 1);
$file = null;
$pagina = 1;
$motores = null;
$todos = false;
foreach ($args as $a) {
    if (str_starts_with($a, '--pagina=')) {
        $pagina = max(1, (int) substr($a, 9));
    } elseif (str_starts_with($a, '--motores=')) {
        $motores = array_filter(array_map('trim', explode(',', substr($a, 10))));
    } elseif ($a === '--todos') {
        $todos = true;
    } elseif ($a === '--ajuda' || $a === '-h') {
        echo "Uso: php tools/poc_generate.php <arquivo.json> [--pagina=N] [--motores=gemini,openai,mock] [--todos]\n";
        exit(0);
    } else {
        $file = $a;
    }
}

$factory = new EngineFactory();
echo "Modo: " . ($factory->mock() ? 'SIMULADO (AI_MOCK=1, sem custo)' : 'REAL (consome créditos)') . "\n";
echo 'Motores: ' . implode(', ', $motores ?? $factory->enabledNames()) . "\n";
if (!UsageLog::tableExists()) {
    echo "AVISO: a tabela usage_log não existe — importe sql/schema.sql. A geração continua, mas o custo não será registrado.\n";
}

$arquivos = $todos ? array_values(PocService::examples()) : [$file];
if ($arquivos === [null]) {
    fwrite(STDERR, "Informe o arquivo JSON de exemplo ou use --todos.\n");
    exit(2);
}

$service = new PocService($factory);
$falhas = 0;
foreach ($arquivos as $arquivo) {
    echo "\n▶ " . basename((string) $arquivo) . " — página {$pagina}\n";
    try {
        $doc = PocService::loadDoc((string) $arquivo);
        $run = $service->run($doc, $pagina, $motores);
        echo "  Execução: {$run['run_id']}  (pasta: {$run['dir']})\n";
        foreach ($run['resultados'] as $r) {
            if ($r['ok']) {
                printf("  OK     %-7s %-32s %dx%d  %.1f s  US$ %.4f  tentativas: %d\n", $r['motor'], $r['modelo'], $r['largura'], $r['altura'], $r['tempo_ms'] / 1000, $r['custo_usd'], $r['tentativas']);
                echo "         " . $r['caminho'] . "\n";
            } else {
                $falhas++;
                printf("  ERRO   %-7s %-32s %s\n", $r['motor'], $r['modelo'], $r['mensagem']);
            }
        }
    } catch (AiException $e) {
        $falhas++;
        echo "  ERRO   " . $e->getMessage() . "\n";
    }
}
echo "\n" . ($falhas === 0 ? 'Concluído sem falhas.' : "Concluído com {$falhas} falha(s). Detalhes em storage/logs/app.log.") . "\n";
exit($falhas === 0 ? 0 : 1);
