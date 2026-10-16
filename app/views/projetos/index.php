<?php
// app/views/projetos/index.php — Meus projetos com filtros por componente, ano, habilidade e status.
/** @var array $lista @var array $filtros @var array $opcoes @var array $status */
?>
<section>
  <div class="cabecalho-pagina"><h1>Meus projetos</h1><a class="botao" href="<?= e(url('/projetos/novo')) ?>">Criar material</a></div>
  <form method="get" action="<?= e(url('/projetos')) ?>" class="form-filtros">
    <input type="search" name="q" placeholder="Título ou tema" value="<?= e($filtros['q']) ?>">
    <select name="componente"><option value="">Componente</option><?php foreach ($opcoes['componentes'] as $c): ?><option value="<?= e($c) ?>" <?= $filtros['componente'] === $c ? 'selected' : '' ?>><?= e($c) ?></option><?php endforeach; ?></select>
    <select name="ano"><option value="">Ano</option><?php foreach ($opcoes['anos'] as $a): ?><option value="<?= e($a) ?>" <?= $filtros['ano'] === $a ? 'selected' : '' ?>><?= e($a) ?></option><?php endforeach; ?></select>
    <input type="text" name="habilidade" placeholder="Código BNCC" value="<?= e($filtros['habilidade']) ?>" size="10">
    <select name="status"><option value="">Situação</option><?php foreach ($status as $k => $v): ?><option value="<?= e($k) ?>" <?= $filtros['status'] === $k ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select>
    <button type="submit" class="botao botao-leve">Filtrar</button>
  </form>
  <p class="nota"><?= e($lista['total']) ?> projeto(s)</p>
  <div class="tabela-rolagem"><table class="tabela">
    <thead><tr><th>Título</th><th>Componente</th><th>Ano</th><th>Habilidades</th><th>Situação</th><th>Autor</th><th>Atualizado</th></tr></thead>
    <tbody>
    <?php foreach ($lista['itens'] as $p): ?>
      <tr>
        <td><a href="<?= e(url('/projetos/' . $p['id'])) ?>"><?= e($p['title']) ?></a></td>
        <td><?= e($p['component']) ?></td><td><?= e($p['year']) ?></td><td><?= e(str_replace(',', ', ', $p['skill_codes'])) ?></td>
        <td><?= e($status[$p['status']] ?? $p['status']) ?></td><td><?= e($p['user_name']) ?></td>
        <td><?= e(date('d/m/Y H:i', strtotime((string) ($p['updated_at'] ?? $p['created_at'])))) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$lista['itens']): ?><tr><td colspan="7">Nenhum projeto ainda. <a href="<?= e(url('/projetos/novo')) ?>">Crie o primeiro material.</a></td></tr><?php endif; ?>
    </tbody>
  </table></div>
  <?php if ($lista['paginas'] > 1): ?>
    <p class="paginacao"><?php for ($i = 1; $i <= $lista['paginas']; $i++): ?><a href="<?= e(url('/projetos?' . http_build_query(array_merge($filtros, ['pagina' => $i])))) ?>" class="<?= $i === $lista['pagina'] ? 'atual' : '' ?>"><?= $i ?></a><?php endfor; ?></p>
  <?php endif; ?>
</section>
