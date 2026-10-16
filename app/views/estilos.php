<?php
// app/views/estilos.php — biblioteca de estilos por faixa etária com prévia.
/** @var array $por_faixa */
?>
<section class="biblioteca">
  <h1>Biblioteca de estilos</h1>
  <p class="nota">Cada estilo mostra a amostra de referência e, quando existir, a página real mais recente gerada por você (ou pela sua escola) nesse estilo.</p>
  <?php foreach ($por_faixa as $f): ?>
    <h2><?= e($f['rotulo']) ?></h2>
    <div class="estilos estilos-biblioteca">
      <?php foreach ($f['estilos'] as $es): ?>
        <article class="estilo-cartao">
          <div class="previas">
            <figure><img class="estilo-imagem" src="<?= e(asset($es['amostra'])) ?>" alt="Referência do estilo <?= e($es['nome']) ?>" loading="lazy" style="border-color: <?= e($es['cores']['destaque']) ?>"><figcaption>Referência</figcaption></figure>
            <?php if ($es['previa_real']): ?>
              <figure><a href="<?= e(url('/projetos/' . $es['previa_real']['projeto'])) ?>"><img class="estilo-imagem" src="<?= e($es['previa_real']['url']) ?>" alt="Página real no estilo <?= e($es['nome']) ?>" loading="lazy"></a><figcaption>Página gerada</figcaption></figure>
            <?php endif; ?>
          </div>
          <strong><?= e($es['nome']) ?></strong>
          <small><?= e($es['publico']) ?></small>
          <p class="nota"><?= e(mb_substr($es['descricao'], 0, 140)) ?>…</p>
          <a class="botao botao-leve" href="<?= e(url('/projetos/novo')) ?>">Criar material neste estilo</a>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endforeach; ?>
</section>
