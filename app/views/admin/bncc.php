<?php
// app/views/admin/bncc.php — importação e consulta da base BNCC.
/** @var int $total @var array $por_etapa @var array $etapas @var array $componentes @var array $filtro @var ?array $consulta @var array $resultados @var bool $arquivos_ok */
?>
<section>
  <h1>Base BNCC</h1>
  <div class="cartoes">
    <article class="cartao">
      <h2><?= e(number_format($total, 0, ',', '.')) ?> habilidades carregadas</h2>
      <?php if ($por_etapa): ?><ul class="lista-compacta"><?php foreach ($por_etapa as $c): ?><li><?= e($etapas[$c['stage']] ?? $c['stage']) ?> (<?= e($c['source']) ?>): <?= e($c['total']) ?></li><?php endforeach; ?></ul><?php endif; ?>
      <form method="post" action="<?= e(url('/admin/bncc/importar')) ?>">
        <?= \App\Csrf::field() ?>
        <?php if ($arquivos_ok): ?>
          <button type="submit" class="botao"><?= $total > 0 ? 'Atualizar a base a partir de data/bncc' : 'Importar a base BNCC agora' ?></button>
          <p class="nota">Fonte: dataset aberto bncc-dados (bncc.dev, CC BY 4.0). A importação é idempotente e leva poucos segundos.</p>
        <?php else: ?>
          <p class="erro">Arquivos de data/bncc/ não encontrados no servidor.</p>
        <?php endif; ?>
      </form>
    </article>
  </div>

  <h2>Validar um código</h2>
  <form method="get" action="<?= e(url('/admin/bncc')) ?>" class="form-filtros">
    <input type="text" name="codigo" placeholder="Ex.: EF04CI01" value="<?= e($filtro['codigo']) ?>">
    <select name="etapa"><option value="">Etapa (opcional)</option><?php foreach ($etapas as $k => $v): ?><option value="<?= e($k) ?>" <?= $filtro['etapa'] === $k ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select>
    <input type="text" name="ano" placeholder="Ano (ex.: 4)" value="<?= e($filtro['ano']) ?>" size="8">
    <input type="text" name="componente" placeholder="Componente (ex.: Ciências)" value="<?= e($filtro['componente']) ?>">
    <button type="submit" class="botao botao-leve">Validar</button>
  </form>
  <?php if ($consulta !== null): ?>
    <p class="<?= $consulta['ok'] ? 'ok' : 'erro' ?>"><?= $consulta['ok'] ? 'Código válido para o recorte informado.' : e($consulta['motivo']) ?></p>
    <?php if ($consulta['skill']): $s = $consulta['skill']; ?>
      <blockquote class="habilidade"><strong><?= e($s['code']) ?></strong> · <?= e($etapas[$s['stage']] ?? $s['stage']) ?> · anos <?= e($s['years']) ?> · <?= e($s['component']) ?><br><?= e($s['description']) ?><br><small><?= e($s['thematic_unit']) ?><?= $s['knowledge_object'] ? ' · ' . e($s['knowledge_object']) : '' ?></small></blockquote>
    <?php endif; ?>
  <?php endif; ?>

  <h2>Pesquisar por etapa</h2>
  <form method="get" action="<?= e(url('/admin/bncc')) ?>" class="form-filtros">
    <select name="etapa" required><option value="">Etapa</option><?php foreach ($etapas as $k => $v): ?><option value="<?= e($k) ?>" <?= $filtro['etapa'] === $k && $filtro['codigo'] === '' ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select>
    <input type="text" name="ano" placeholder="Ano" value="<?= e($filtro['codigo'] === '' ? $filtro['ano'] : '') ?>" size="8">
    <input type="text" name="componente" placeholder="Componente" value="<?= e($filtro['codigo'] === '' ? $filtro['componente'] : '') ?>" list="componentes">
    <datalist id="componentes"><?php foreach ($componentes as $c): ?><option value="<?= e($c) ?>"><?php endforeach; ?></datalist>
    <input type="search" name="q" placeholder="Palavra-chave" value="<?= e($filtro['busca']) ?>">
    <button type="submit" class="botao botao-leve">Pesquisar</button>
  </form>
  <?php if ($resultados): ?>
    <div class="tabela-rolagem"><table class="tabela">
      <thead><tr><th>Código</th><th>Anos</th><th>Componente</th><th>Unidade / campo</th><th>Habilidade</th></tr></thead>
      <tbody><?php foreach ($resultados as $r): ?><tr><td><?= e($r['code']) ?></td><td><?= e($r['years']) ?></td><td><?= e($r['component']) ?></td><td><?= e($r['thematic_unit']) ?></td><td class="quebra"><?= e($r['description']) ?></td></tr><?php endforeach; ?></tbody>
    </table></div>
  <?php elseif ($filtro['etapa'] !== '' && $filtro['codigo'] === ''): ?>
    <p class="nota">Nenhuma habilidade encontrada para esse recorte.</p>
  <?php endif; ?>
</section>
