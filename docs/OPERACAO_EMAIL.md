# Operação de e-mail — Checklist de produção

Checklist do **item 1**: deixar e-mail estável em local e produção.  
Não envia nada por si só — são passos manuais + crons.

---

## A) Dependências Composer

No diretório do projeto:

```bash
composer update
```

Se falhar por advisory do `firebase/php-jwt`, o `composer.json` já deve pedir versão segura (`^6.11` ou superior). Depois confira:

```bash
# Não deve listar netflie/whatsapp-cloud-api
composer show | findstr netflie
```

(Windows PowerShell: `composer show | Select-String netflie` — sem resultado = OK)

---

## B) Diagnóstico rápido (não envia e-mail)

```bash
php worker/status-email.php
php worker/status-email.php 1
```

Troque `1` pelo `id_admin` da escola.  
Esperado: `"pronto_para_workers": true`.

Se faltar tabela, rode o SQL em `ARCHITECTURE.md` (seção migrações de e-mail).

---

## C) `.env` do servidor

Confirme no `.env` (raiz do projeto):

| Variável | Uso |
|----------|-----|
| `APP_KEY` | Criptografia da senha SMTP da escola (**recomendado**; sem ela usa `SYSTEM_TOKEN`) |
| `SMTP_*` | E-mail padrão do sistema (recovery / fallback) |
| `DB_*` | Banco |
| `TIMEZONE=America/Sao_Paulo` | Datas de vencimento / cobrança |

Reinicie Apache após alterar `.env` (XAMPP: Stop/Start Apache).

---

## D) Painel — Configurações → Comunicação (como Diretor)

1. SMTP da escola salvo e **teste** para o **seu** e-mail
2. Cobrança automática: configure dias (ex.: antes `3,5` / depois `1,3,7`)
3. **Auditar contatos** → corrigir e-mails fictícios e WhatsApp inválido
4. **Simular hoje** → ver lista (não envia)
5. Só quando estiver certo: marcar cobrança **ativa**, **Salvar**, e usar **Enviar agora** ou o cron

---

## E) Cron / Agendador

### HostGator / cPanel (produção — recomendado)

No **Cron Jobs** do cPanel, use URL HTTP com `SYSTEM_TOKEN` do `.env` (não depende de login no painel):

```cron
# Campanhas (e-mail + WhatsApp) — a cada minuto (limite=1 evita timeout e respeita pacing WA)
* * * * * curl -fsS "https://SEU-DOMINIO-PAINEL/cron/campanhas?token=SEU_SYSTEM_TOKEN&limite=1" >/dev/null 2>&1

# Social (Facebook/Instagram) — a cada 5 minutos
*/5 * * * * curl -fsS "https://SEU-DOMINIO-PAINEL/cron/social?token=SEU_SYSTEM_TOKEN" >/dev/null 2>&1

# Cobrança automática — todo dia às 08:00
0 8 * * * curl -fsS "https://SEU-DOMINIO-PAINEL/cron/cobranca?token=SEU_SYSTEM_TOKEN" >/dev/null 2>&1

# Aniversariantes — todo dia às 08:05
5 8 * * * curl -fsS "https://SEU-DOMINIO-PAINEL/cron/aniversario?token=SEU_SYSTEM_TOKEN" >/dev/null 2>&1
```

Alternativa CLI (se o hosting permitir `php` no cron):

```cron
* * * * * /usr/bin/php /home/USUARIO/painel-cti/worker/campanhas.php 0 1 >> /home/USUARIO/logs/cti-campanhas.log 2>&1
*/5 * * * * /usr/bin/php /home/USUARIO/painel-cti/worker/social.php >> /home/USUARIO/logs/cti-social.log 2>&1
```

Sem esses crons, a fila só anda com o painel aberto (`campanha-heartbeat.js`) ou com a agenda social aberta.

### Linux (produção)

```cron
# Campanhas — a cada minuto
* * * * * /usr/bin/php /var/www/painel-cti/worker/campanhas.php >> /var/log/cti-campanhas.log 2>&1

# Cobrança — todo dia às 08:00
0 8 * * * /usr/bin/php /var/www/painel-cti/worker/cobranca.php >> /var/log/cti-cobranca.log 2>&1

# Aniversariantes — todo dia às 08:05
5 8 * * * /usr/bin/php /var/www/painel-cti/worker/aniversario.php >> /var/log/cti-aniversario.log 2>&1
```

Ajuste o caminho do PHP (`which php`) e do projeto.

### Windows / XAMPP (Agendador de Tarefas)

1. Abrir **Agendador de Tarefas** → Criar Tarefa Básica
2. Ação: Iniciar programa  
   - Programa: `C:\xampp\php\php.exe`  
   - Argumentos (campanhas, a cada 5 min em teste):  
     `C:\xampp\htdocs\pjt\painel-cti\worker\campanhas.php`  
   - Argumentos (cobrança, 1x/dia 08:00):  
     `C:\xampp\htdocs\pjt\painel-cti\worker\cobranca.php`
3. Iniciar em: `C:\xampp\htdocs\pjt\painel-cti`

Sem cron, use no painel:
- Campanhas → **Processar fila agora**
- Comunicação → **Enviar agora** (cobrança)

---

## F) Teste seguro (sem spammar alunos)

| Passo | Envia para alunos? |
|-------|--------------------|
| `status-email.php` | Não |
| Auditar contatos | Não |
| Simular hoje | Não |
| E-mail de teste (campo SMTP) | Só o endereço que você digitar |
| Enviar agora / worker cobrança com switch ativo | **Sim** |
| Processar fila de campanha em status `enviando` | **Sim** |

Recomendação: deixe cobrança **desativada** até a simulação fazer sentido; ative só depois.

---

## G) Critérios de “item 1 concluído”

- [ ] `composer update` OK e sem `netflie` no vendor
- [ ] `php worker/status-email.php` → pronto
- [ ] SMTP sistema **ou** escola testado com sucesso
- [ ] Auditoria rodada; fakes críticos corrigidos (ou aceitos conscientes)
- [ ] Simular hoje funciona
- [ ] Cron/agendador configurado **ou** processo manual documentado na escola
- [ ] Cobrança ativa só após validação

---

## Comandos úteis

```bash
php worker/status-email.php 1
php worker/campanhas.php
php worker/campanhas.php 1 10
php worker/cobranca.php
php worker/cobranca.php 1
php worker/aniversario.php
php worker/aniversario.php 1
```

Teste cron HTTP (substitua token):

```bash
curl -fsS "https://SEU-DOMINIO-PAINEL/cron/cobranca?token=SEU_SYSTEM_TOKEN"
curl -fsS "https://SEU-DOMINIO-PAINEL/cron/aniversario?token=SEU_SYSTEM_TOKEN"
```

SQL da Fase 6 (log de execuções): `database/comunicacao_fase6.sql`
