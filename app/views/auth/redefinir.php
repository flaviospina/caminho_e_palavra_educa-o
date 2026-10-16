<?php
// app/views/auth/redefinir.php — nova senha a partir do link do e-mail.
/** @var string $token */
?>
<section class="caixa-form">
  <h1>Redefinir senha</h1>
  <form method="post" action="<?= e(url('/senha/redefinir/' . $token)) ?>" class="form">
    <?= \App\Csrf::field() ?>
    <label>Nova senha (mínimo 8 caracteres, com letras e números) <input type="password" name="senha" required minlength="8" autocomplete="new-password"></label>
    <label>Repita a nova senha <input type="password" name="senha2" required minlength="8" autocomplete="new-password"></label>
    <button type="submit" class="botao">Salvar nova senha</button>
  </form>
</section>
