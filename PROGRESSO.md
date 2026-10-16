# PROGRESSO.md — estado do projeto

> Atualizado ao fim de cada sessão. Leia junto com `CLAUDE.md` e
> `docs/PROMPT-MESTRE.md` antes de qualquer trabalho.

## Fase atual

**FASE 2 — Banco de dados, BNCC e autenticação: concluída, aguardando
`APROVADO FASE 2`.**

| Fase | Situação |
|---|---|
| 0 — Fundação | ✅ aprovada (16/10/2026) |
| 1 — Prova de conceito dos motores de IA | ✅ aprovada (16/10/2026) |
| 2 — Banco, BNCC e autenticação | ✅ concluída (aguardando aprovação) |
| 3 — Assistente de conteúdo e revisão | ⏳ |
| 4 — Fila, geração de imagem e conferência | ⏳ |
| 5 — Interface completa, capas, cartazes e PDF | ⏳ |
| 6 — Modo editável | ⏳ |
| 7 — Diferenciais pedagógicos | ⏳ |
| 8 — Gestão, cotas e LGPD | ⏳ |
| 9 — Produção no cecape-server | ⏳ |

## Fase 0 — critérios de aceite

| Critério | Status | Evidência |
|---|---|---|
| `php -l` sem erros em todos os `.php` | ✅ | `find … -name '*.php' -exec php -l` → nenhum erro |
| `php tests/run.php` passa (config, banco, roteador, /health) | ✅ | 27 testes, 0 falhas |
| `/health` 200 `{"status":"ok"}`; 503 com banco desligado, mensagem em PT | ✅ | teste `fase0_health.php` + verificação HTTP com `php -S` e MariaDB parado |
| `.env` e `app/` inacessíveis pelo navegador | ✅ | nginx: `deploy/nginx.conf.example` validado por teste; Apache/HostGator: `.htaccess` validados por teste e por requisições reais num Apache 2.4 local (`/.env`, `/app/config.php`, `/storage/logs/app.log` → 404) |
| `LEIA-ME.md` explica a instalação sem terminal local | ✅ | `LEIA-ME.md`: Parte A (HostGator/cPanel em subpasta) e Parte B (HestiaCP) |

## Fase 1 — critérios de aceite

| Critério | Status | Evidência |
|---|---|---|
| Com `AI_MOCK=1` o script gera PNG válido e os testes passam | ✅ | `php tools/poc_generate.php … --motores=gemini,openai,mock` → 3 PNG 1024×1536; `php tests/run.php` → 49 testes, 0 falhas |
| Com chaves reais, 1 página A4 em cada motor em `storage/poc/` (caminho, dimensões, tempo) | ⏳ | Depende das chaves do coordenador: página `/poc` ou `tools/poc_generate.php` com `AI_MOCK=0`. A rede deste ambiente bloqueia `api.openai.com` |
| Erros da API viram mensagens claras em PT e ficam em log, sem derrubar o script | ✅ | `HttpClient::fromHttpStatus` (401/403, 429, 400/404/422, 5xx), tempo limite e rede; teste `fase1_motores.php`; `PocService` captura e registra em `usage_log` com `status=erro` |
| Tempo limite configurável (padrão 360 s) e até 2 novas tentativas com espera crescente | ✅ | `AI_TIMEOUT`, `AI_RETRIES`, `AI_BACKOFF`; `AbstractImageEngine::generate()` espera 5 s, 10 s; teste "tentativas" |
| IDs de modelo e preços confirmados e anotados | ✅ (com ressalva) | Tabela "Modelos e preços" abaixo. Os sites oficiais são bloqueados pela rede deste ambiente; os valores vêm de buscas que citam as páginas oficiais e devem ser conferidos pelo coordenador |
| Kit de teste cego: 6 JSONs (3 componentes × 2 anos) | ✅ | `data/exemplos/*.json` + `LEIA-ME.md`; teste "kit" |

## Fase 2 — critérios de aceite

| Critério | Status | Evidência |
|---|---|---|
| `schema.sql` roda duas vezes seguidas sem erro | ✅ | teste "schema: roda duas vezes" (13 tabelas, todas `utf8mb4_unicode_ci`); importado 2× no MariaDB local |
| Importação BNCC carrega todas as habilidades; quantidade e códigos conhecidos; rejeita inexistente | ✅ | 1.721 registros (93 EI + 1.304 EF + 183 EM + 141 Computação); `EF04CI01`, `EF67LP08`, `EM13LGG103` conferidos; `EF04CI99` rejeitado; `tools/import_bncc.php` |
| Testes de login: senha errada, bloqueio após 5 tentativas, CSRF ausente rejeitado, sessão regenerada | ✅ | `tests/cases/fase2_auth.php` + verificação HTTP (cookie de sessão muda no login) |
| Professor não acessa telas de admin | ✅ | teste "permissão": 403 em `/admin/*` para professor e coordenador; 302 para anônimo; 200 para admin |
| Aceite do termo registrado com data, hora e IP | ✅ | tabela `consents` (`accepted_at`, `ip`, `user_agent`, versão); teste "termo" |

## Modelos e preços (consultados em 16/10/2026)

| Motor | ID no `.env` | Situação | Preço de referência por imagem (US$) |
|---|---|---|---|
| Google Gemini | `gemini-3-pro-image` (padrão) | GA desde 28/05/2026. **`gemini-3-pro-image-preview` foi desligado em 25/06/2026** | 0,134 (1K/2K) · 0,24 (4K) — US$ 120 por 1 M tokens de saída |
| Google Gemini | `gemini-3.1-flash-image` | Sucessor de `gemini-3.1-flash-image-preview` (desligado em 25/06/2026); no Vertex ainda aparece com sufixo `-preview` | 0,045 (0.5K) · 0,067 (1K) · 0,101 (2K) · 0,151 (4K) — US$ 60 / 1 M tokens |
| Google Gemini | `gemini-3.1-flash-lite-image` | Nano Banana 2 Lite | 0,0336 (1K) — US$ 30 / 1 M tokens |
| OpenAI | `gpt-image-2.5-flare` (padrão) | Lançado em 08/09/2026; variante rápida | Cobrança por token: US$ 30 / 1 M tokens de saída de imagem, 5 / 1 M texto de entrada. 1024×1536 ≈ 0,012 (low) · 0,048 (medium) · 0,187 (high) |
| OpenAI | `gpt-image-2.5-sunburst` | Mesma data; variante de qualidade (mais lenta) | Mesma tabela de tokens do flare |

O custo gravado em `usage_log` é calculado pelos tokens devolvidos pela API
(`usageMetadata` no Gemini, `usage` na OpenAI); a tabela por imagem é só
reserva. Fontes: ai.google.dev (changelog e modelos), developers.openai.com
(guia de imagens), via resumos de busca. Ação pendente: o coordenador confere
os valores nas páginas oficiais de preços ao ativar as chaves.

## Decisões tomadas

1. **Repositório próprio** `flaviospina/caminho_e_palavra_educa-o`, com o
   projeto na raiz conforme a seção 4 do prompt-mestre. A Fase 0 foi iniciada
   por engano em `gamifica_3d` (Cruzadinha 3D) e movida para cá na mesma
   sessão; os commits da Fase 0 foram retirados da branch do `gamifica_3d`.
2. **Autoload por classmap do Composer** (`"classmap": ["app/"]`), para manter
   as pastas em minúsculas (`app/controllers/`) como pede o prompt. Ao criar uma
   classe nova é preciso rodar `composer dump-autoload --optimize` e versionar
   `vendor/` — isso é feito pelo Claude Code em cada fase; o servidor só faz
   `git pull`.
3. **Testes contra MySQL real** usando `.env.test` (banco separado, ex.:
   `materiais_test`). Se `.env.test` não existir, caem no `.env`. No contêiner
   de desenvolvimento usamos MariaDB 10.11 (compatível); em produção, MySQL 8.
4. **`public/storage` é um link simbólico** para `storage/public/` (arquivos
   gerados que podem ser servidos). Tudo o mais em `storage/` é privado.
5. **Função global `e()`** em `app/helpers.php` para escapar saída.
6. **Cabeçalhos de segurança** (`nosniff`, `SAMEORIGIN`, `Referrer-Policy`)
   enviados em toda resposta por `App\Response::send()`.
7. **Hospedagem temporária no HostGator** em `https://cecapescs.com.br/materiais`
   (cPanel/Apache, subpasta). Ajustes feitos na própria Fase 0: `.htaccess`
   na raiz (encaminha para `public/`, nega pastas privadas, redireciona
   `/public/` para a raiz da subpasta), `.htaccess` em `public/` (controlador
   frontal), `Require all denied` nas pastas privadas, constante `BASE_PATH`
   e `Router::stripBasePath()` para o prefixo da subpasta, helper `url()`.
   Validado num Apache 2.4 local com a mesma estrutura de pastas.
   Limitação: sem `systemd`; o worker (Fase 4) usará Cron Job do cPanel
   enquanto o sistema estiver no HostGator.
8. **Fase 1 — arquitetura de IA.** `app/ai/`: `ImageEngine`/`TextEngine`
   (interfaces), `ImageRequest`/`ImageResult`, `AiException` (mensagem em PT +
   detalhe técnico + tipo), `HttpClient` (cURL), `AbstractImageEngine` (tempo
   limite, novas tentativas, log), `GeminiImage`, `OpenAIImage`, `MockEngine`
   (desenha a página com GD e as fontes DejaVu versionadas em
   `templates/fonts/`), `PromptBuilder`, `Styles` (catálogo
   `templates/styles/estilos.json` + um `.txt` por estilo), `Pricing`,
   `EngineFactory`. Proporção **2:3** (1024×1536 / aspectRatio 2:3) por ser a
   mais próxima de A4 nos dois motores; a montagem do PDF (Fase 5) ajusta.
9. **`usage_log` criada já na Fase 1** (`sql/schema.sql`, idempotente,
   compatível com MySQL 5.7), porque o registro de custo é entrega desta fase.
10. **Página `/poc` protegida por `POC_ACCESS_KEY`** (sem login até a Fase 2) e
    com geração síncrona no navegador, para quem não tem terminal. Na Fase 4
    toda geração passa pela fila. `Session` e `Csrf` criados agora porque todo
    POST exige CSRF (regra de todas as fases).
11. **Motor de texto real fica para a Fase 3**; na Fase 1 `TextEngine` só tem
    a implementação simulada.
12. **Fase 2 — base BNCC** do dataset aberto `bncc-dev/bncc-dados` (CC BY 4.0,
    commit em `data/bncc/VERSAO-commit.txt`), incluindo o complemento de
    Computação (141 registros, `source = computacao-2022`). Mapeamento: EI usa
    o grupo etário como "ano" (`bebes`, `criancas_bem_pequenas`,
    `criancas_pequenas`) e o campo de experiência como componente; EM sem
    componente usa a área; EF divide-se em Anos Iniciais/Finais pelos anos.
13. **Autenticação própria** (sem biblioteca): `password_hash`, bloqueio de 15
    min após 5 erros, token CSRF por sessão, `session_regenerate_id` no login,
    recuperação por e-mail com token sha256 de uso único (60 min), senha
    provisória com troca obrigatória, termo de uso versionado (`TERM_VERSION`).
    Guardas de rota em `App\Guard`; `/poc` agora exige admin.
14. **E-mail** via `mail()` do PHP (HostGator/HestiaCP) com `MAIL_DRIVER=log`
    nos testes. Se o `mail()` do HostGator for bloqueado, trocar por SMTP é um
    ajuste só em `app/services/Mailer.php`.
15. **Imagens de referência** do coordenador salvas em
   `templates/styles/referencias/` e descritas em `templates/styles/REFERENCIAS.md`,
   com um mapa inicial de 6 estilos a confirmar na Fase 1.

## Ambiente de produção temporário (HostGator) — confirmado em 16/10/2026

- URL: `https://cecapescs.com.br/materiais` (conta cPanel `itthri79`).
- `/health` → 200 em produção: PHP 8.3.35, todas as extensões, `storage` gravável.
- Banco `itthri79_materiais`, **MySQL 5.7.44** (não 8). Charset padrão do banco
  era `utf8` (mb3); a conexão força `utf8mb4` e recomendou-se alterar o
  agrupamento do banco para `utf8mb4_unicode_ci` no phpMyAdmin.
- **Regra para a Fase 2:** o `schema.sql` deve rodar tanto no MySQL 5.7 quanto
  no 8: `utf8mb4_unicode_ci` explícito em toda tabela, sem `utf8mb4_0900_*`,
  sem `CHECK` como garantia (validar no PHP), sem `DEFAULT (expressão)`, sem
  funções de janela, chaves únicas/índices em VARCHAR com no máximo 191
  caracteres, `JSON` apenas como armazenamento (sem depender de índices JSON).

## Pendências para a Fase 3

- Coordenador: no servidor, importar `schema.sql` + `seeds.sql`, entrar como
  admin, importar a BNCC pela tela e trocar o e-mail do admin inicial.
- Coordenador: confirmar o teste dos motores reais pela `/poc` e os preços.
- Fase 3: assistente de conteúdo (etapa → ano → componente → habilidade →
  tema), adaptador real de texto (Gemini) com saída JSON, validador do esquema
  usando `BnccSkill::validateFor()`, editor de revisão, plano de aula em PDF,
  "Meus projetos" (tabelas `projects`/`pages` já existem).

## Ambiente de desenvolvimento (contêiner do Claude Code)

- PHP 8.3.6 CLI com pdo_mysql, mbstring, curl, gd, intl, zip.
- MariaDB 10.11 local (`127.0.0.1:3306`), banco `materiais_test`,
  usuário `materiais` / senha `materiais123` (só no contêiner).
- Servidor de testes manuais: `cd public && php -S 127.0.0.1:8089 index.php`.
