<?php
// app/views/admin/usuario_form.php — cadastro/edição de usuário.
/** @var array $usuario @var string $acao @var array $escolas @var array $perfis */
?>
<section class="caixa-form">
  <h1><?= e($titulo) ?></h1>
  <form method="post" action="<?= e($acao) ?>" class="form" x-data="{ perfil: '<?= e($usuario['role_slug'] ?? 'professor') ?>' }">
    <?= \App\Csrf::field() ?>
    <label>Nome completo <input type="text" name="name" required maxlength="120" value="<?= e($usuario['name'] ?? '') ?>"></label>
    <label>E-mail <input type="email" name="email" required maxlength="191" value="<?= e($usuario['email'] ?? '') ?>"></label>
    <label>Perfil
      <select name="role" x-model="perfil">
        <?php foreach ($perfis as $p): ?><option value="<?= e($p['slug']) ?>"><?= e($p['name']) ?></option><?php endforeach; ?>
      </select>
    </label>
    <label x-show="perfil !== 'admin'">Escola
      <select name="school_id">
        <option value="">— escolha —</option>
        <?php foreach ($escolas as $s): ?><option value="<?= e($s['id']) ?>" <?= (string) ($usuario['school_id'] ?? '') === (string) $s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?>
      </select>
    </label>
    <label class="check"><input type="checkbox" name="active" value="1" <?= !empty($usuario['active']) ? 'checked' : '' ?>> Conta ativa</label>
    <?php if (empty($usuario['id'])): ?><p class="nota">Uma senha provisória será gerada e mostrada após o cadastro; o usuário troca no primeiro acesso.</p><?php endif; ?>
    <button type="submit" class="botao">Salvar</button>
    <p class="nota"><a href="<?= e(url('/admin/usuarios')) ?>">Voltar</a></p>
  </form>
</section>
