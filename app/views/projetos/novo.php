<?php
// app/views/projetos/novo.php — assistente: passos 1 (conteúdo), 2 (finalidade e páginas) e 3 (estilo).
/** @var array $etapas @var array $ei_grupos @var array $estilos @var array $finalidades @var int $max_paginas @var int $max_habilidades @var bool $simulado @var array $valores */
$cfg = [
    'apiComponentes' => url('/api/bncc/componentes'),
    'apiHabilidades' => url('/api/bncc/habilidades'),
    'anos' => [
        'EI' => array_map(fn($k, $v) => ['valor' => $k, 'rotulo' => $v], array_keys($ei_grupos), $ei_grupos),
        'EF_ANOS_INICIAIS' => array_map(fn($a) => ['valor' => (string) $a, 'rotulo' => $a . 'º ano'], range(1, 5)),
        'EF_ANOS_FINAIS' => array_map(fn($a) => ['valor' => (string) $a, 'rotulo' => $a . 'º ano'], range(6, 9)),
        'EM' => array_map(fn($a) => ['valor' => (string) $a, 'rotulo' => $a . 'ª série'], range(1, 3)),
    ],
    'maxHabilidades' => $max_habilidades,
    'valores' => $valores,
];
?>
<section class="assistente" x-data="assistente(<?= e(json_encode($cfg, JSON_UNESCAPED_UNICODE)) ?>)" x-init="iniciar()">
  <?php $passo_atual = 1; ?>
  <ol class="passos" aria-label="Etapas do assistente">
    <template x-for="(nome, i) in ['Conteúdo', 'Finalidade', 'Estilo', 'Revisão', 'Concluir']" :key="i">
      <li :class="{ feito: passo > i + 1, atual: passo === i + 1 }"><span class="passo-n" x-text="i + 1"></span> <span class="passo-nome" x-text="nome"></span></li>
    </template>
  </ol>
  <h1>Novo material</h1>
  <?php if ($simulado): ?><p class="aviso">Modo simulado: o conteúdo será gerado pelo próprio sistema, sem gastar créditos de IA.</p><?php endif; ?>

  <form method="post" action="<?= e(url('/projetos')) ?>" class="form" @submit="enviando = true">
    <?= \App\Csrf::field() ?>

    <div x-show="passo === 1">
      <h2>1. Conteúdo</h2>
      <label>Etapa
        <select name="etapa" x-model="etapa" @change="mudouEtapa()" required>
          <option value="">— escolha —</option>
          <?php foreach ($etapas as $k => $v): ?><option value="<?= e($k) ?>"><?= e($v) ?></option><?php endforeach; ?>
        </select>
      </label>
      <label>Ano
        <select name="ano" x-model="ano" @change="mudouAno()" :disabled="!etapa" required>
          <option value="">— escolha —</option>
          <template x-for="a in anosDisponiveis" :key="a.valor"><option :value="a.valor" x-text="a.rotulo" :selected="a.valor === ano"></option></template>
        </select>
      </label>
      <label>Componente curricular
        <select name="componente" x-model="componente" @change="mudouComponente()" :disabled="!ano || carregandoComponentes" required>
          <option value="">— escolha —</option>
          <template x-for="c in componentes" :key="c"><option :value="c" x-text="c" :selected="c === componente"></option></template>
        </select>
        <small class="nota" x-show="carregandoComponentes">Carregando…</small>
      </label>
      <fieldset :disabled="!componente">
        <legend>Habilidades BNCC (até <?= $max_habilidades ?>) <small class="nota" x-text="selecionadas.length + ' escolhida(s)'"></small></legend>
        <input type="search" x-model.debounce.400ms="busca" @input="buscarHabilidades()" placeholder="Filtrar por código, palavra ou objeto de conhecimento" aria-label="Filtrar habilidades">
        <p class="nota" x-show="carregandoHabilidades">Carregando habilidades…</p>
        <p class="nota" x-show="!carregandoHabilidades && mensagemHabilidades" x-text="mensagemHabilidades"></p>
        <ul class="lista-habilidades">
          <template x-for="h in habilidades" :key="h.codigo">
            <li>
              <label class="check check-topo">
                <input type="checkbox" name="habilidades[]" :value="h.codigo" :checked="selecionadas.includes(h.codigo)" @change="alternar(h.codigo, $event.target.checked)" :disabled="!selecionadas.includes(h.codigo) && selecionadas.length >= <?= $max_habilidades ?>">
                <span><strong x-text="h.codigo"></strong> <small x-text="h.unidade ? '· ' + h.unidade : ''"></small><br><span x-text="h.texto"></span></span>
              </label>
            </li>
          </template>
        </ul>
      </fieldset>
      <label>Tema da apostila <input type="text" name="tema" x-model="tema" maxlength="120" required placeholder="Ex.: Misturas no dia a dia"></label>
      <label>Observações para a IA (opcional) <textarea name="observacoes" x-model="observacoes" maxlength="500" rows="2" placeholder="Ex.: usar exemplos da cozinha; turma com alunos com dislexia"></textarea></label>
      <p class="erro" x-show="erroPasso" x-text="erroPasso"></p>
      <div class="acoes-passo"><button type="button" class="botao" @click="avancar()">Continuar</button></div>
    </div>

    <div x-show="passo === 2" x-cloak>
      <h2>2. Finalidade e número de páginas</h2>
      <fieldset>
        <legend>Finalidade</legend>
        <?php foreach ($finalidades as $k => $v): ?>
          <label class="check"><input type="radio" name="finalidade" value="<?= e($k) ?>" x-model="finalidade"> <?= e(ucfirst($k === 'introducao' ? 'introdução' : ($k === 'revisao' ? 'revisão' : $k))) ?> <small class="nota">— <?= e($v) ?></small></label>
        <?php endforeach; ?>
      </fieldset>
      <label>Páginas (além da capa)
        <select name="paginas" x-model="paginas">
          <?php for ($i = 1; $i <= $max_paginas; $i++): ?><option value="<?= $i ?>"><?= $i ?> página<?= $i > 1 ? 's' : '' ?><?= $i === 3 ? ' (recomendado)' : '' ?></option><?php endfor; ?>
        </select>
      </label>
      <p class="nota">A página 1 é sempre a abertura (objetivo, habilidades, roteiro). As seguintes explicam o tema e a última traz a prática.</p>
      <p class="erro" x-show="erroPasso" x-text="erroPasso"></p>
      <div class="acoes-passo"><button type="button" class="botao botao-leve" @click="voltar()">Voltar</button><button type="button" class="botao" @click="avancar()">Continuar</button></div>
    </div>

    <div x-show="passo === 3" x-cloak>
      <h2>3. Estilo visual</h2>
      <div class="estilos">
        <?php foreach ($estilos as $es): ?>
          <label class="estilo-cartao" :class="{ escolhido: estilo === '<?= e($es['chave']) ?>' }">
            <input type="radio" name="estilo" value="<?= e($es['chave']) ?>" x-model="estilo">
            <span class="estilo-amostra" style="background: <?= e($es['cores']['fundo']) ?>; border-color: <?= e($es['cores']['destaque']) ?>; color: <?= e($es['cores']['texto']) ?>"><span style="background: <?= e($es['cores']['escuro']) ?>"></span><?= e(mb_substr($es['nome'], 0, 1)) ?></span>
            <strong><?= e($es['nome']) ?></strong>
            <small><?= e($es['publico']) ?></small>
          </label>
        <?php endforeach; ?>
      </div>
      <p class="erro" x-show="erroPasso" x-text="erroPasso"></p>
      <div class="acoes-passo">
        <button type="button" class="botao botao-leve" @click="voltar()">Voltar</button>
        <button type="submit" class="botao" :disabled="enviando || !estilo" x-text="enviando ? 'Gerando o conteúdo… aguarde até 2 minutos' : 'Gerar conteúdo com a IA'">Gerar conteúdo com a IA</button>
      </div>
    </div>
  </form>
</section>
