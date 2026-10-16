<?php
// app/views/projetos/mostrar.php — passo 5: resumo do projeto e próximas ações.
/** @var array $projeto @var array $doc @var array $gerado @var array $paginas @var bool $pode_editar @var ?array $estilo @var array $status */
$passo_atual = 5;
include ROOT_PATH . '/app/views/projetos/_passos.php';
?>
<section class="projeto">
  <div class="cabecalho-pagina">
    <h1><?= e($projeto['title']) ?></h1>
    <span class="selo selo-<?= e($projeto['status']) ?>"><?= e($status[$projeto['status']] ?? $projeto['status']) ?></span>
  </div>
  <p class="nota"><?= e($projeto['component']) ?> · <?= e(\App\Models\BnccSkill::yearLabel((string) $projeto['stage'], (string) $projeto['year'])) ?> · <?= e(\App\Models\BnccSkill::STAGES[$projeto['stage']] ?? $projeto['stage']) ?> · estilo <?= e($estilo['nome'] ?? $projeto['style_slug']) ?><br>
  Criado por <?= e($projeto['user_name']) ?><?= $projeto['school_name'] ? ' (' . e($projeto['school_name']) . ')' : '' ?> em <?= e(date('d/m/Y H:i', strtotime((string) $projeto['created_at']))) ?>.
  Versão gerada pela IA guardada<?= $projeto['reviewed_at'] ? '; revisada pelo professor em ' . e(date('d/m/Y H:i', strtotime((string) $projeto['reviewed_at']))) : '; ainda não revisada' ?>.</p>

  <div class="cartoes">
    <article class="cartao"><h2>Habilidades BNCC</h2><ul class="lista-compacta"><?php foreach ($doc['habilidades'] as $h): ?><li><strong><?= e($h['codigo']) ?></strong> — <?= e($h['resumo']) ?></li><?php endforeach; ?></ul></article>
    <article class="cartao"><h2>Próximas ações</h2>
      <p>
        <?php if ($pode_editar): ?><a class="botao" href="<?= e(url('/projetos/' . $projeto['id'] . '/revisar')) ?>">Revisar conteúdo</a><?php endif; ?>
        <a class="botao botao-leve" href="<?= e(url('/projetos/' . $projeto['id'] . '/plano')) ?>">Plano de aula</a>
        <a class="botao botao-leve" href="<?= e(url('/projetos/' . $projeto['id'] . '/plano.pdf')) ?>">PDF do plano</a>
      </p>
      <p class="nota">Conteúdo salvo em Meus projetos como <?= e(mb_strtolower($status[$projeto['status']] ?? $projeto['status'])) ?>. A geração das páginas em imagem é o próximo módulo do sistema e usará exatamente este texto.</p>
    </article>
  </div>

  <h2>Capa</h2>
  <div class="pagina-preview capa-preview">
    <small><?= e($doc['capa']['texto_superior'] ?? '') ?></small>
    <h3><?= e($doc['capa']['titulo'] ?? '') ?></h3>
    <p><?= e($doc['capa']['subtitulo'] ?? '') ?></p>
    <small><?= e($doc['capa']['frase_inferior'] ?? '') ?></small>
  </div>
  <?php foreach ($doc['paginas'] as $p): ?>
    <h2>Página <?= e($p['numero']) ?> · <?= e($p['tipo']) ?></h2>
    <div class="pagina-preview">
      <h3><?= e($p['titulo']) ?></h3>
      <?php if (!empty($p['subtitulo'])): ?><p class="subtitulo"><?= e($p['subtitulo']) ?></p><?php endif; ?>
      <div class="blocos-preview">
        <?php foreach ($p['blocos'] as $b): ?>
          <div class="bloco-preview"><strong><?= e($b['n'] ?? '') ?> <?= e($b['titulo']) ?></strong>
            <?php if (!empty($b['texto'])): ?><p><?= e($b['texto']) ?></p><?php endif; ?>
            <?php if (!empty($b['itens'])): ?><ul><?php foreach ($b['itens'] as $it): ?><li><?= e($it) ?></li><?php endforeach; ?></ul><?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
      <?php if (!empty($p['rodape'])): ?><p class="rodape-preview"><?= e($p['rodape']) ?> · Página <?= e($p['numero']) ?> de <?= count($doc['paginas']) ?></p><?php endif; ?>
    </div>
  <?php endforeach; ?>

  <?php if ($pode_editar): ?>
    <form method="post" action="<?= e(url('/projetos/' . $projeto['id'] . '/excluir')) ?>" class="form-inline" onsubmit="return confirm('Excluir este projeto?')">
      <?= \App\Csrf::field() ?><button type="submit" class="link link-perigo">Excluir projeto</button>
    </form>
  <?php endif; ?>
</section>
