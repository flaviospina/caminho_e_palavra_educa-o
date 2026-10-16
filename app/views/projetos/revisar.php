<?php
// app/views/projetos/revisar.php — passo 4: editor de revisão por bloco, com contador e limite.
/** @var array $projeto @var array $doc @var array $limites @var string[] $erros */
$passo_atual = 4;
include ROOT_PATH . '/app/views/projetos/_passos.php';
$campo = function (string $name, string $valor, int $max, string $rotulo, bool $area = false, int $rows = 2): string {
    $attrs = 'name="' . e($name) . '" data-max="' . $max . '" maxlength="' . ($max + 200) . '"';
    $html = '<label class="campo-revisao"><span class="rotulo">' . e($rotulo) . ' <span class="contador" aria-live="polite"></span></span>';
    $html .= $area ? '<textarea ' . $attrs . ' rows="' . $rows . '">' . e($valor) . '</textarea>' : '<input type="text" ' . $attrs . ' value="' . e($valor) . '">';
    return $html . '</label>';
};
?>
<section class="revisao">
  <h1>4. Revisão do conteúdo</h1>
  <p class="nota">Edite o que quiser. O contador mostra o limite de cada campo para o estilo <strong><?= e($projeto['style_slug']) ?></strong>; textos acima do limite não cabem na página e são recusados ao salvar. A versão gerada pela IA fica guardada; você pode voltar a ela a qualquer momento.</p>
  <?php if ($erros): ?>
    <div class="flash flash-erro"><strong>Ajuste os campos abaixo:</strong><ul><?php foreach ($erros as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div>
  <?php endif; ?>
  <form method="post" action="<?= e(url('/projetos/' . $projeto['id'] . '/revisar')) ?>" class="form form-revisao">
    <?= \App\Csrf::field() ?>
    <h2>Identificação</h2>
    <?= $campo('tema', (string) $doc['tema'], 120, 'Tema') ?>
    <h2>Capa</h2>
    <?= $campo('capa[texto_superior]', (string) ($doc['capa']['texto_superior'] ?? ''), 60, 'Texto superior') ?>
    <?= $campo('capa[titulo]', (string) ($doc['capa']['titulo'] ?? ''), $limites['titulo'], 'Título') ?>
    <?= $campo('capa[subtitulo]', (string) ($doc['capa']['subtitulo'] ?? ''), $limites['subtitulo'], 'Subtítulo') ?>
    <?= $campo('capa[frase_inferior]', (string) ($doc['capa']['frase_inferior'] ?? ''), 90, 'Frase inferior') ?>
    <h2>Habilidades BNCC</h2>
    <?php foreach ($doc['habilidades'] as $i => $h): ?>
      <?= $campo("habilidades[{$i}][resumo]", (string) ($h['resumo'] ?? ''), $limites['resumo'], $h['codigo'] . ' — resumo', true, 2) ?>
    <?php endforeach; ?>
    <?php foreach ($doc['paginas'] as $i => $p): ?>
      <details class="pagina-revisao" open>
        <summary>Página <?= e($p['numero']) ?> — <?= e($p['tipo']) ?>: <?= e($p['titulo']) ?></summary>
        <?= $campo("paginas[{$i}][titulo]", (string) $p['titulo'], $limites['titulo'], 'Título da página') ?>
        <?= $campo("paginas[{$i}][subtitulo]", (string) ($p['subtitulo'] ?? ''), $limites['subtitulo'], 'Subtítulo') ?>
        <?php foreach ($p['blocos'] as $j => $b): ?>
          <div class="bloco-revisao">
            <h3>Bloco <?= e($b['n'] ?? $j + 1) ?></h3>
            <?= $campo("paginas[{$i}][blocos][{$j}][titulo]", (string) $b['titulo'], $limites['bloco_titulo'], 'Título do bloco') ?>
            <?php if (array_key_exists('texto', $b) || empty($b['itens'])): ?>
              <?= $campo("paginas[{$i}][blocos][{$j}][texto]", (string) ($b['texto'] ?? ''), $limites['bloco_texto'], 'Texto', true, 3) ?>
            <?php endif; ?>
            <?php if (!empty($b['itens']) || !array_key_exists('texto', $b)): ?>
              <label class="campo-revisao"><span class="rotulo">Itens (um por linha, até <?= $limites['itens_max'] ?> itens de <?= $limites['item'] ?> caracteres) <span class="contador" aria-live="polite"></span></span>
                <textarea name="paginas[<?= $i ?>][blocos][<?= $j ?>][itens]" rows="<?= max(2, count($b['itens'] ?? []) + 1) ?>" data-max-itens="<?= $limites['itens_max'] ?>" data-max-item="<?= $limites['item'] ?>"><?= e(implode("\n", $b['itens'] ?? [])) ?></textarea>
              </label>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
        <?= $campo("paginas[{$i}][rodape]", (string) ($p['rodape'] ?? ''), $limites['rodape'], 'Rodapé') ?>
      </details>
    <?php endforeach; ?>
    <div class="acoes-passo">
      <a class="botao botao-leve" href="<?= e(url('/projetos/' . $projeto['id'])) ?>">Cancelar</a>
      <button type="submit" class="botao">Salvar revisão e continuar</button>
    </div>
  </form>
  <?php if ($projeto['reviewed_json']): ?>
    <form method="post" action="<?= e(url('/projetos/' . $projeto['id'] . '/reverter')) ?>" class="form-inline" onsubmit="return confirm('Descartar a sua revisão e voltar ao texto gerado pela IA?')">
      <?= \App\Csrf::field() ?><button type="submit" class="link">Voltar à versão gerada pela IA (revisada em <?= e(date('d/m/Y H:i', strtotime((string) $projeto['reviewed_at']))) ?>)</button>
    </form>
  <?php endif; ?>
</section>
