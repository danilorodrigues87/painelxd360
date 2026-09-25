-- XD360 — Schema inicial
-- Banco: xd360 | Execute: mysql -u root xd360 < database/xd360_init.sql

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- Tenants (clientes licenciados)
CREATE TABLE IF NOT EXISTS clientes_assinantes (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_admin INT UNSIGNED NOT NULL DEFAULT 0,
  ativo CHAR(1) NOT NULL DEFAULT 's',
  nome VARCHAR(255) NOT NULL DEFAULT '',
  cpf_cnpj VARCHAR(20) NOT NULL DEFAULT '',
  email VARCHAR(255) NOT NULL DEFAULT '',
  site VARCHAR(255) NOT NULL DEFAULT '',
  logo VARCHAR(255) NOT NULL DEFAULT '',
  instagram VARCHAR(120) NULL DEFAULT NULL,
  telefone VARCHAR(40) NOT NULL DEFAULT '',
  youtube VARCHAR(255) NULL DEFAULT NULL,
  endereco VARCHAR(255) NOT NULL DEFAULT '',
  numero VARCHAR(20) NOT NULL DEFAULT '',
  bairro VARCHAR(120) NOT NULL DEFAULT '',
  estado INT UNSIGNED NOT NULL DEFAULT 0,
  cidade INT UNSIGNED NOT NULL DEFAULT 0,
  cep VARCHAR(12) NOT NULL DEFAULT '',
  modulos_liberados JSON NULL DEFAULT NULL,
  plan_id INT UNSIGNED NULL DEFAULT NULL,
  valor_mensal_custom DECIMAL(10,2) NULL DEFAULT NULL,
  dia_vencimento_assinatura TINYINT UNSIGNED NOT NULL DEFAULT 10,
  assinatura_status VARCHAR(20) NOT NULL DEFAULT 'trial',
  trial_ate DATE NULL DEFAULT NULL,
  assinatura_proximo_vencimento DATE NULL DEFAULT NULL,
  slug VARCHAR(80) NULL DEFAULT NULL,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_slug (slug),
  KEY idx_ativo (ativo),
  KEY idx_plan (plan_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Planos SaaS
CREATE TABLE IF NOT EXISTS planos_assinatura (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nome VARCHAR(120) NOT NULL,
  produto_slug VARCHAR(40) NULL DEFAULT NULL,
  descricao TEXT NULL,
  descricao_detalhada TEXT NULL,
  valor_mensal DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  ciclo VARCHAR(12) NOT NULL DEFAULT 'mensal',
  valor_sugerido DECIMAL(10,2) NULL DEFAULT NULL,
  modulos JSON NULL COMMENT 'módulos internos do produto',
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  ordem INT NOT NULL DEFAULT 0,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Faturas SaaS
CREATE TABLE IF NOT EXISTS saas_faturas (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_admin INT UNSIGNED NOT NULL,
  plan_id INT UNSIGNED NULL DEFAULT NULL,
  competencia CHAR(7) NOT NULL COMMENT 'YYYY-MM',
  valor DECIMAL(10,2) NOT NULL,
  vencimento DATE NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'aberta' COMMENT 'aberta,pago,cancelada,vencida',
  mp_payment_id VARCHAR(64) NULL DEFAULT NULL,
  pix_copia_cola TEXT NULL,
  pix_qr_base64 MEDIUMTEXT NULL,
  email_enviado_em DATETIME NULL DEFAULT NULL,
  pago_em DATETIME NULL DEFAULT NULL,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  atualizado_em DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_saas_escola_comp (id_admin, competencia),
  KEY idx_saas_status_venc (status, vencimento)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Usuários
CREATE TABLE IF NOT EXISTS usuarios (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nome VARCHAR(191) NOT NULL,
  email VARCHAR(191) NOT NULL,
  senha VARCHAR(255) NOT NULL,
  nivel VARCHAR(40) NOT NULL DEFAULT 'Diretor',
  id_admin INT UNSIGNED NOT NULL DEFAULT 0,
  ativo CHAR(1) NOT NULL DEFAULT 's',
  whatsapp VARCHAR(20) NOT NULL DEFAULT '',
  id_responsavel INT UNSIGNED NOT NULL DEFAULT 0,
  foto VARCHAR(255) NULL DEFAULT NULL,
  acesso TEXT NULL COMMENT 'JSON labels permissoes',
  termos_uso TINYINT(1) NOT NULL DEFAULT 1,
  termos_aceito_em DATETIME NULL DEFAULT NULL,
  termos_versao VARCHAR(20) NULL DEFAULT NULL,
  rg VARCHAR(20) NOT NULL DEFAULT '',
  cpf VARCHAR(20) NOT NULL DEFAULT '',
  nascimento DATE NULL DEFAULT NULL,
  endereco VARCHAR(255) NOT NULL DEFAULT '',
  numero VARCHAR(20) NOT NULL DEFAULT '',
  bairro VARCHAR(120) NOT NULL DEFAULT '',
  uf INT UNSIGNED NOT NULL DEFAULT 0,
  cidade INT UNSIGNED NOT NULL DEFAULT 0,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_email (email),
  KEY idx_id_admin (id_admin)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dados jurídicos XD360 (licenciante)
CREATE TABLE IF NOT EXISTS saas_empresaxd360 (
  id TINYINT UNSIGNED NOT NULL DEFAULT 1,
  razao_social VARCHAR(255) NOT NULL DEFAULT 'XD360 Tecnologia',
  nome_fantasia VARCHAR(120) NOT NULL DEFAULT 'XD360',
  cnpj VARCHAR(18) NULL DEFAULT NULL,
  endereco VARCHAR(255) NULL DEFAULT NULL,
  numero VARCHAR(20) NULL DEFAULT NULL,
  bairro VARCHAR(120) NULL DEFAULT NULL,
  cep VARCHAR(12) NULL DEFAULT NULL,
  estado INT UNSIGNED NULL DEFAULT NULL,
  cidade INT UNSIGNED NULL DEFAULT NULL,
  email VARCHAR(120) NULL DEFAULT 'contato@xd360.com.br',
  telefone VARCHAR(40) NULL DEFAULT NULL,
  site VARCHAR(255) NULL DEFAULT NULL,
  rep_legal_usuario_id INT UNSIGNED NULL DEFAULT NULL,
  rep_nome VARCHAR(191) NULL DEFAULT NULL,
  rep_cpf VARCHAR(14) NULL DEFAULT NULL,
  rep_rg VARCHAR(20) NULL DEFAULT NULL,
  rep_cargo VARCHAR(120) NULL DEFAULT 'Administrador',
  foro_comarca VARCHAR(191) NULL DEFAULT NULL,
  atualizado_em DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Contrato SaaS
CREATE TABLE IF NOT EXISTS saas_contrato_modelo (
  id TINYINT UNSIGNED NOT NULL DEFAULT 1,
  html MEDIUMTEXT NULL,
  atualizado_em DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Chamados suporte
CREATE TABLE IF NOT EXISTS chamados (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_admin INT UNSIGNED NOT NULL DEFAULT 0,
  usuario_id INT UNSIGNED NOT NULL DEFAULT 0,
  assunto VARCHAR(255) NOT NULL DEFAULT '',
  status VARCHAR(30) NOT NULL DEFAULT 'aberto',
  prioridade VARCHAR(20) NOT NULL DEFAULT 'normal',
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  atualizado_em DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_chamados_admin (id_admin)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS chamado_mensagens (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  chamado_id INT UNSIGNED NOT NULL,
  usuario_id INT UNSIGNED NOT NULL DEFAULT 0,
  mensagem TEXT NOT NULL,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_chamado (chamado_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Estados/cidades (opcional — formulário de endereço)
CREATE TABLE IF NOT EXISTS estados (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nome VARCHAR(80) NOT NULL,
  uf CHAR(2) NOT NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cidades (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  estados_id INT UNSIGNED NOT NULL,
  nome VARCHAR(120) NOT NULL,
  PRIMARY KEY (id),
  KEY idx_estado (estados_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- Dados iniciais
INSERT IGNORE INTO saas_empresaxd360 (id, razao_social, nome_fantasia, email, rep_cargo)
VALUES (1, 'XD360 Tecnologia', 'XD360', 'contato@xd360.com.br', 'Administrador');

INSERT IGNORE INTO saas_contrato_modelo (id, html) VALUES (1, NULL);

INSERT INTO planos_assinatura (nome, produto_slug, descricao, valor_mensal, ciclo, valor_sugerido, modulos, ativo, ordem) VALUES
('DoceFlow Mensal', 'doceflow', 'Licença DoceFlow, cobrança mensal', 49.00, 'mensal', 49.00, '[]', 1, 1),
('DoceFlow Anual', 'doceflow', 'Licença DoceFlow por 12 meses. Ajuste o valor sugerido.', 0.00, 'anual', 0.00, '[]', 1, 2);

-- Master admin (senha: admin123)
INSERT INTO usuarios (nome, email, senha, nivel, id_admin, ativo) VALUES
('Administrador XD360', 'admin@xd360.com.br', '$2y$10$xoTk1KodMKnWdXc1IXDTRefY1ZE07sjknwLrvJITeRwG0eN2hw0F2', 'Diretor', 0, 's');
