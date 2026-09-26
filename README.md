# XD360 — Painel de Controle SaaS

Painel multi-tenant para licenciar e gerenciar os produtos HTML do [topapps](../topapps/projetoshtml).

Projeto **independente** (MVC PHP). Inspira-se na arquitetura do painel-cti, mas com escopo XD360: clientes, planos, assinaturas e produtos topapps.

Documentação: [ARCHITECTURE_XD360.md](ARCHITECTURE_XD360.md) · Banco: [docs/BANCO.md](docs/BANCO.md)

## Requisitos

- PHP 8+
- MySQL/MariaDB (XAMPP)
- Composer

## Instalação local

1. Banco `xd360` criado no phpMyAdmin
2. Importar schema: `database/xd360/01_init.sql`
3. Copiar `.env.example` → `.env` (já configurado para localhost)
4. `composer install`

## Acesso

- URL: http://localhost/pjt/xd360
- Master: `admin@xd360.com.br` / `admin123`
- Configure seu e-mail em `MASTER_EMAILS` no `.env`

## Estrutura

| Rota | Função |
|------|--------|
| `/` | Login |
| `/master` | Dashboard SaaS |
| `/master/escolas` | Clientes (tenants) |
| `/master/planos` | Planos e produtos |
| `/master/assinaturas` | Faturas e cobrança |
| `/painel` | Dashboard do cliente |
| `/painel/produtos` | Produtos licenciados |
| `/painel/assinatura` | Pagamento |

## Produtos (topapps)

Slugs em `app/Common/ProductModules.php`. URLs configuradas no `.env` (`TOPAPPS_BASE`, `PRODUCT_*`).
