-- sql/seeds.sql
-- Dados iniciais (idempotente: INSERT … ON DUPLICATE KEY UPDATE).
-- Importe DEPOIS de sql/schema.sql. Pode ser importado de novo a cada fase.

SET NAMES utf8mb4;

-- ── Perfis ───────────────────────────────────────────────────────────────
INSERT INTO roles (id, slug, name, description) VALUES
  (1, 'professor', 'Professor(a)', 'Cria e revisa materiais dos próprios projetos.'),
  (2, 'coordenador', 'Coordenador(a) pedagógico(a)', 'Acompanha e aprova os materiais da sua escola.'),
  (3, 'admin', 'Administrador(a)', 'Secretaria/CECAPE: escolas, usuários, BNCC, cotas e relatórios.')
ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description);

-- ── Escola de teste ──────────────────────────────────────────────────────
INSERT INTO schools (id, name, short_name, city, active) VALUES
  (1, 'Escola de Teste CECAPE', 'Escola Teste', 'São Caetano do Sul', 1)
ON DUPLICATE KEY UPDATE short_name = VALUES(short_name);

-- ── Administrador inicial (senha provisória: Mudar@2026 — troque no primeiro acesso) ──
INSERT INTO users (id, school_id, role_id, name, email, password_hash, active, must_change_pass) VALUES
  (1, NULL, 3, 'Administrador CECAPE', 'admin@cecapescs.com.br', '$2y$10$fMwjPBo6PBA52Tf8/3a2.eX2VNap4sTo3kdNdkoXj9apjG4R581mK', 1, 1)
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- ── Estilos visuais (espelho de templates/styles/estilos.json) ───────────
INSERT INTO styles (slug, name, audience, description, mascot, limits_json, sort_order, active) VALUES
  ('cartoon_infantil', 'Cartoon infantil', 'Educação Infantil e 1º ao 3º ano', 'ilustração cartoon infantil, cores saturadas e alegres (amarelo, azul-celeste, verde, rosa), fundo claro com nuvens e grama, blocos arredondados com bordas grossas e ícones grandes, placas de madeira com setas, estrelas e confetes', 'uma criança sorridente de 7 anos, em estilo cartoon, com mochila escolar, sem marca ou personagem conhecido', '{"titulo":28,"subtitulo":60,"bloco_titulo":26,"bloco_texto":160,"item":60,"itens_max":4,"blocos_max":6}', 10, 1),
  ('doodle_escolar', 'Doodle escolar', '2º ao 6º ano', 'colagem de caderno escolar com rabiscos (doodles) à mão, letras recortadas multicoloridas no título, adesivos, setas, balões de fala, mini-cartões coloridos (amarelo, verde, rosa, azul) e fundo branco levemente texturizado', 'um estudante em traço simples de desenho animado, apontando para os blocos, sem marca ou personagem conhecido', '{"titulo":30,"subtitulo":70,"bloco_titulo":28,"bloco_texto":200,"item":70,"itens_max":5,"blocos_max":8}', 20, 1),
  ('colagem_adesivos', 'Colagem de adesivos', '4º ao 9º ano', 'página densa em estilo colagem com adesivos, etiquetas, setas e placas, mini-blocos numerados com ícones, paleta vibrante (laranja, verde, azul, lilás) sobre fundo claro, muitas pequenas ilustrações de apoio', 'nenhum personagem central; apenas ícones e pequenas ilustrações', '{"titulo":32,"subtitulo":80,"bloco_titulo":30,"bloco_texto":220,"item":80,"itens_max":5,"blocos_max":9}', 30, 1),
  ('infografico_limpo', 'Infográfico limpo', '6º ano ao Ensino Médio', 'infográfico moderno e limpo, cartões arredondados em grade com números grandes e ícones de linha, paleta azul, laranja, roxo e verde sobre fundo branco, tipografia sem serifa forte, espaçamento generoso', 'um adolescente recortado em estilo semi-realista, de perfil, sem marca ou personagem conhecido', '{"titulo":34,"subtitulo":90,"bloco_titulo":32,"bloco_texto":260,"item":90,"itens_max":5,"blocos_max":8}', 40, 1),
  ('jovem_cinematografico', 'Jovem cinematográfico', '8º ano ao Ensino Médio', 'estética cinematográfica escura, luz azul e dourada, título enorme com brilho, cartões translúcidos sobre fundo escuro, ícones em linha fina, atmosfera inspiradora de cidade ao entardecer', 'um jovem de costas, estilo animação 3D, olhando o horizonte, sem marca ou personagem conhecido', '{"titulo":34,"subtitulo":90,"bloco_titulo":32,"bloco_texto":240,"item":90,"itens_max":5,"blocos_max":7}', 50, 1),
  ('epico_realista', 'Épico realista', 'Capas, Ensino Médio e eventos', 'pintura digital foto-realista e épica, luz dourada, céu dramático, objetos simbólicos do tema em destaque (livro aberto, globo, instrumentos), título serifado elegante, poucos blocos com fundo semitransparente', 'nenhum personagem; um objeto simbólico do tema em primeiro plano', '{"titulo":30,"subtitulo":80,"bloco_titulo":30,"bloco_texto":200,"item":80,"itens_max":4,"blocos_max":5}', 60, 1)
ON DUPLICATE KEY UPDATE name = VALUES(name), audience = VALUES(audience), description = VALUES(description), mascot = VALUES(mascot), limits_json = VALUES(limits_json), sort_order = VALUES(sort_order);
