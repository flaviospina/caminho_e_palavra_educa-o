-- sql/schema.sql
-- Esquema do banco (idempotente: pode ser importado várias vezes no phpMyAdmin).
-- Compatível com MySQL 5.7 (HostGator) e MySQL 8 (cecape-server):
--   * utf8mb4_unicode_ci explícito em toda tabela;
--   * sem CHECK como garantia (a validação fica no PHP);
--   * chaves em VARCHAR com no máximo 191 caracteres;
--   * nenhuma função exclusiva do MySQL 8.
-- Tabelas: roles, schools, users, password_resets, consents, bncc_skills, styles,
--          projects, pages, generation_jobs, usage_log, audit_log, quotas.

SET NAMES utf8mb4;

-- ── Perfis de acesso ─────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS roles (
    id          TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug        VARCHAR(30) NOT NULL,                -- professor | coordenador | admin
    name        VARCHAR(60) NOT NULL,
    description VARCHAR(255) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_roles_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Escolas da rede ──────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS schools (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name        VARCHAR(150) NOT NULL,
    short_name  VARCHAR(60) NULL,                    -- nome curto para capas e listas
    inep_code   VARCHAR(12) NULL,                    -- código INEP (opcional)
    city        VARCHAR(80) NOT NULL DEFAULT 'São Caetano do Sul',
    active      TINYINT(1) NOT NULL DEFAULT 1,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_schools_name (name),
    KEY idx_schools_active (active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Usuários ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS users (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    school_id        INT UNSIGNED NULL,               -- admin da Secretaria pode não ter escola
    role_id          TINYINT UNSIGNED NOT NULL,
    name             VARCHAR(120) NOT NULL,
    email            VARCHAR(191) NOT NULL,
    password_hash    VARCHAR(255) NOT NULL,
    active           TINYINT(1) NOT NULL DEFAULT 1,
    must_change_pass TINYINT(1) NOT NULL DEFAULT 0,   -- senha provisória
    failed_attempts  TINYINT UNSIGNED NOT NULL DEFAULT 0,
    locked_until     DATETIME NULL,                   -- bloqueio após 5 tentativas
    last_login_at    DATETIME NULL,
    last_login_ip    VARCHAR(45) NULL,
    anonymized_at    DATETIME NULL,                   -- LGPD: conta excluída/anonimizada
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_email (email),
    KEY idx_users_school (school_id),
    KEY idx_users_role (role_id),
    CONSTRAINT fk_users_school FOREIGN KEY (school_id) REFERENCES schools (id) ON DELETE SET NULL,
    CONSTRAINT fk_users_role FOREIGN KEY (role_id) REFERENCES roles (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Recuperação de senha ─────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS password_resets (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id     INT UNSIGNED NOT NULL,
    token_hash  CHAR(64) NOT NULL,                   -- sha256 do token enviado por e-mail
    expires_at  DATETIME NOT NULL,
    used_at     DATETIME NULL,
    created_ip  VARCHAR(45) NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_resets_token (token_hash),
    KEY idx_resets_user (user_id),
    CONSTRAINT fk_resets_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Aceite do termo de uso (LGPD) ────────────────────────────────────────
CREATE TABLE IF NOT EXISTS consents (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id      INT UNSIGNED NOT NULL,
    term_version VARCHAR(20) NOT NULL,               -- ex.: 2026-10
    accepted_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ip           VARCHAR(45) NOT NULL,
    user_agent   VARCHAR(255) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_consents_user_version (user_id, term_version),
    CONSTRAINT fk_consents_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Base BNCC (dataset aberto bncc-dados, CC BY 4.0) ─────────────────────
CREATE TABLE IF NOT EXISTS bncc_skills (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    code             VARCHAR(20) NOT NULL,            -- EF04CI01, EI03EO01, EM13LGG103
    stage            VARCHAR(20) NOT NULL,            -- EI | EF_ANOS_INICIAIS | EF_ANOS_FINAIS | EM
    years            VARCHAR(40) NOT NULL,            -- anos cobertos, separados por vírgula: "4" | "6,7" | "1,2,3"
    component        VARCHAR(80) NOT NULL,            -- Ciências, Matemática, Língua Portuguesa… (EI: campo de experiência)
    area             VARCHAR(80) NULL,                -- área do conhecimento (EF/EM)
    thematic_unit    VARCHAR(191) NULL,               -- unidade temática / campo de atuação / eixo
    knowledge_object TEXT NULL,                       -- objeto(s) de conhecimento
    description      TEXT NOT NULL,                   -- texto oficial da habilidade
    source           VARCHAR(80) NOT NULL DEFAULT 'bncc-dados',
    extra            JSON NULL,                       -- campos adicionais do dataset
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_bncc_code (code),
    KEY idx_bncc_stage_component (stage, component),
    KEY idx_bncc_years (years)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Estilos visuais (espelho de templates/styles/estilos.json) ───────────
CREATE TABLE IF NOT EXISTS styles (
    id          SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug        VARCHAR(40) NOT NULL,                -- chave: doodle_escolar…
    name        VARCHAR(80) NOT NULL,
    audience    VARCHAR(120) NULL,                   -- público sugerido
    description TEXT NULL,
    mascot      VARCHAR(255) NULL,
    limits_json JSON NULL,                           -- limites de texto por bloco
    sort_order  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    active      TINYINT(1) NOT NULL DEFAULT 1,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_styles_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Projetos (apostila, cartaz, plano de aula) ───────────────────────────
CREATE TABLE IF NOT EXISTS projects (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id          INT UNSIGNED NOT NULL,
    school_id        INT UNSIGNED NULL,
    type             VARCHAR(20) NOT NULL DEFAULT 'apostila',  -- apostila | cartaz | plano
    title            VARCHAR(191) NOT NULL,
    stage            VARCHAR(20) NOT NULL,
    year             VARCHAR(10) NOT NULL,
    component        VARCHAR(80) NOT NULL,
    skill_codes      VARCHAR(255) NOT NULL DEFAULT '',  -- códigos BNCC separados por vírgula (busca)
    theme            VARCHAR(191) NOT NULL,
    purpose          VARCHAR(40) NULL,                 -- finalidade: introducao, revisao…
    style_slug       VARCHAR(40) NULL,
    output_mode      VARCHAR(20) NOT NULL DEFAULT 'imagem',  -- imagem | editavel
    status           VARCHAR(20) NOT NULL DEFAULT 'rascunho', -- rascunho | revisado | gerando | pronto | erro
    content_json     JSON NULL,                        -- conteúdo gerado pela IA (versão original)
    reviewed_json    JSON NULL,                        -- conteúdo revisado pelo professor
    reviewed_at      DATETIME NULL,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    deleted_at       DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_projects_user (user_id),
    KEY idx_projects_school (school_id),
    KEY idx_projects_status (status),
    KEY idx_projects_search (component, year),
    KEY idx_projects_skills (skill_codes(191)),
    CONSTRAINT fk_projects_user FOREIGN KEY (user_id) REFERENCES users (id),
    CONSTRAINT fk_projects_school FOREIGN KEY (school_id) REFERENCES schools (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Páginas de um projeto (capa + páginas) ───────────────────────────────
CREATE TABLE IF NOT EXISTS pages (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    project_id     INT UNSIGNED NOT NULL,
    number         SMALLINT UNSIGNED NOT NULL,          -- 0 = capa
    kind           VARCHAR(20) NOT NULL DEFAULT 'pagina', -- capa | abertura | explicacao | pratica | cartaz
    status         VARCHAR(20) NOT NULL DEFAULT 'pendente', -- pendente | gerando | conferindo | pronto | erro | revisar
    image_path     VARCHAR(255) NULL,                 -- caminho relativo em storage/
    prompt_text    MEDIUMTEXT NULL,
    check_result   JSON NULL,                         -- resultado da conferência automática
    attempts       TINYINT UNSIGNED NOT NULL DEFAULT 0,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_pages_project_number (project_id, number),
    KEY idx_pages_status (status),
    CONSTRAINT fk_pages_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Fila de geração (worker CLI) ─────────────────────────────────────────
CREATE TABLE IF NOT EXISTS generation_jobs (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    project_id    INT UNSIGNED NOT NULL,
    page_id       INT UNSIGNED NULL,
    user_id       INT UNSIGNED NOT NULL,
    job_type      VARCHAR(20) NOT NULL DEFAULT 'imagem', -- imagem | conferencia | ilustracao
    status        VARCHAR(20) NOT NULL DEFAULT 'pendente', -- pendente | gerando | conferindo | pronto | erro
    engine        VARCHAR(40) NULL,
    attempts      TINYINT UNSIGNED NOT NULL DEFAULT 0,
    max_attempts  TINYINT UNSIGNED NOT NULL DEFAULT 3,
    locked_by     VARCHAR(80) NULL,                   -- identificação do worker
    locked_at     DATETIME NULL,
    available_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    started_at    DATETIME NULL,
    finished_at   DATETIME NULL,
    error_message VARCHAR(500) NULL,
    payload       JSON NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_jobs_status (status, available_at),
    KEY idx_jobs_project (project_id),
    KEY idx_jobs_page (page_id),
    CONSTRAINT fk_jobs_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE,
    CONSTRAINT fk_jobs_page FOREIGN KEY (page_id) REFERENCES pages (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Registro de cada chamada aos motores de IA (custo estimado em US$) ───
CREATE TABLE IF NOT EXISTS usage_log (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id         INT UNSIGNED NULL,
    school_id       INT UNSIGNED NULL,
    context         VARCHAR(40)  NOT NULL DEFAULT 'poc',   -- poc, apostila, cartaz, verificacao...
    reference       VARCHAR(120) NULL,                      -- id da execução/projeto/página
    engine          VARCHAR(40)  NOT NULL,                  -- gemini, openai, mock
    model           VARCHAR(80)  NOT NULL,
    resolution      VARCHAR(20)  NULL,                      -- 1024x1536, 2K...
    prompt_chars    INT UNSIGNED NOT NULL DEFAULT 0,
    input_tokens    INT UNSIGNED NULL,
    output_tokens   INT UNSIGNED NULL,
    duration_ms     INT UNSIGNED NOT NULL DEFAULT 0,
    cost_usd        DECIMAL(10,5) NOT NULL DEFAULT 0,
    status          VARCHAR(10)  NOT NULL DEFAULT 'ok',     -- ok | erro
    error_message   VARCHAR(500) NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_usage_user (user_id),
    KEY idx_usage_school (school_id),
    KEY idx_usage_engine (engine, model),
    KEY idx_usage_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Auditoria: quem, quando, o quê ───────────────────────────────────────
CREATE TABLE IF NOT EXISTS audit_log (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id     INT UNSIGNED NULL,                   -- NULL = sistema / anônimo
    action      VARCHAR(60) NOT NULL,                -- login, login_falhou, usuario_criado…
    entity      VARCHAR(40) NULL,                    -- users, schools, projects…
    entity_id   INT UNSIGNED NULL,
    details     TEXT NULL,                           -- JSON com o que mudou (sem senhas)
    ip          VARCHAR(45) NULL,
    user_agent  VARCHAR(255) NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_audit_user (user_id),
    KEY idx_audit_action (action),
    KEY idx_audit_entity (entity, entity_id),
    KEY idx_audit_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Cotas mensais (por escola e por professor) ───────────────────────────
CREATE TABLE IF NOT EXISTS quotas (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    scope          VARCHAR(10) NOT NULL,             -- escola | usuario | rede
    school_id      INT UNSIGNED NULL,
    user_id        INT UNSIGNED NULL,
    period         CHAR(7) NOT NULL,                 -- AAAA-MM
    max_images     INT UNSIGNED NOT NULL DEFAULT 0,  -- 0 = sem limite
    max_cost_usd   DECIMAL(10,2) NOT NULL DEFAULT 0, -- 0 = sem limite
    used_images    INT UNSIGNED NOT NULL DEFAULT 0,
    used_cost_usd  DECIMAL(10,5) NOT NULL DEFAULT 0,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_quotas_scope (scope, school_id, user_id, period),
    KEY idx_quotas_period (period)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
