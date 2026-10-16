<?php
// app/views/poc.php — comparação lado a lado das imagens geradas por cada motor.
/** @var string[] $exemplos @var string[] $motores @var bool $simulado @var array $modelos @var array $execucoes @var bool $tabela_ok @var array $usos @var ?string $resultado */
?>
<section class="poc">
  <h1>Prova de conceito dos motores</h1>

  <?php if ($simulado): ?>
    <p class="aviso">Modo simulado (<code>AI_MOCK=1</code>): as imagens são desenhadas pelo próprio sistema, sem gastar créditos. Para testar os motores reais, coloque as chaves no <code>.env</code> e <code>AI_MOCK=0</code>.</p>
  <?php else: ?>
    <p class="aviso aviso-forte">Modo real: cada geração consome créditos (Gemini <?= e($modelos['gemini']) ?>; OpenAI <?= e($modelos['openai']) ?>).</p>
  <?php endif; ?>
  <?php if (!$tabela_ok): ?>
    <p class="erro">A tabela <code>usage_log</code> não existe. Importe <code>sql/schema.sql</code> no phpMyAdmin para registrar o custo de cada geração.</p>
  <?php endif; ?>

  <form method="post" action="<?= e(url('/poc/gerar')) ?>" class="poc-form" x-data="{ enviando: false }" @submit="enviando = true">
    <?= \App\Csrf::field() ?>
    <label>Conteúdo de exemplo
      <select name="exemplo" required>
        <?php foreach ($exemplos as $ex): ?><option value="<?= e($ex) ?>"><?= e($ex) ?></option><?php endforeach; ?>
      </select>
    </label>
    <label>Página
      <select name="pagina"><option value="1">1 – abertura</option><option value="2">2 – explicação</option><option value="3">3 – prática</option></select>
    </label>
    <fieldset>
      <legend>Motores</legend>
      <?php foreach (['gemini' => 'Google Gemini', 'openai' => 'OpenAI', 'mock' => 'Simulado'] as $k => $rotulo): ?>
        <label class="check"><input type="checkbox" name="motores[]" value="<?= e($k) ?>" <?= in_array($k, $motores, true) || ($k === 'mock' && $simulado) ? 'checked' : '' ?>> <?= e($rotulo) ?></label>
      <?php endforeach; ?>
    </fieldset>
    <button type="submit" class="botao" :disabled="enviando" x-text="enviando ? 'Gerando… isso pode levar alguns minutos' : 'Gerar nesta página'">Gerar</button>
  </form>

  <?php foreach ($execucoes as $ex): ?>
    <article class="execucao <?= ($resultado ?? '') === $ex['run_id'] ? 'destaque' : '' ?>">
      <h2><?= e($ex['documento']['componente'] ?? '') ?> · <?= e(\App\Ai\PromptBuilder::anoLabel((string) ($ex['documento']['ano'] ?? ''))) ?> · página <?= e($ex['pagina'] ?? '') ?> — <?= e($ex['titulo'] ?? '') ?></h2>
      <p class="nota">Execução <?= e($ex['run_id']) ?> · <?= e($ex['gerado_em'] ?? '') ?> · estilo <?= e($ex['documento']['estilo'] ?? '') ?><?= !empty($ex['simulado']) ? ' · simulado' : '' ?></p>
      <div class="comparacao">
        <?php foreach ($ex['resultados'] as $r): ?>
          <figure>
            <?php if (!empty($r['ok'])): ?>
              <a href="<?= e(url('/poc/imagem/' . $ex['run_id'] . '/' . $r['arquivo'])) ?>" target="_blank"><img src="<?= e(url('/poc/imagem/' . $ex['run_id'] . '/' . $r['arquivo'])) ?>" alt="Página gerada por <?= e($r['motor']) ?>" loading="lazy"></a>
              <figcaption><strong><?= e($r['motor']) ?></strong> · <?= e($r['modelo']) ?><br><?= e($r['largura']) ?>×<?= e($r['altura']) ?> · <?= e(number_format($r['tempo_ms'] / 1000, 1, ',', '.')) ?> s · US$ <?= e(number_format((float) $r['custo_usd'], 3, ',', '.')) ?> · tentativas: <?= e($r['tentativas']) ?></figcaption>
            <?php else: ?>
              <div class="falha"><strong><?= e($r['motor']) ?></strong><br><?= e($r['mensagem']) ?></div>
            <?php endif; ?>
          </figure>
        <?php endforeach; ?>
      </div>
    </article>
  <?php endforeach; ?>

  <?php if ($usos): ?>
    <h2>Últimos registros em usage_log</h2>
    <div class="tabela-rolagem">
    <table class="tabela">
      <thead><tr><th>Quando</th><th>Execução</th><th>Motor</th><th>Modelo</th><th>Resolução</th><th>Tokens</th><th>Tempo</th><th>US$</th><th>Status</th></tr></thead>
      <tbody>
      <?php foreach ($usos as $u): ?>
        <tr>
          <td><?= e($u['created_at']) ?></td><td><?= e($u['reference']) ?></td><td><?= e($u['engine']) ?></td><td><?= e($u['model']) ?></td>
          <td><?= e($u['resolution']) ?></td><td><?= e($u['input_tokens']) ?>/<?= e($u['output_tokens']) ?></td>
          <td><?= e(number_format($u['duration_ms'] / 1000, 1, ',', '.')) ?> s</td><td><?= e(number_format((float) $u['cost_usd'], 4, ',', '.')) ?></td>
          <td class="<?= $u['status'] === 'ok' ? 'ok' : 'erro' ?>"><?= e($u['status']) ?><?= $u['error_message'] ? ': ' . e($u['error_message']) : '' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>
</section>
