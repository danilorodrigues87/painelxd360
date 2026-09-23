-- XD360 — domínios por cliente (subdomínio + custom)
USE xd360;

ALTER TABLE clientes_assinantes
  ADD COLUMN IF NOT EXISTS dominio_custom VARCHAR(255) NULL DEFAULT NULL AFTER slug,
  ADD COLUMN IF NOT EXISTS dominio_verificado TINYINT(1) NOT NULL DEFAULT 0 AFTER dominio_custom;

ALTER TABLE planos_assinatura
  ADD COLUMN IF NOT EXISTS permite_dominio_custom TINYINT(1) NOT NULL DEFAULT 0 AFTER ordem;

UPDATE planos_assinatura SET permite_dominio_custom = 1 WHERE nome IN ('Business', 'Enterprise') OR valor_mensal >= 149;
