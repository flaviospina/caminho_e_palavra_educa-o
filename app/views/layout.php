<?php
// app/views/layout.php — moldura comum de todas as páginas (mobile-first).
/** @var string $content */
$titulo = $titulo ?? APP_NAME;
$usuarioLogado = \App\Auth::user();
$flashes = \App\Request::pullFlash();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($titulo) ?> · <?= e(APP_NAME) ?></title>
<link rel="stylesheet" href="<?= e(url('/assets/css/app.css')) ?>">
<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.14.9/dist/cdn.min.js"></script>
<script defer src="<?= e(url('/assets/js/app.js')) ?>"></script>
</head>
<body>
<header class="topo" x-data="{ aberto: false }">
  <a class="marca" href="<?= e(url('/')) ?>">
    <span class="marca-icone" aria-hidden="true">📘</span>
    <span><?= e(APP_NAME) ?></span>
  </a>
  <button class="menu-botao" type="button" @click="aberto = !aberto" aria-label="Abrir menu">☰</button>
  <nav class="menu" :class="{ 'menu-aberto': aberto }">
    <?php if ($usuarioLogado): ?>
      <a href="<?= e(url('/painel')) ?>">Painel</a>
      <?php if (\App\Auth::isAdmin()): ?>
        <a href="<?= e(url('/admin/escolas')) ?>">Escolas</a>
        <a href="<?= e(url('/admin/usuarios')) ?>">Usuários</a>
        <a href="<?= e(url('/admin/bncc')) ?>">Base BNCC</a>
        <a href="<?= e(url('/poc')) ?>">PoC motores</a>
      <?php endif; ?>
      <span class="menu-usuario"><?= e($usuarioLogado['name']) ?> · <?= e($usuarioLogado['role_name']) ?></span>
      <form method="post" action="<?= e(url('/sair')) ?>" class="menu-form"><?= \App\Csrf::field() ?><button type="submit" class="botao botao-leve">Sair</button></form>
    <?php else: ?>
      <a href="<?= e(url('/entrar')) ?>" class="botao botao-claro">Entrar</a>
    <?php endif; ?>
  </nav>
</header>
<main class="conteudo">
<?php foreach ($flashes as $f): ?>
  <p class="flash flash-<?= e($f['tipo']) ?>"><?= e($f['mensagem']) ?></p>
<?php endforeach; ?>
<?= $content ?>
</main>
<footer class="rodape">
  <p>CECAPE · Secretaria de Educação de São Caetano do Sul · versão <?= e(APP_VERSION) ?></p>
</footer>
</body>
</html>
