<?php
// app/views/auth/trocar.php — troca de senha (obrigatória no primeiro acesso).
/** @var bool $obrigatorio */
?>
<section class="caixa-form">
  <h1>Trocar senha</h1>
  <?php if ($obrigatorio): ?><p class="aviso">Sua senha é provisória. Crie uma senha nova para continuar.</p><?php endif; ?>
  <form method="post" action="<?= e(url('/senha/trocar')) ?>" class="form">
    <?= \App\Csrf::field() ?>
    <label>Senha atual <input type="password" name="senha_atual" required autocomplete="current-password"></label>
    <label>Nova senha (mínimo 8 caracteres, com letras e números) <input type="password" name="senha" required minlength="8" autocomplete="new-password"></label>
    <label>Repita a nova senha <input type="password" name="senha2" required minlength="8" autocomplete="new-password"></label>
    <button type="submit" class="botao">Salvar</button>
  </form>
</section>
