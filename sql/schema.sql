-- sql/schema.sql
-- Esquema do banco (idempotente: pode ser importado várias vezes no phpMyAdmin).
-- Compatível com MySQL 5.7 (HostGator) e MySQL 8 (cecape-server):
--   * utf8mb4_unicode_ci explícito em toda tabela;
--   * sem CHECK como garantia (a validação fica no PHP);
--   * chaves em VARCHAR com no máximo 191 caracteres.
-- Fase 1: apenas usage_log. As demais tabelas chegam na Fase 2.

SET NAMES utf8mb4;

-- Registro de cada chamada aos motores de IA (custo estimado em US$).
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
