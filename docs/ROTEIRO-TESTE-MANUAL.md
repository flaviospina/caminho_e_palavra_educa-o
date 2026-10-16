# Roteiro de teste manual — professor cria uma apostila do início ao PDF

Ambiente: `https://cecapescs.com.br/materiais`, Cron Job do worker ativo
(LEIA-ME A6f). Pode ser feito com `AI_MOCK=1` (sem custo; páginas simuladas)
ou `AI_MOCK=0` (Gemini real). Tempo estimado: 15 minutos.

| # | Ação | Resultado esperado |
|---|---|---|
| 1 | Admin: **Escolas → editar** a escola do professor, enviar um logotipo PNG, escolher a cor principal e salvar | Logotipo aparece na tela de edição |
| 2 | Admin: **Identidade** → enviar o logotipo da rede | "Logotipo da rede atualizado" |
| 3 | Entrar como professor (senha provisória) | Pede troca de senha e aceite do termo; cai no Painel |
| 4 | **Criar material** → etapa Anos Iniciais, 4º ano, Ciências; marcar EF04CI01; tema "Misturas no dia a dia"; Continuar | Lista de habilidades filtrada pelo recorte; passo 2 abre |
| 5 | Finalidade "Introdução", 3 páginas; Continuar; estilo "Doodle escolar"; **Gerar conteúdo com a IA** | Em até 1 min abre o passo 4 (Revisão) com capa, habilidades e 3 páginas |
| 6 | Alterar o título da página 1 para um texto de 60 caracteres | Contador fica vermelho; ao salvar, lista o erro e nada é salvo |
| 7 | Corrigir o título, salvar | Passo 5 com selo "Revisado" e "revisada pelo professor em…" |
| 8 | **Plano de aula** e **PDF do plano** | Plano com habilidades oficiais, momentos e avaliação; PDF abre com texto selecionável |
| 9 | **Gerar imagens das páginas** → confirmar | Mensagem "4 página(s) enviada(s) para a fila"; barra "Gerando…" atualizando a cada 5 s |
| 10 | Aguardar | "✅ Pronto!"; galeria com capa + 3 páginas; capa com faixa inferior com logotipo e nome da escola e nome da rede |
| 11 | Em uma página, **Gerar esta página de novo** | Página volta para "Na fila" e depois "Pronta" com "2 gerações" |
| 12 | **Montar o PDF da apostila**: arrastar a página 3 para antes da 2 (ou usar as setas); **Baixar PDF** | Arquivo `apostila-N.pdf` com 4 páginas A4, capa primeiro, ordem 1, 3, 2; rodapé discreto em cada página |
| 13 | Clicar em "Alternativa: montar o PDF no servidor com esta ordem" | Mesmo PDF gerado pelo servidor |
| 14 | **Duplicar este projeto** | Abre a cópia como rascunho ("Cópia de …"), sem imagens |
| 15 | **Criar cartaz** → Festa junina, título, data, local, chamada, 3 formatos, estilo Cartoon infantil → Gerar | Abre o projeto do cartaz; galeria mostra Cartaz STORY, FEED e A4 ao ficarem prontos; "baixar PNG" em cada um |
| 16 | **Meus projetos** → filtrar pelo código `EF04CI01` | Lista só a apostila; filtro por "Cartaz" na busca por texto encontra o cartaz |
| 17 | **Estilos** | Cada estilo mostra a referência e, para o Doodle escolar, a página real gerada no passo 10 |
| 18 | Entrar como coordenador da mesma escola | Vê a apostila em Meus projetos, abre o projeto, mas não tem "Revisar" nem "Gerar" |

Registrar no relatório da fase: data, usuário, modo (simulado/real) e qualquer
divergência observada.
