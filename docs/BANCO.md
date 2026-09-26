# Banco de dados — XD360

Os scripts deste projeto ficam em `database/xd360/`, numerados na ordem de aplicação.  
Os `.sql` na raiz de `database/` vieram do painel CTI e não entram no servidor do XD360.

## Servidor online

O ambiente local já está nesta versão. No phpMyAdmin do banco de produção, cole e execute:

**`database/xd360/16_aceite_cep_chamados.sql`** — aceite do contrato, CEP da empresa, chamados e mais de uma fatura no mês.

**`database/xd360/17_perfil_endereco.sql`** — CEP, cidade e UF no perfil do usuário. Aplicado no banco local em 26/09/2026.

Não apaga dados. Se uma linha disser que a coluna já existe, siga para a próxima.

Se o online ainda não tiver contratos por produto (`saas_contratos`), rode antes o `13_planos_produto.sql` e, se a tabela jurídica não existir, o `11_saas_empresaxd360.sql`.

## Regra

Toda alteração de tabela ganha o próximo número em `database/xd360/` (hoje o último é 17) e fica anotada aqui até o online ser atualizado.
