<?php
// app/views/projetos/_passos.php — barra dos 5 passos do assistente.
/** @var int $passo_atual */
$passos = [1 => 'Conteúdo', 2 => 'Finalidade', 3 => 'Estilo', 4 => 'Revisão', 5 => 'Concluir'];
?>
<ol class="passos" aria-label="Etapas do assistente">
  <?php foreach ($passos as $n => $nome): ?>
    <li class="<?= $n < $passo_atual ? 'feito' : ($n === $passo_atual ? 'atual' : '') ?>"><span class="passo-n"><?= $n ?></span> <span class="passo-nome"><?= e($nome) ?></span></li>
  <?php endforeach; ?>
</ol>
