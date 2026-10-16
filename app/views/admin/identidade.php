<?php
// app/views/admin/identidade.php — logotipo da rede e atualização da estrutura do banco.
/** @var string $rede @var bool $tem_logo @var array $pendentes */
?>
<section>
  <h1>Identidade visual da rede</h1>
  <div class="cartoes">
    <article class="cartao">
      <h2>Secretaria / rede</h2>
      <p>Nome usado nas capas: <strong><?= e($rede) ?></strong> <small class="nota">(variável <code>NETWORK_NAME</code> do .env)</small></p>
      <?php if ($tem_logo): ?><p><img src="<?= e(url('/identidade/logo')) ?>?v=<?= time() ?>" alt="Logotipo da rede" class="logo-previa"></p><?php else: ?><p class="nota">Nenhum logotipo da rede enviado. As capas mostram só o nome.</p><?php endif; ?>
      <form method="post" action="<?= e(url('/admin/identidade')) ?>" class="form" enctype="multipart/form-data">
        <?= \App\Csrf::field() ?>
        <label>Logotipo da rede (PNG, JPG ou WebP, até 2 MB; fundo transparente fica melhor) <input type="file" name="logo" accept="image/png,image/jpeg,image/webp"></label>
        <?php if ($tem_logo): ?><label class="check"><input type="checkbox" name="remover_logo" value="1"> Remover o logotipo atual</label><?php endif; ?>
        <button type="submit" class="botao">Salvar</button>
      </form>
      <p class="nota">O logotipo de cada escola é enviado em Escolas → editar. Na capa, a faixa inferior recebe o logotipo e o nome da escola à esquerda e a rede à direita.</p>
    </article>
    <article class="cartao">
      <h2>Estrutura do banco</h2>
      <?php if ($pendentes): ?>
        <p class="erro">Há colunas pendentes: <?= e(implode(', ', array_map(fn($p) => $p['tabela'] . '.' . $p['coluna'], $pendentes))) ?>.</p>
      <?php else: ?>
        <p class="ok">Estrutura atualizada.</p>
      <?php endif; ?>
      <form method="post" action="<?= e(url('/admin/banco/atualizar')) ?>"><?= \App\Csrf::field() ?><button type="submit" class="botao botao-leve">Aplicar atualizações pendentes</button></form>
      <p class="nota">Após cada atualização do sistema: importe <code>sql/schema.sql</code> e <code>sql/seeds.sql</code> no phpMyAdmin e clique aqui (o MySQL 5.7 do HostGator não aceita "adicionar coluna se não existir" no SQL).</p>
    </article>
  </div>
</section>
