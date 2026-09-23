-- Tokens one-time: painel XD360 → produto (DoceFlow, etc.)
-- mysql -u root xd360 < database/xd360_produto_launch.sql

USE xd360;

CREATE TABLE IF NOT EXISTS produto_launch_tokens (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  token_hash CHAR(64) NOT NULL,
  tenant_id INT UNSIGNED NOT NULL COMMENT 'clientes_assinantes.id',
  id_usuario INT UNSIGNED NOT NULL,
  produto_slug VARCHAR(40) NOT NULL,
  expira_em DATETIME NOT NULL,
  usado_em DATETIME NULL DEFAULT NULL,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_produto_launch_hash (token_hash),
  KEY idx_produto_launch_exp (expira_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
