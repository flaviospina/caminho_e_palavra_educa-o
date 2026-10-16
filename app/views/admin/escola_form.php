<?php
// app/views/admin/escola_form.php — cadastro/edição de escola.
/** @var array $escola @var string $acao */
?>
<section class="caixa-form">
  <h1><?= e($titulo) ?></h1>
  <form method="post" action="<?= e($acao) ?>" class="form" enctype="multipart/form-data">
    <?= \App\Csrf::field() ?>
    <label>Nome da escola <input type="text" name="name" required maxlength="150" value="<?= e($escola['name'] ?? '') ?>"></label>
    <label>Nome curto (capas e listas) <input type="text" name="short_name" maxlength="60" value="<?= e($escola['short_name'] ?? '') ?>"></label>
    <label>Código INEP (8 dígitos, opcional) <input type="text" name="inep_code" maxlength="8" inputmode="numeric" value="<?= e($escola['inep_code'] ?? '') ?>"></label>
    <label>Município <input type="text" name="city" maxlength="80" value="<?= e($escola['city'] ?? 'São Caetano do Sul') ?>"></label>
    <h2>Identidade visual (capas e cartazes)</h2>
    <label>Logotipo (PNG, JPG ou WebP, até 2 MB)
      <input type="file" name="logo" accept="image/png,image/jpeg,image/webp">
    </label>
    <?php if (!empty($escola['logo_path'])): ?>
      <p><img src="<?= e(url('/escolas/' . $escola['id'] . '/logo')) ?>" alt="Logotipo atual" class="logo-previa"> <label class="check"><input type="checkbox" name="remover_logo" value="1"> Remover logotipo</label></p>
    <?php endif; ?>
    <label>Cor principal <input type="color" name="primary_color" value="<?= e($escola['primary_color'] ?? '#1E3A8A') ?>"></label>
    <label>Frase curta da escola (opcional, até 120 caracteres) <input type="text" name="tagline" maxlength="120" value="<?= e($escola['tagline'] ?? '') ?>"></label>
    <label class="check"><input type="checkbox" name="active" value="1" <?= !empty($escola['active']) ? 'checked' : '' ?>> Escola ativa</label>
    <button type="submit" class="botao">Salvar</button>
    <p class="nota"><a href="<?= e(url('/admin/escolas')) ?>">Voltar</a></p>
  </form>
</section>
