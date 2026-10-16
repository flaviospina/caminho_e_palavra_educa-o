<?php
// app/views/admin/escolas.php — lista de escolas.
/** @var array $escolas */
?>
<section>
  <div class="cabecalho-pagina"><h1>Escolas</h1><a class="botao" href="<?= e(url('/admin/escolas/nova')) ?>">Nova escola</a></div>
  <div class="tabela-rolagem">
  <table class="tabela">
    <thead><tr><th>Nome</th><th>Nome curto</th><th>INEP</th><th>Usuários</th><th>Ativa</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($escolas as $s): ?>
      <tr>
        <td><?= e($s['name']) ?></td><td><?= e($s['short_name']) ?></td><td><?= e($s['inep_code']) ?></td>
        <td><?= e($s['users_count']) ?></td><td><?= $s['active'] ? 'sim' : 'não' ?></td>
        <td><a href="<?= e(url('/admin/escolas/' . $s['id'] . '/editar')) ?>">editar</a></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$escolas): ?><tr><td colspan="6">Nenhuma escola cadastrada.</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
</section>
