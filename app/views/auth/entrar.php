<?php
// app/views/auth/entrar.php — formulário de login.
?>
<section class="caixa-form">
  <h1>Entrar</h1>
  <form method="post" action="<?= e(url('/entrar')) ?>" class="form">
    <?= \App\Csrf::field() ?>
    <label>E-mail <input type="email" name="email" required autocomplete="username" autofocus></label>
    <label>Senha <input type="password" name="senha" required autocomplete="current-password"></label>
    <button type="submit" class="botao">Entrar</button>
    <p class="nota"><a href="<?= e(url('/senha/esqueci')) ?>">Esqueci minha senha</a></p>
  </form>
</section>
