<?php
// app/views/auth/esqueci.php — pedido de redefinição de senha.
?>
<section class="caixa-form">
  <h1>Esqueci minha senha</h1>
  <p>Informe o e-mail cadastrado. Enviaremos um link para criar uma nova senha, válido por 60 minutos.</p>
  <form method="post" action="<?= e(url('/senha/esqueci')) ?>" class="form">
    <?= \App\Csrf::field() ?>
    <label>E-mail <input type="email" name="email" required autocomplete="username" autofocus></label>
    <button type="submit" class="botao">Enviar link</button>
    <p class="nota"><a href="<?= e(url('/entrar')) ?>">Voltar para o login</a></p>
  </form>
</section>
