<?php
// app/views/cartazes/novo.php — formulário do cartaz de evento (gera direto pela fila).
/** @var array $tipos @var array $formatos @var array $estilos @var array $etapas @var ?array $escola @var array $valores @var bool $simulado */
$v = fn(string $k, string $d = '') => (string) ($valores[$k] ?? $d);
?>
<section class="assistente" x-data="{ estilo: '<?= e($v('estilo')) ?>', enviando: false }">
  <h1>Novo cartaz de evento</h1>
  <p class="nota">Cartazes não usam habilidades BNCC: você escreve os textos e o sistema gera a arte nos formatos escolhidos<?= $escola ? ', com a identidade visual de ' . e($escola['short_name'] ?: $escola['name']) : '' ?>.</p>
  <?php if ($simulado): ?><p class="aviso">Modo simulado: as artes serão desenhadas pelo próprio sistema, sem custo.</p><?php endif; ?>
  <form method="post" action="<?= e(url('/cartazes')) ?>" class="form" @submit="enviando = true">
    <?= \App\Csrf::field() ?>
    <label>Tipo de evento
      <select name="tipo_evento" required>
        <option value="">— escolha —</option>
        <?php foreach ($tipos as $k => $r): ?><option value="<?= e($k) ?>" <?= $v('tipo_evento') === $k ? 'selected' : '' ?>><?= e($r) ?></option><?php endforeach; ?>
      </select>
    </label>
    <label>Título do cartaz (até 40 caracteres) <input type="text" name="titulo" maxlength="40" required value="<?= e($v('titulo')) ?>" placeholder="Ex.: Festa Junina 2026"></label>
    <div class="linha-dupla">
      <label>Data <input type="date" name="data" required value="<?= e($v('data')) ?>"></label>
      <label>Hora (opcional) <input type="time" name="hora" value="<?= e($v('hora')) ?>"></label>
    </div>
    <label>Local (até 60) <input type="text" name="local" maxlength="60" required value="<?= e($v('local')) ?>" placeholder="Ex.: Quadra da escola"></label>
    <label>Chamada (até 80, opcional) <input type="text" name="chamada" maxlength="80" value="<?= e($v('chamada')) ?>" placeholder="Ex.: Traga a família e venha se divertir!"></label>
    <label>Informações (até 4 linhas de 60 caracteres, uma por linha) <textarea name="informacoes" rows="3" placeholder="Entrada gratuita&#10;Barracas de comidas típicas"><?= e($v('informacoes')) ?></textarea></label>
    <label>Público (para a linguagem visual)
      <select name="etapa"><?php foreach ($etapas as $k => $r): ?><option value="<?= e($k) ?>" <?= $v('etapa', 'EF_ANOS_INICIAIS') === $k ? 'selected' : '' ?>><?= e($r) ?></option><?php endforeach; ?></select>
    </label>
    <fieldset>
      <legend>Formatos</legend>
      <?php $fsel = (array) ($valores['formatos'] ?? ['STORY', 'FEED', 'A4']); foreach ($formatos as $k => $f): ?>
        <label class="check"><input type="checkbox" name="formatos[]" value="<?= e($k) ?>" <?= in_array($k, $fsel, true) ? 'checked' : '' ?>> <?= e($f[0]) ?> <small class="nota">(<?= e($f[1]) ?>)</small></label>
      <?php endforeach; ?>
    </fieldset>
    <h2>Estilo visual</h2>
    <div class="estilos">
      <?php foreach ($estilos as $es): ?>
        <label class="estilo-cartao" :class="{ escolhido: estilo === '<?= e($es['chave']) ?>' }">
          <input type="radio" name="estilo" value="<?= e($es['chave']) ?>" x-model="estilo" required>
          <img class="estilo-imagem" src="<?= e(asset($es['amostra'])) ?>" alt="Exemplo do estilo <?= e($es['nome']) ?>" loading="lazy" style="border-color: <?= e($es['cores']['destaque']) ?>">
          <strong><?= e($es['nome']) ?></strong><small><?= e($es['publico']) ?></small>
        </label>
      <?php endforeach; ?>
    </div>
    <div class="acoes-passo"><button type="submit" class="botao" :disabled="enviando" x-text="enviando ? 'Enviando…' : 'Gerar cartaz'">Gerar cartaz</button></div>
  </form>
</section>
