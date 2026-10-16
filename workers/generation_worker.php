<?php
// workers/generation_worker.php
// Worker da fila de geração de imagens.
//   Lote (Cron Job do cPanel, a cada minuto):  php workers/generation_worker.php --lote
//   Contínuo (systemd no cecape-server):        php workers/generation_worker.php --continuo
// Opções: --max-segundos=50 (lote) · --intervalo=5 (espera em s quando a fila está vazia, contínuo)
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("Execute pela linha de comando (cron ou systemd).\n");
}
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Models\GenerationJob;
use App\Services\GenerationWorker;

$modo = 'lote';
$maxSegundos = (int) env('WORKER_MAX_SECONDS', '50');
$intervalo = 5;
foreach (array_slice($argv, 1) as $a) {
    if ($a === '--continuo') {
        $modo = 'continuo';
    } elseif ($a === '--lote') {
        $modo = 'lote';
    } elseif (str_starts_with($a, '--max-segundos=')) {
        $maxSegundos = max(5, (int) substr($a, 15));
    } elseif (str_starts_with($a, '--intervalo=')) {
        $intervalo = max(1, (int) substr($a, 12));
    } elseif ($a === '--ajuda' || $a === '-h') {
        echo "Uso: php workers/generation_worker.php [--lote|--continuo] [--max-segundos=N] [--intervalo=N]\n";
        exit(0);
    }
}

// Evita dois lotes simultâneos do cron (um worker contínuo pode conviver com o cron: a reivindicação é atômica).
$lockFile = STORAGE_PATH . '/cache/worker-' . $modo . '.lock';
@mkdir(dirname($lockFile), 0750, true);
$lock = fopen($lockFile, 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    echo "Outro worker já está em execução neste modo. Saindo.\n";
    exit(0);
}

$worker = new GenerationWorker(null, null, null, false, null, (int) env('WORKER_STALE_MINUTES', '10'));
$log = fn(string $m) => printf("[%s] %s\n", date('Y-m-d H:i:s'), $m);
$log("Worker {$worker->workerId()} iniciado em modo {$modo} (" . (AI_MOCK ? 'simulado' : 'real') . ').');

$parar = false;
if (function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    foreach ([SIGTERM, SIGINT] as $sig) {
        pcntl_signal($sig, function () use (&$parar, $log): void {
            $parar = true;
            $log('Sinal recebido: encerrando após o job atual.');
        });
    }
}

if ($modo === 'lote') {
    $r = $worker->runBatch($maxSegundos);
    $fila = GenerationJob::queueCounts();
    $log(sprintf('Lote concluído: %d job(s) processado(s), %d recuperado(s), %.1f s. Fila: %s.', $r['processados'], $r['recuperados'], $r['segundos'], json_encode($fila)));
    exit(0);
}

while (!$parar) {
    $r = $worker->runBatch(3600, 1000);
    if ($r['processados'] > 0 || $r['recuperados'] > 0) {
        $log(sprintf('%d job(s) processado(s), %d recuperado(s).', $r['processados'], $r['recuperados']));
    }
    for ($i = 0; $i < $intervalo && !$parar; $i++) {
        sleep(1);
    }
}
$log('Worker encerrado.');
exit(0);
