<?php
// app/views/admin/usuarios.php — lista de usuários com filtros e paginação.
/** @var array $lista @var array $filtros @var array $escolas @var array $perfis */
?>
<section>
  <div class="cabecalho-pagina"><h1>Usuários</h1><a class="botao" href="<?= e(url('/admin/usuarios/novo')) ?>">Novo usuário</a></div>
  <form method="get" action="<?= e(url('/admin/usuarios')) ?>" class="form-filtros">
    <input type="search" name="q" placeholder="Nome ou e-mail" value="<?= e($filtros['q']) ?>">
    <select name="perfil"><option value="">Todos os perfis</option><?php foreach ($perfis as $p): ?><option value="<?= e($p['slug']) ?>" <?= $filtros['role'] === $p['slug'] ? 'selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?></select>
    <select name="escola"><option value="">Todas as escolas</option><?php foreach ($escolas as $s): ?><option value="<?= e($s['id']) ?>" <?= (string) $filtros['school_id'] === (string) $s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?></select>
    <select name="ativo"><option value="">Ativos e inativos</option><option value="1" <?= $filtros['active'] === '1' ? 'selected' : '' ?>>Só ativos</option><option value="0" <?= $filtros['active'] === '0' ? 'selected' : '' ?>>Só inativos</option></select>
    <button type="submit" class="botao botao-leve">Filtrar</button>
  </form>
  <p class="nota"><?= e($lista['total']) ?> usuário(s) · página <?= e($lista['pagina']) ?> de <?= e($lista['paginas']) ?></p>
  <div class="tabela-rolagem">
  <table class="tabela">
    <thead><tr><th>Nome</th><th>E-mail</th><th>Perfil</th><th>Escola</th><th>Ativo</th><th>Último acesso</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($lista['itens'] as $u): ?>
      <tr>
        <td><?= e($u['name']) ?><?= $u['must_change_pass'] ? ' <small>(senha provisória)</small>' : '' ?><?= $u['locked_until'] && strtotime($u['locked_until']) > time() ? ' <small class="erro">(bloqueado)</small>' : '' ?></td>
        <td><?= e($u['email']) ?></td><td><?= e($u['role_name']) ?></td><td><?= e($u['school_name']) ?></td>
        <td><?= $u['active'] ? 'sim' : 'não' ?></td><td><?= $u['last_login_at'] ? e(date('d/m/Y H:i', strtotime($u['last_login_at']))) : '—' ?></td>
        <td class="acoes">
          <a href="<?= e(url('/admin/usuarios/' . $u['id'] . '/editar')) ?>">editar</a>
          <form method="post" action="<?= e(url('/admin/usuarios/' . $u['id'] . '/senha')) ?>" onsubmit="return confirm('Gerar nova senha provisória para <?= e($u['name']) ?>?')"><?= \App\Csrf::field() ?><button type="submit" class="link">nova senha</button></form>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$lista['itens']): ?><tr><td colspan="7">Nenhum usuário encontrado.</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
  <?php if ($lista['paginas'] > 1): ?>
    <p class="paginacao">
    <?php for ($p = 1; $p <= $lista['paginas']; $p++): ?>
      <?php $qs = http_build_query(['q' => $filtros['q'], 'perfil' => $filtros['role'], 'escola' => $filtros['school_id'], 'ativo' => $filtros['active'], 'pagina' => $p]); ?>
      <a href="<?= e(url('/admin/usuarios?' . $qs)) ?>" class="<?= $p === $lista['pagina'] ? 'atual' : '' ?>"><?= $p ?></a>
    <?php endfor; ?>
    </p>
  <?php endif; ?>
</section>
