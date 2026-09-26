# XD360 Painel — deploy

Repositório: `https://github.com/danilorodrigues87/painelxd360.git`

## Git (PowerShell)

```powershell
cd C:\xampp\htdocs\pjt\xd360
git init
git remote add origin https://github.com/danilorodrigues87/painelxd360.git
git add .
git commit --trailer "Co-authored-by: Cursor <cursoragent@cursor.com>" -m "Painel XD360"
git branch -M main
git push -u origin main
```

## Servidor

| Host | DocumentRoot |
|------|----------------|
| `app.xd360.com.br` | raiz do projeto xd360 |

```bash
composer install --no-dev
```

## MySQL

Importar banco xd360 (dump local ou `database/xd360/01_init.sql`).
Obrigatório: `mysql -u USER -p xd360 < database/xd360/14_produto_launch.sql`

Banco que já está no ar: aplicar só o arquivo pendente em [BANCO.md](BANCO.md). Hoje: `database/xd360/16_aceite_cep_chamados.sql`.

## `.env` produção

URL=https://app.xd360.com.br
DOCEFLOW_PUBLIC_URL=https://doceflow.xd360.com.br
XD360_MASTER_URL=https://app.xd360.com.br
JWT_KEY e PRODUCT_LAUNCH_SECRET iguais ao doceflow.

## cPanel Git deploy

- Ajuste `DEPLOYPATH` em `.cpanel.yml` (somente este domínio).
- `.env` manual no servidor; rode `composer install --no-dev`.
- Erro 500: `docs/TROUBLESHOOTING_CPANEL.md` e `scripts/verificar-instalacao.php`.

## Testes

Login + Produtos → Abrir app
