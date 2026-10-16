<?php
// app/views/termo.php — termo de uso com aceite registrado.
/** @var string $html @var bool $aceito @var string $versao */
?>
<section class="termo">
  <div class="termo-texto"><?= $html ?></div>
  <?php if ($aceito): ?>
    <p class="ok">Você já aceitou a versão <?= e($versao) ?> deste termo.</p>
    <p><a class="botao" href="<?= e(url('/painel')) ?>">Ir para o painel</a></p>
  <?php else: ?>
    <form method="post" action="<?= e(url('/termo')) ?>" class="form">
      <?= \App\Csrf::field() ?>
      <label class="check"><input type="checkbox" name="aceito" value="1" required> Li e aceito o termo de uso (versão <?= e($versao) ?>).</label>
      <button type="submit" class="botao">Aceitar e continuar</button>
    </form>
  <?php endif; ?>
</section>
