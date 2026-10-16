// public/assets/js/app.js — componentes Alpine.js da aplicação
// Este arquivo é carregado ANTES do Alpine (ordem dos <script defer> no layout):
// os componentes são registrados no evento alpine:init. Se por algum motivo o
// Alpine já estiver presente, registra na hora.
function registrarAlpine(fn) {
  if (window.Alpine) { fn(); } else { document.addEventListener('alpine:init', fn); }
}

registrarAlpine(() => {
  // Painel "Estado do sistema" da página inicial: consulta GET /health.
  Alpine.data('estadoSistema', () => ({
    carregando: true,
    ok: false,
    mensagem: '',
    php: '',
    banco: '',
    storage: '',
    async carregar() {
      const base = document.querySelector('link[rel="stylesheet"][href*="/assets/css/app.css"]');
      const baseUrl = base ? base.getAttribute('href').replace(/\/assets\/css\/app\.css(\?.*)?$/, '') : '';
      try {
        const resposta = await fetch(baseUrl + '/health', { headers: { Accept: 'application/json' } });
        const dados = await resposta.json();
        this.ok = dados.status === 'ok';
        this.mensagem = dados.mensagem || (this.ok ? 'Tudo certo.' : 'Há problemas no sistema.');
        this.php = dados.php && dados.php.ok ? 'ok (' + dados.php.versao + ')' : 'com problemas';
        this.banco = dados.banco && dados.banco.ok ? 'ok (' + dados.banco.versao + ')' : 'indisponível';
        this.storage = dados.storage && dados.storage.ok ? 'ok' : 'sem permissão de escrita';
      } catch (erro) {
        this.ok = false;
        this.mensagem = 'Não foi possível consultar o estado do sistema.';
      } finally {
        this.carregando = false;
      }
    },
  }));
});

// Assistente de criação (passos 1–3): anos por etapa, componentes e habilidades via API.
registrarAlpine(() => {
  Alpine.data('assistente', (cfg) => ({
    passo: 1, enviando: false, erroPasso: '',
    etapa: cfg.valores.etapa || '', ano: cfg.valores.ano || '', componente: cfg.valores.componente || '',
    tema: cfg.valores.tema || '', observacoes: cfg.valores.observacoes || '',
    finalidade: cfg.valores.finalidade || 'introducao', paginas: cfg.valores.paginas || '3', estilo: cfg.valores.estilo || '',
    selecionadas: Array.isArray(cfg.valores.habilidades) ? cfg.valores.habilidades : [],
    componentes: [], habilidades: [], busca: '', mensagemHabilidades: '',
    carregandoComponentes: false, carregandoHabilidades: false,
    get anosDisponiveis() { return cfg.anos[this.etapa] || []; },
    async iniciar() {
      if (this.etapa && this.ano) { await this.carregarComponentes(); }
      if (this.componente) { await this.buscarHabilidades(); }
    },
    mudouEtapa() { this.ano = ''; this.componente = ''; this.componentes = []; this.habilidades = []; this.selecionadas = []; },
    async mudouAno() { this.componente = ''; this.habilidades = []; this.selecionadas = []; await this.carregarComponentes(); },
    async mudouComponente() { this.selecionadas = []; this.busca = ''; await this.buscarHabilidades(); },
    async carregarComponentes() {
      if (!this.etapa || !this.ano) { return; }
      this.carregandoComponentes = true;
      try {
        const r = await fetch(cfg.apiComponentes + '?etapa=' + encodeURIComponent(this.etapa) + '&ano=' + encodeURIComponent(this.ano), { headers: { Accept: 'application/json' } });
        const d = await r.json();
        this.componentes = d.ok ? d.data : [];
      } catch (e) { this.componentes = []; this.erroPasso = 'Não foi possível carregar os componentes. Recarregue a página.'; }
      finally { this.carregandoComponentes = false; }
    },
    async buscarHabilidades() {
      if (!this.componente) { return; }
      this.carregandoHabilidades = true; this.mensagemHabilidades = '';
      try {
        const q = '?etapa=' + encodeURIComponent(this.etapa) + '&ano=' + encodeURIComponent(this.ano) + '&componente=' + encodeURIComponent(this.componente) + '&q=' + encodeURIComponent(this.busca || '');
        const r = await fetch(cfg.apiHabilidades + q, { headers: { Accept: 'application/json' } });
        const d = await r.json();
        this.habilidades = d.ok ? d.data : [];
        this.mensagemHabilidades = d.mensagem || '';
      } catch (e) { this.habilidades = []; this.mensagemHabilidades = 'Não foi possível carregar as habilidades.'; }
      finally { this.carregandoHabilidades = false; }
    },
    alternar(codigo, marcado) {
      if (marcado) { if (!this.selecionadas.includes(codigo) && this.selecionadas.length < cfg.maxHabilidades) { this.selecionadas.push(codigo); } }
      else { this.selecionadas = this.selecionadas.filter((c) => c !== codigo); }
    },
    avancar() {
      this.erroPasso = '';
      if (this.passo === 1) {
        if (!this.etapa || !this.ano || !this.componente) { this.erroPasso = 'Escolha etapa, ano e componente.'; return; }
        if (this.selecionadas.length === 0) { this.erroPasso = 'Marque pelo menos uma habilidade BNCC.'; return; }
        if (this.tema.trim().length < 3) { this.erroPasso = 'Informe o tema (mínimo 3 caracteres).'; return; }
      }
      if (this.passo === 2 && !this.finalidade) { this.erroPasso = 'Escolha a finalidade.'; return; }
      this.passo = Math.min(3, this.passo + 1);
      window.scrollTo({ top: 0, behavior: 'smooth' });
    },
    voltar() { this.erroPasso = ''; this.passo = Math.max(1, this.passo - 1); },
  }));
});

// Editor de revisão: contador de caracteres e aviso de limite em cada campo.
document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('.form-revisao [data-max]').forEach((el) => {
    const contador = el.closest('.campo-revisao')?.querySelector('.contador');
    const max = parseInt(el.dataset.max, 10);
    const atualizar = () => {
      const n = [...el.value.trim()].length;
      if (!contador) { return; }
      contador.textContent = n + '/' + max;
      const excedido = n > max;
      contador.classList.toggle('excedido', excedido);
      el.classList.toggle('campo-excedido', excedido);
      contador.title = excedido ? 'Texto acima do limite do estilo: encurte para caber na página.' : '';
    };
    el.addEventListener('input', atualizar);
    atualizar();
  });
  document.querySelectorAll('.form-revisao [data-max-itens]').forEach((el) => {
    const contador = el.closest('.campo-revisao')?.querySelector('.contador');
    const maxItens = parseInt(el.dataset.maxItens, 10);
    const maxItem = parseInt(el.dataset.maxItem, 10);
    const atualizar = () => {
      const itens = el.value.split(/\r?\n/).map((s) => s.trim()).filter((s) => s !== '');
      const longo = itens.find((s) => [...s].length > maxItem);
      const excedido = itens.length > maxItens || Boolean(longo);
      if (!contador) { return; }
      contador.textContent = itens.length + '/' + maxItens + ' itens' + (longo ? ' · item com ' + [...longo].length + '/' + maxItem : '');
      contador.classList.toggle('excedido', excedido);
      el.classList.toggle('campo-excedido', excedido);
    };
    el.addEventListener('input', atualizar);
    atualizar();
  });
});

// Tela do projeto: acompanha a fila de geração (consulta a cada 5 s) e avisa quando ficar pronto.
registrarAlpine(() => {
  Alpine.data('geracaoProjeto', (cfg) => ({
    estado: cfg.inicial, timer: null, avisou: false,
    get precisaRevisar() { return (this.estado.paginas || []).some((p) => p.status === 'revisar'); },
    iniciar() { if (!this.estado.concluido) { this.timer = setInterval(() => this.atualizar(), 5000); } },
    async atualizar() {
      try {
        const r = await fetch(cfg.url, { headers: { Accept: 'application/json' }, cache: 'no-store' });
        const d = await r.json();
        if (d.ok) { this.estado = d.data; }
        if (this.estado.concluido) {
          clearInterval(this.timer);
          if (!this.avisou) {
            this.avisou = true;
            document.title = (this.estado.status === 'pronto' ? '✅ Pronto! ' : '⚠️ ') + document.title;
            if ('Notification' in window && Notification.permission === 'granted') {
              new Notification('Materiais Visuais', { body: this.estado.status === 'pronto' ? 'Suas páginas estão prontas.' : 'A geração terminou com erro.' });
            }
          }
        }
      } catch (e) { /* tenta de novo no próximo ciclo */ }
    },
  }));
});
