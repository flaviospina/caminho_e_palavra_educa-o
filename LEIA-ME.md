# LEIA-ME — Gerador de Materiais Visuais com IA (CECAPE / SCS)

Sistema web para professores da rede municipal de São Caetano do Sul criarem,
com IA, apostilas visuais A4, cartazes de eventos e planos de aula alinhados à
BNCC. Backend PHP 8.2 + MySQL 8, frontend HTML/CSS/JS com Alpine.js, sem build.

Este documento explica como **instalar e atualizar** usando apenas o navegador.
Nenhum passo exige terminal no seu computador. Há dois cenários:

- **Parte A — HostGator (cPanel, Apache), em subpasta** do domínio
  `cecapescs.com.br` (`https://cecapescs.com.br/materiais`). Hospedagem
  temporária enquanto o cecape-server não está disponível.
- **Parte B — cecape-server (HestiaCP, nginx)**, destino definitivo.

---

## Parte A — HostGator (cPanel) em subpasta

### A1. Enviar o código

Opção 1 (recomendada): cPanel → **Git™ Version Control** → **Criar** →
*Clone a Repository* com a URL `https://github.com/flaviospina/caminho_e_palavra_educa-o`,
caminho `public_html/materiais`, branch `main`. Para atualizar depois, abra o
repositório em *Manage* → *Pull or Deploy* → **Update from Remote**.

Opção 2: baixe o ZIP do GitHub (*Code → Download ZIP*), envie pelo
**Gerenciador de Arquivos** para `public_html/materiais` e extraia (os arquivos
devem ficar direto em `public_html/materiais/`, não numa subpasta do ZIP).

A pasta `vendor/` já vem no repositório; não é preciso rodar `composer`.

### A2. PHP

cPanel → **Select PHP Version** (ou *MultiPHP Manager*): escolha **8.2** e
marque as extensões `pdo_mysql`, `mbstring`, `curl`, `gd`, `openssl`,
`fileinfo`, `json`, `intl`, `zip`.

### A3. Banco de dados

cPanel → **Bancos de Dados MySQL®**: crie o banco `materiais` e o usuário
`materiais` (o cPanel adiciona o prefixo da conta, ex.: `itthri79_materiais`),
dê **todos os privilégios** e anote a senha. Crie também `materiais_test` se
quiser rodar os testes no servidor.

Depois, no **phpMyAdmin**, selecione o banco → aba **Operações** →
*Agrupamento* → `utf8mb4_unicode_ci` → Executar. O HostGator cria o banco em
`utf8` (mb3); o sistema força `utf8mb4` na conexão e nas tabelas, mas o padrão
do banco alinhado evita avisos. O MySQL do HostGator é 5.7; o sistema é
compatível com 5.7 e 8.

### A4. Configurar o `.env`

No Gerenciador de Arquivos, em `public_html/materiais/`, copie `.env.example`
para `.env` e edite:

```
APP_ENV=production
APP_DEBUG=0
BASE_URL=https://cecapescs.com.br/materiais
DB_HOST=localhost
DB_NAME=itthri79_materiais
DB_USER=itthri79_materiais
DB_PASS=<senha>
AI_MOCK=1
```

O `BASE_URL` com `/materiais` no final é o que faz o sistema funcionar em
subpasta. Se usar outro nome de pasta, troque aqui.

### A5. Como o Apache enxerga a pasta

Os arquivos `.htaccess` já versionados fazem tudo:

- `materiais/.htaccess` encaminha qualquer endereço para `public/`, redireciona
  `/materiais/public/...` para `/materiais/...` e responde **404** para `.env`,
  `app/`, `vendor/`, `storage/` (exceto `storage/public/`), `tests/` etc.
- `public/.htaccess` envia tudo que não é arquivo real para `index.php`.
- `app/`, `vendor/`, `storage/` e outras pastas têm `Require all denied`.

Se o cPanel pedir, mantenha `AllowOverride All` (padrão no HostGator).

### A6. Conferir

1. `https://cecapescs.com.br/materiais/` → página inicial com "Estado do sistema".
2. `https://cecapescs.com.br/materiais/health` → HTTP 200 com `"status": "ok"`.
3. `https://cecapescs.com.br/materiais/.env` e
   `https://cecapescs.com.br/materiais/app/config.php` → **404**.
4. `https://cecapescs.com.br/materiais/public/` → redireciona para
   `https://cecapescs.com.br/materiais/`.

Se `/health` responder 503 com "Pasta de armazenamento sem permissão de
escrita", no Gerenciador de Arquivos clique com o botão direito em `storage`
→ *Change Permissions* → `755` (marque "aplicar às subpastas"). As pastas
privadas respondem 403 ou 404; ambos significam "bloqueado".

### A6b. Banco: importar `sql/schema.sql` e `sql/seeds.sql`

phpMyAdmin → selecione o banco → aba **Importar** → escolha `sql/schema.sql`
→ Executar. Repita com `sql/seeds.sql` (perfis, 6 estilos, escola de teste e o
administrador inicial). Os dois arquivos podem ser importados de novo a cada
fase sem apagar nada.

### A6d. Primeiro acesso e usuários

1. Abra `https://cecapescs.com.br/materiais/entrar` e entre com
   `admin@cecapescs.com.br` / senha provisória `Mudar@2026`.
2. O sistema pede a troca da senha e o aceite do termo de uso (registrado com
   data, hora e IP).
3. Menu **Base BNCC** → **Importar a base BNCC agora** (1.721 habilidades do
   dataset aberto bncc-dados; leva poucos segundos).
4. Menu **Escolas** → cadastre as escolas. Menu **Usuários** → cadastre
   professores e coordenadores: o sistema gera uma senha provisória, mostra na
   tela e envia por e-mail (`MAIL_DRIVER=mail` usa o `mail()` do PHP do HostGator;
   confira `MAIL_FROM` no `.env`, de preferência um e-mail do próprio domínio).
5. Se alguém errar a senha 5 vezes, a conta fica bloqueada por 15 minutos;
   "Esqueci minha senha" envia um link válido por 60 minutos, e o admin pode
   gerar uma nova senha provisória em **Usuários → nova senha**.
6. Troque o e-mail do administrador inicial para o seu em **Usuários → editar**.

### A6c. Prova de conceito dos motores de IA (`/poc`)

1. Entre como administrador (a página `/poc` é restrita ao perfil admin).
2. Para testar sem gastar créditos, mantenha `AI_MOCK=1`.
3. Para testar os motores reais: `AI_MOCK=0`, `GEMINI_API_KEY=...`,
   `OPENAI_API_KEY=...`, e confira `GEMINI_IMAGE_MODEL`, `OPENAI_IMAGE_MODEL`,
   `IMAGE_QUALITY`. Cada geração consome créditos.
4. Abra `https://cecapescs.com.br/materiais/poc`, escolha um
   exemplo, a página e os motores, e clique em **Gerar**. A geração pode levar
   alguns minutos; as imagens ficam em `storage/poc/<execução>/` e cada
   chamada é registrada em `usage_log` (tabela ao final da página).

Se o servidor interromper a geração por tempo (erro 504 ou página em branco),
reduza para um motor por vez; o limite de execução do PHP no HostGator pode ser
menor que o tempo de geração. A geração definitiva (Fase 4) roda em fila, fora
da requisição web.

### A7. Limitações no HostGator

- Não há `systemd`: o worker de geração (Fase 4) rodará por **Cron Job** do
  cPanel (intervalo mínimo de 5 min) enquanto o sistema estiver aqui.
- Tempo máximo de execução PHP é limitado pelo plano; gerações longas de
  imagem ficam na fila, nunca na requisição web.

---

## Parte B — cecape-server (HestiaCP)

---

### B1. Pré-requisitos no servidor

- Ubuntu 24.04 com HestiaCP, nginx, MySQL 8 e PHP 8.2 (fpm) com as extensões
  `pdo_mysql`, `mbstring`, `curl`, `gd`, `openssl`, `fileinfo`, `json`.
- `git` instalado (o HestiaCP já traz).
- Um domínio ou subdomínio apontado para o servidor, ex.
  `materiais.cecapescs.com.br`, com SSL (Let's Encrypt pelo HestiaCP).

### B2. Criar o domínio no HestiaCP

1. Entre no HestiaCP → **Web** → **Adicionar domínio web**.
2. Informe o domínio (ex.: `materiais.cecapescs.com.br`), marque
   **SSL / Let's Encrypt** e **Forçar HTTPS**.
3. Em **Avançado**, escolha a versão **PHP 8.2** no backend.
4. Salve. O HestiaCP cria a pasta
   `/home/<usuário>/web/<domínio>/public_html/`.

### B3. Criar o banco de dados

1. HestiaCP → **DB** → **Adicionar banco de dados**.
2. Nome: `materiais`; usuário: `materiais`; senha forte (anote).
   Charset: `utf8mb4` (padrão).
3. Repita para o banco de testes: `materiais_test` (mesmo usuário).
4. O HestiaCP mostra o nome completo (ex.: `cecape_materiais`); use-o no `.env`.

### B4. Baixar o código (primeira vez)

1. HestiaCP → ícone de **Terminal** (canto superior direito) → abre o terminal web.
2. Execute (troque `<usuário>` e `<domínio>`):
   ```
   cd /home/<usuário>/web/<domínio>/public_html
   git clone https://github.com/flaviospina/caminho_e_palavra_educa-o.git .
   ```
   Se o repositório for privado, o HestiaCP pedirá usuário e *token* do GitHub.
3. A pasta `vendor/` já vem no repositório. **Não** é preciso rodar `composer`.

### B5. Configurar o `.env`

1. HestiaCP → **Arquivos** (gerenciador de arquivos) → abra
   `web/<domínio>/public_html/`.
2. Copie `.env.example` para `.env` e edite:
   - `APP_ENV=production`, `APP_DEBUG=0`
   - `BASE_URL=https://materiais.cecapescs.com.br` (sem barra no final)
   - `DB_HOST=127.0.0.1`, `DB_NAME`, `DB_USER`, `DB_PASS` conforme o passo 3
   - `AI_MOCK=1` por enquanto (nenhum crédito de IA é gasto)
3. Para os testes, copie `.env.test.example` para `.env.test` apontando para
   o banco `materiais_test`.
4. Permissões: o `.env` fica **fora** de `public/`; o nginx nunca o serve.

### B6. Apontar o nginx para a pasta `public/`

O sistema só publica a pasta `public/`. Há duas formas:

**Forma A — diretivas personalizadas (mais simples).**
HestiaCP → Web → o domínio → **Avançado** → cole o conteúdo de
`deploy/nginx.conf.example` ajustando `root`, `server_name`, certificados e o
socket do php-fpm. Salve.

**Forma B — template do HestiaCP.**
No terminal web, copie `deploy/nginx.conf.example` para
`/usr/local/hestia/data/templates/web/nginx/php-fpm/materiais.stpl` (e o
`.tpl` correspondente) e selecione o template `materiais` em
Web → domínio → Avançado → Template do proxy/web. Isso é detalhado na Fase 9.

Em qualquer das formas, confirme que:
- `root` termina em `/public_html/public`;
- só a porta **443** está exposta (regra do DTI: o proxy reverso por nome
  encaminha para o servidor);
- arquivos iniciados por ponto e extensões `.env .sql .md .log` são negados.

Depois: HestiaCP → Web → domínio → **Reiniciar** (ou terminal web:
`sudo systemctl reload nginx`).

### B7. Pastas graváveis

No terminal web:
```
cd /home/<usuário>/web/<domínio>/public_html
chmod -R u+rwX storage
```
O `public/storage` é um link simbólico para `storage/public` (arquivos gerados
servidos como estáticos). Nada em `storage/` executa PHP. Os arquivos
`.htaccess` do repositório são ignorados pelo nginx.

### B8. Conferir

1. Abra `https://<domínio>/` — a página inicial mostra o "Estado do sistema".
2. Abra `https://<domínio>/health` — deve responder HTTP 200 com
   `{"status": "ok", ...}`. Se responder 503, a mensagem em português diz o que
   falta (banco, pasta `storage` ou extensão PHP).
3. Abra `https://<domínio>/.env` e `https://<domínio>/app/config.php` — ambos
   devem dar **404**.

### B8b. Prova de conceito dos motores (`/poc`) e `schema.sql`

Igual à Parte A (A6b e A6c): importe `sql/schema.sql` no phpMyAdmin do
HestiaCP e entre como admin. Pelo terminal web também é
possível rodar `php tools/poc_generate.php data/exemplos/<arquivo>.json --pagina=1`.

### B9. Rodar os testes no servidor (opcional, recomendado)

No terminal web:
```
cd /home/<usuário>/web/<domínio>/public_html
php tests/run.php
```
Resultado esperado: `N testes, 0 falhas ✅`. Os testes usam `.env.test`
(banco `materiais_test`) e sempre rodam em modo simulado de IA.

### B10. Atualizar (próximas fases)

1. Terminal web do HestiaCP:
   ```
   cd /home/<usuário>/web/<domínio>/public_html
   git pull
   ```
2. Se a fase trouxer SQL novo, importe `sql/schema.sql` no **phpMyAdmin**
   (HestiaCP → DB → phpMyAdmin). O arquivo é idempotente: pode rodar de novo.
3. Confira `/health` e a página inicial.

### B11. Desenvolvimento local (quando houver PHP instalado)

```
cp .env.test.example .env.test   # ajuste DB_*
php tests/run.php
cd public && php -S 127.0.0.1:8080 index.php
```

### B12. Estrutura resumida

```
public/        raiz publicada (index.php, assets/, storage → ../storage/public)
app/           config.php, Database.php, Router.php, controllers/, views/, services/, ai/
storage/       imagens, PDFs e logs gerados (privado)
templates/     prompts-modelo por estilo e imagens de referência
tests/         php tests/run.php
deploy/        nginx.conf.example (e, nas próximas fases, systemd e checklist)
docs/          PROMPT-MESTRE.md (especificação completa por fases)
```
