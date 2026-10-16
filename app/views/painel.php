<?php
// app/views/painel.php — painel inicial por perfil.
/** @var array $usuario @var ?array $consent @var int $bncc_total */
?>
<section class="painel">
  <h1>Olá, <?= e($usuario['name']) ?></h1>
  <p class="nota"><?= e($usuario['role_name']) ?><?= $usuario['school_name'] ? ' · ' . e($usuario['school_name']) : '' ?><?= $consent ? ' · termo aceito em ' . e(date('d/m/Y H:i', strtotime((string) $consent['accepted_at']))) : '' ?></p>
  <div class="cartoes">
    <article class="cartao">
      <h2>Meus projetos</h2>
      <p>O assistente de criação de apostilas chega na Fase 3. Por enquanto, confira seu cadastro e a base BNCC.</p>
    </article>
    <?php if (\App\Auth::isAdmin()): ?>
      <article class="cartao"><h2>Escolas e usuários</h2><p><a href="<?= e(url('/admin/escolas')) ?>">Cadastrar escolas</a> · <a href="<?= e(url('/admin/usuarios')) ?>">Cadastrar usuários</a></p></article>
      <article class="cartao"><h2>Base BNCC</h2><p><?= e(number_format($bncc_total, 0, ',', '.')) ?> habilidades carregadas. <a href="<?= e(url('/admin/bncc')) ?>">Importar ou consultar</a></p></article>
      <article class="cartao"><h2>Motores de IA</h2><p><a href="<?= e(url('/poc')) ?>">Prova de conceito</a> (comparação Gemini × OpenAI)</p></article>
    <?php elseif (\App\Auth::hasRole('coordenador')): ?>
      <article class="cartao"><h2>Minha escola</h2><p>A aprovação de materiais da escola chega nas próximas fases.</p></article>
    <?php endif; ?>
    <article class="cartao"><h2>Minha conta</h2><p><a href="<?= e(url('/senha/trocar')) ?>">Trocar senha</a> · <a href="<?= e(url('/termo')) ?>">Termo de uso</a></p></article>
  </div>
</section>
