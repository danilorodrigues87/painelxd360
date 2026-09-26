# XD360 — Arquitetura

Painel SaaS **independente** para licenciar produtos HTML (topapps).  
Base técnica inspirada no painel-cti (MVC PHP próprio), mas escopo reduzido.

## Escopo ativo

| Área | Rotas |
|------|-------|
| Master | `/master`, `/master/clientes`, `/master/planos`, `/master/assinaturas` |
| Cliente | `/painel`, `/painel/produtos`, `/painel/assinatura`, `/painel/config/escola` |

## Nomenclatura

| Legado (interno) | Significado XD360 |
|------------------|-------------------|
| `clientes_assinantes` | Clientes (tenants) |
| `ClientesAssinantes` | Entity tenant — renomear na fase 2 |
| `DadosCti` / `saas_empresaxd360` | Dados jurídicos XD360 — renomear na fase 2 |
| `ProductModules` | Produtos licenciados (doceflow, fitpro, …) |
| `SystemModules` | Menu do painel cliente |

## Código legado CTI

Arquivos de módulos escola (matrículas, EAD, CRM, WhatsApp) **não estão nas rotas** — podem ser removidos em limpeza futura.

## DB local

- Banco: `xd360`
- Init: `database/xd360/01_init.sql`
- Atualizações e o que falta no servidor online: `docs/BANCO.md`

## Produtos com backend (DoceFlow)

- Repo: `../doceflow` — API + app em `/doceflow/` (Opção A); ponte local `xd360/doceflow/index.php`
- Launch: `/painel/produtos/abrir/{slug}` → token → `DOCEFLOW_PUBLIC_URL/?launch=`
- Exchange: `POST /api/v1/produtos/launch/exchange` (secret `PRODUCT_LAUNCH_SECRET`)
- SQL tokens: `database/xd360/14_produto_launch.sql`
