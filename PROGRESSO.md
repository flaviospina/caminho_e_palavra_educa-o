# PROGRESSO.md — estado do projeto

> Atualizado ao fim de cada sessão. Leia junto com `CLAUDE.md` e
> `docs/PROMPT-MESTRE.md` antes de qualquer trabalho.

## Fase atual

**FASE 0 — Fundação do projeto: concluída, aguardando `APROVADO FASE 0`.**

| Fase | Situação |
|---|---|
| 0 — Fundação | ✅ concluída (aguardando aprovação) |
| 1 — Prova de conceito dos motores de IA | ⏳ não iniciada |
| 2 — Banco, BNCC e autenticação | ⏳ |
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
8. **Imagens de referência** do coordenador salvas em
   `templates/styles/referencias/` e descritas em `templates/styles/REFERENCIAS.md`,
   com um mapa inicial de 6 estilos a confirmar na Fase 1.

## Pendências para a Fase 1

- Confirmar na documentação oficial os IDs e preços dos modelos de imagem
  (`gemini-3-pro-image-preview`, `gemini-3.1-flash-image-preview`,
  `gpt-image-2.5-flare`, `gpt-image-2.5-sunburst`) e anotar aqui.
- Definir o modelo de texto (Gemini) e o formato de saída JSON.
- Escrever um prompt-modelo por estilo em `templates/styles/` a partir de
  `REFERENCIAS.md`.
- Nomes dos estilos propostos: `cartoon_infantil`, `doodle_escolar`,
  `colagem_adesivos`, `infografico_limpo`, `jovem_cinematografico`,
  `epico_realista` — aguardando confirmação do coordenador.

## Ambiente de desenvolvimento (contêiner do Claude Code)

- PHP 8.3.6 CLI com pdo_mysql, mbstring, curl, gd, intl, zip.
- MariaDB 10.11 local (`127.0.0.1:3306`), banco `materiais_test`,
  usuário `materiais` / senha `materiais123` (só no contêiner).
- Servidor de testes manuais: `cd public && php -S 127.0.0.1:8089 index.php`.
