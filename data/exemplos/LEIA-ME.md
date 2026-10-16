# Kit de teste cego — Fase 1

Seis conteúdos de exemplo no esquema da seção 5 do prompt-mestre, prontos para
gerar a mesma página nos motores configurados e comparar o resultado:

| Arquivo | Componente | Ano | Habilidade(s) | Estilo |
|---|---|---|---|---|
| `ef04_ciencias_misturas.json` | Ciências | 4º | EF04CI01 | doodle_escolar |
| `ef07_ciencias_maquinas.json` | Ciências | 7º | EF07CI01 | infografico_limpo |
| `ef04_matematica_problemas.json` | Matemática | 4º | EF04MA03 | cartoon_infantil |
| `ef07_matematica_proporcionalidade.json` | Matemática | 7º | EF07MA17 | colagem_adesivos |
| `ef04_portugues_ideia_central.json` | Língua Portuguesa | 4º | EF15LP03, EF35LP03 | doodle_escolar |
| `ef07_portugues_imagens_noticias.json` | Língua Portuguesa | 7º | EF67LP08 | jovem_cinematografico |

Cada arquivo tem capa + 3 páginas (abertura, explicação, prática). Os códigos
BNCC foram escritos a partir da base oficial e serão validados automaticamente
contra a tabela `bncc_skills` na Fase 2.

Como usar:
- Navegador: `/poc` (perfil admin) → escolher o exemplo, a página e os motores.
- Terminal: `php tools/poc_generate.php data/exemplos/ef04_ciencias_misturas.json --pagina=1`
