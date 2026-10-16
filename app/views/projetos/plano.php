<?php
// app/views/projetos/plano.php — plano de aula em texto (mesmo conteúdo do JSON aprovado).
/** @var array $projeto @var array $plano @var string $html */
?>
<section class="plano">
  <p class="acoes-passo"><a class="botao botao-leve" href="<?= e(url('/projetos/' . $projeto['id'])) ?>">Voltar ao projeto</a> <a class="botao" href="<?= e(url('/projetos/' . $projeto['id'] . '/plano.pdf')) ?>">Baixar PDF</a></p>
  <div class="plano-texto"><?= $html ?></div>
</section>
