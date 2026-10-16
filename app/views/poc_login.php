<?php
// app/views/poc_login.php — pede a chave de acesso da prova de conceito.
/** @var ?string $erro */
?>
<section class="poc-login">
  <h1>Prova de conceito dos motores de IA</h1>
  <p>Área restrita. Informe a chave definida em <code>POC_ACCESS_KEY</code> no arquivo <code>.env</code>.</p>
  <?php if (!empty($erro)): ?><p class="erro"><?= e($erro) ?></p><?php endif; ?>
  <form method="post" action="<?= e(url('/poc/entrar')) ?>" class="form-linha">
    <?= \App\Csrf::field() ?>
    <label for="chave">Chave de acesso</label>
    <input type="password" id="chave" name="chave" required autocomplete="off">
    <button type="submit" class="botao">Entrar</button>
  </form>
</section>
