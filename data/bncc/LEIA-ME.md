# Base BNCC — dataset aberto `bncc-dados`

Fonte: **bncc.dev (mantido pela Profy)** — https://github.com/bncc-dev/bncc-dados
Licença dos dados: **CC BY 4.0** (ver `LICENSE-dados.md`). Commit copiado: ver `VERSAO-commit.txt`.

Arquivos usados pelo importador (`app/services/BnccImporter.php`):

| Arquivo | Conteúdo | Registros |
|---|---|---|
| `bncc-2018/estrutura.json` | áreas, componentes, campos de experiência, recortes temporais | — |
| `bncc-2018/educacao-infantil.json` | objetivos de aprendizagem da EI | 93 |
| `bncc-2018/ensino-fundamental.json` | habilidades do EF (1º ao 9º ano) | 1.304 |
| `bncc-2018/ensino-medio.json` | habilidades do EM | 183 |
| `computacao-2022/computacao.json` | complemento de Computação (Parecer CNE/CEB 2/2022) | 141 |

Total: 1.721 registros na tabela `bncc_skills`.

Mapeamento para a tabela: `stage` (EI, EF_ANOS_INICIAIS, EF_ANOS_FINAIS, EM),
`years` (anos separados por vírgula; na EI, o grupo etário: `bebes`,
`criancas_bem_pequenas`, `criancas_pequenas`; no EM, `1,2,3`), `component`
(na EI, o campo de experiência; no EM sem componente, a área), `thematic_unit`,
`knowledge_object`, `description` (texto oficial) e `source`.

Para atualizar a base: substitua os arquivos JSON por uma versão mais nova do
repositório e rode a importação de novo (Admin → Base BNCC, ou
`php tools/import_bncc.php`). A importação é idempotente.
