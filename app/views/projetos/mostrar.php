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
  <?php $ehCartaz = ($projeto['type'] ?? '') === 'cartaz'; ?>
  <p class="nota"><?= $ehCartaz ? 'Cartaz · ' . e($doc['evento']['tipo_rotulo'] ?? '') : e($projeto['component']) . ' · ' . e(\App\Models\BnccSkill::yearLabel((string) $projeto['stage'], (string) $projeto['year'])) . ' · ' . e(\App\Models\BnccSkill::STAGES[$projeto['stage']] ?? $projeto['stage']) ?> · estilo <?= e($estilo['nome'] ?? $projeto['style_slug']) ?><br>
  Criado por <?= e($projeto['user_name']) ?><?= $projeto['school_name'] ? ' (' . e($projeto['school_name']) . ')' : '' ?> em <?= e(date('d/m/Y H:i', strtotime((string) $projeto['created_at']))) ?>.
  Versão gerada pela IA guardada<?= $projeto['reviewed_at'] ? '; revisada pelo professor em ' . e(date('d/m/Y H:i', strtotime((string) $projeto['reviewed_at']))) : '; ainda não revisada' ?>.</p>

  <div class="cartoes">
    <?php if ($ehCartaz): ?>
      <article class="cartao"><h2>Dados do evento</h2><ul class="lista-compacta">
        <li><strong><?= e($doc['evento']['titulo'] ?? '') ?></strong></li><li><?= e($doc['evento']['data_hora'] ?? '') ?> · <?= e($doc['evento']['local'] ?? '') ?></li>
        <?php if (!empty($doc['evento']['chamada'])): ?><li><?= e($doc['evento']['chamada']) ?></li><?php endif; ?>
        <?php foreach ($doc['evento']['informacoes'] ?? [] as $i): ?><li><?= e($i) ?></li><?php endforeach; ?>
        <li>Formatos: <?= e(implode(', ', $doc['formatos'] ?? [])) ?></li></ul></article>
    <?php else: ?>
      <article class="cartao"><h2>Habilidades BNCC</h2><ul class="lista-compacta"><?php foreach ($doc['habilidades'] as $h): ?><li><strong><?= e($h['codigo']) ?></strong> — <?= e($h['resumo']) ?></li><?php endforeach; ?></ul></article>
    <?php endif; ?>
    <article class="cartao"><h2>Próximas ações</h2>
      <p>
        <?php if ($pode_editar && !$ehCartaz): ?><a class="botao botao-leve" href="<?= e(url('/projetos/' . $projeto['id'] . '/revisar')) ?>"><?= $projeto['status'] === 'rascunho' ? 'Revisar e aprovar o conteúdo' : 'Revisar conteúdo' ?></a><?php endif; ?>
        <?php if (!$ehCartaz): ?>
        <a class="botao botao-leve" href="<?= e(url('/projetos/' . $projeto['id'] . '/plano')) ?>">Plano de aula</a>
        <a class="botao botao-leve" href="<?= e(url('/projetos/' . $projeto['id'] . '/plano.pdf')) ?>">PDF do plano</a>
        <?php endif; ?>
      </p>
      <form method="post" action="<?= e(url('/projetos/' . $projeto['id'] . '/duplicar')) ?>" class="form-inline"><?= \App\Csrf::field() ?><button type="submit" class="link">Duplicar este projeto</button></form>
      <?php if ($pode_editar && $projeto['status'] === 'rascunho'): ?>
        <p class="nota">Para gerar as imagens, primeiro abra "Revisar e aprovar o conteúdo" e salve (mesmo sem alterar nada).</p>
      <?php elseif ($pode_editar && in_array($projeto['status'], ['revisado', 'pronto', 'erro'], true)): ?>
        <form method="post" action="<?= e(url('/projetos/' . $projeto['id'] . '/gerar')) ?>" class="form-inline" onsubmit="return confirm('<?= $projeto['status'] === 'revisado' ? 'Gerar as imagens de todas as páginas? Cada página consome créditos de IA.' : 'Gerar TODAS as páginas de novo? As imagens atuais serão substituídas e cada página consome créditos.' ?>')">
          <?= \App\Csrf::field() ?><button type="submit" class="botao"><?= $projeto['status'] === 'revisado' ? 'Gerar imagens das páginas' : 'Gerar todas as páginas de novo' ?></button>
        </form>
        <p class="nota"><?= AI_MOCK ? 'Modo simulado: as páginas serão desenhadas pelo próprio sistema, sem custo.' : 'A geração acontece em segundo plano (fila); cada página leva de 1 a 3 minutos.' ?></p>
      <?php endif; ?>
    </article>
  </div>

  <?php $statusInicial = \App\Services\GenerationService::status($projeto); ?>
  <?php if ($paginas && ($projeto['status'] !== 'revisado' || array_filter($paginas, fn($p) => $p['status'] !== 'pendente'))): ?>
  <section class="galeria" x-data="geracaoProjeto(<?= e(json_encode(['url' => url('/projetos/' . $projeto['id'] . '/status'), 'inicial' => $statusInicial], JSON_UNESCAPED_UNICODE)) ?>)" x-init="iniciar()">
    <h2>Páginas geradas</h2>
    <p class="progresso" :class="{ ok: estado.concluido && estado.status === 'pronto', erro: estado.status === 'erro' }">
      <template x-if="!estado.concluido"><span>⏳ Gerando… <span x-text="estado.prontas"></span> de <span x-text="estado.total"></span> página(s) pronta(s). Esta tela atualiza sozinha a cada 5 segundos.</span></template>
      <template x-if="estado.concluido && estado.status === 'pronto'"><span>✅ Pronto! Todas as páginas foram geradas<span x-show="precisaRevisar"> (algumas pedem conferência manual)</span>.</span></template>
      <template x-if="estado.concluido && estado.status === 'erro'"><span>⚠️ A geração terminou com erro em alguma página. Veja abaixo e use "Gerar de novo".</span></template>
    </p>
    <div class="galeria-grade">
      <template x-for="p in estado.paginas" :key="p.numero">
        <figure class="galeria-item" :class="'estado-' + p.status">
          <template x-if="p.imagem"><a :href="p.imagem" target="_blank"><img :src="p.imagem" :alt="'Página ' + p.numero" loading="lazy"></a></template>
          <template x-if="!p.imagem"><div class="galeria-vazio" x-text="p.rotulo"></div></template>
          <figcaption>
            <strong x-text="p.formato ? 'Cartaz ' + p.formato : (p.numero === 0 ? 'Capa' : 'Página ' + p.numero)"></strong> · <span x-text="p.rotulo"></span>
            <template x-if="p.imagem"><a :href="p.imagem.replace('?v=', '?baixar=1&v=')" class="baixar">⬇ baixar PNG</a></template>
            <span x-show="p.tentativas > 1" x-text="' · ' + p.tentativas + ' gerações'"></span>
            <template x-if="p.problemas && p.problemas.length"><ul class="problemas"><template x-for="pr in p.problemas"><li x-text="pr"></li></template></ul></template>
            <?php if ($pode_editar): ?>
            <form method="post" :action="'<?= e(url('/projetos/' . $projeto['id'] . '/paginas/')) ?>' + p.numero + '/regenerar'" x-show="estado.concluido || (p.status !== 'gerando' && p.status !== 'conferindo' && p.status !== 'pendente')" onsubmit="return confirm('Gerar esta página de novo? Consome créditos de IA.')">
              <?= \App\Csrf::field() ?><button type="submit" class="link">Gerar esta página de novo</button>
            </form>
            <?php endif; ?>
          </figcaption>
        </figure>
      </template>
    </div>
  </section>
  <?php endif; ?>

  <?php if (!$ehCartaz && array_filter($paginas, fn($p) => in_array($p['status'], ['pronto', 'revisar'], true) && \App\Models\Page::imageFile($p) !== null)): ?>
  <?php $prontas = array_values(array_map(fn($p) => ['numero' => (int) $p['number'], 'rotulo' => (int) $p['number'] === 0 ? 'Capa' : 'Página ' . $p['number'], 'imagem' => url('/projetos/' . $projeto['id'] . '/pagina/' . $p['number'] . '.png?v=' . (int) $p['attempts'])], array_filter($paginas, fn($p) => in_array($p['status'], ['pronto', 'revisar'], true) && \App\Models\Page::imageFile($p) !== null))); ?>
  <section class="montagem" x-data="montagemPdf(<?= e(json_encode(['paginas' => $prontas, 'titulo' => $projeto['title'], 'projeto' => (int) $projeto['id'], 'rodape' => mb_substr((string) $projeto['title'], 0, 60) . ' · projeto ' . (int) $projeto['id']], JSON_UNESCAPED_UNICODE)) ?>)">
    <h2>Montar o PDF da apostila</h2>
    <p class="nota">A capa fica sempre em primeiro. Arraste as demais páginas (ou use as setas) para definir a ordem e clique em "Baixar PDF". O arquivo é montado no seu navegador, com páginas A4.</p>
    <ol class="ordem-paginas">
      <template x-for="(p, i) in ordem" :key="p.numero">
        <li :draggable="p.numero !== 0" @dragstart="arrastar(i)" @dragover.prevent @drop="soltar(i)" :class="{ fixa: p.numero === 0 }">
          <img :src="p.imagem" :alt="p.rotulo"><span x-text="(i + 1) + 'º · ' + p.rotulo"></span>
          <span class="setas" x-show="p.numero !== 0"><button type="button" @click="mover(i, -1)" :disabled="i <= 1" aria-label="Subir">▲</button><button type="button" @click="mover(i, 1)" :disabled="i >= ordem.length - 1" aria-label="Descer">▼</button></span>
        </li>
      </template>
    </ol>
    <p class="acoes-passo">
      <button type="button" class="botao" @click="baixar()" :disabled="montando" x-text="montando ? 'Montando PDF…' : 'Baixar PDF'">Baixar PDF</button>
      <span class="nota" x-text="mensagem"></span>
    </p>
    <form method="post" action="<?= e(url('/projetos/' . $projeto['id'] . '/apostila.pdf')) ?>" x-ref="fallback" class="form-inline"><?= \App\Csrf::field() ?><input type="hidden" name="ordem" :value="ordem.map(p => p.numero).join(',')"><button type="submit" class="link">Alternativa: montar o PDF no servidor com esta ordem</button></form>
  </section>
  <?php endif; ?>

  <?php if (!$ehCartaz): ?>
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
  <?php endif; ?>

  <?php if ($pode_editar): ?>
    <form method="post" action="<?= e(url('/projetos/' . $projeto['id'] . '/excluir')) ?>" class="form-inline" onsubmit="return confirm('Excluir este projeto?')">
      <?= \App\Csrf::field() ?><button type="submit" class="link link-perigo">Excluir projeto</button>
    </form>
  <?php endif; ?>
</section>
