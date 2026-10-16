<?php
// app/views/admin/escola_form.php — cadastro/edição de escola.
/** @var array $escola @var string $acao */
?>
<section class="caixa-form">
  <h1><?= e($titulo) ?></h1>
  <form method="post" action="<?= e($acao) ?>" class="form">
    <?= \App\Csrf::field() ?>
    <label>Nome da escola <input type="text" name="name" required maxlength="150" value="<?= e($escola['name'] ?? '') ?>"></label>
    <label>Nome curto (capas e listas) <input type="text" name="short_name" maxlength="60" value="<?= e($escola['short_name'] ?? '') ?>"></label>
    <label>Código INEP (8 dígitos, opcional) <input type="text" name="inep_code" maxlength="8" inputmode="numeric" value="<?= e($escola['inep_code'] ?? '') ?>"></label>
    <label>Município <input type="text" name="city" maxlength="80" value="<?= e($escola['city'] ?? 'São Caetano do Sul') ?>"></label>
    <label class="check"><input type="checkbox" name="active" value="1" <?= !empty($escola['active']) ? 'checked' : '' ?>> Escola ativa</label>
    <button type="submit" class="botao">Salvar</button>
    <p class="nota"><a href="<?= e(url('/admin/escolas')) ?>">Voltar</a></p>
  </form>
</section>
