# XD360 — erro 500 / página em branco (cPanel)

## Sintoma

`https://app.xd360.com.br` → **HTTP 500** (Chrome: "Esta página não está funcionando").

## Causas mais comuns

### 1. Pasta `vendor/` ausente

O Git **não** envia `vendor/`. No Terminal cPanel:

```bash
cd ~/app.xd360.com.br
/usr/local/bin/ea-php81 /usr/local/bin/composer install --no-dev
```

(Se `composer` estiver no PATH, use `composer install --no-dev`.)

### 2. Arquivo `.env` ausente ou inválido

O deploy **não** copia `.env`. Crie/edite em `~/app.xd360.com.br/.env`.

- **Uma linha por variável** — não deixe `JWT_KEY` duplicado.
- Remova linhas de exemplo tipo `JWT_KEY=<gere uma chave longa>`.
- Inclua `SITE=XD360`.

Exemplo mínimo:

```env
URL=https://app.xd360.com.br
SITE=XD360
DB_HOST=localhost
DB_USER=...
DB_PASS=...
DB_NAME=...

JWT_KEY=chave_longa_unica
PRODUCT_LAUNCH_SECRET=outra_chave_longa
APP_KEY=...
SYSTEM_TOKEN=...

DOCEFLOW_PUBLIC_URL=https://doceflow.xd360.com.br
XD360_BASE_DOMAIN=xd360.com.br
XD360_MASTER_HOST=app.xd360.com.br
XD360_MASTER_URL=https://app.xd360.com.br
TIMEZONE=America/Sao_Paulo
MAINTENANCE=false
```

### 3. `.htaccess` não foi copiado

O comando `cp -R *` **ignora** arquivos que começam com `.`. O `.cpanel.yml` atual também copia `.htaccess`. Se faltar, copie manualmente do repositório.

### 4. MySQL

Usuário cPanel precisa estar **associado** ao banco (`dncurs82_xd360`). Importe o dump ou rode `database/xd360_init.sql`.

### 5. Permissões

`app/sessions/` deve existir e ser gravável (755 ou 775).

## Diagnóstico rápido

Após deploy, acesse **uma vez**:

`https://app.xd360.com.br/scripts/verificar-instalacao.php`

Remova o arquivo depois.

## Logs

cPanel → **Errors** / `error_log` na pasta do domínio — mensagem `Fatal error` ou `failed to open stream: vendor/autoload.php`.

## `.cpanel.yml`

Confirme que `DEPLOYPATH` é **somente** `app.xd360.com.br`. Apontar para pasta de outro site sobrescreve arquivos.
