# WhatsApp / Evolution API — operação

## Variáveis no `.env`

```env
EVOLUTION_URL=https://sua-evolution.exemplo.com
EVOLUTION_API_KEY=sua_chave
EVOLUTION_WEBHOOK_SECRET=gere_um_segredo_longo
```

## SQL

1. Bloco **WhatsApp / Evolution** em `ARCHITECTURE.md` (se ainda não rodou)
2. Bloco **WhatsApp inbox + setores + chatbot (Fase 3b)**
3. **Menu inicial:** `database/whatsapp_menu_config.sql`

## Menu inicial (Comunicação)

Em **Comunicação → WhatsApp → Menu inicial**:

| Opção | Efeito |
|-------|--------|
| **Enviar menu automático** | Liga/desliga menu na 1ª mensagem e fluxos `primeira_msg` / `saudacao` |
| **Permitir menu manual** | Cliente digita `menu` (ou palavras configuradas) para ver setores |
| **Textos** | Abertura, rodapé, opção inválida, palavras-chave |

Com o automático **desligado**, a 1ª mensagem fica **silenciosa** (só aparece no Inbox). Setores continuam em **WhatsApp → Setores e atendentes**.

## Plano / módulo

- Slug: `whatsapp` · Label: `WhatsApp`
- Diretor: acesso automático
- Funcionários: marcar **WhatsApp** nas permissões + liberar slug na escola se `modulos_liberados` for lista explícita

Exemplo (escola com lista explícita):

```sql
-- Ajuste o JSON conforme os módulos já liberados
UPDATE clientes_assinantes
SET modulos_liberados = JSON_ARRAY_APPEND(COALESCE(modulos_liberados, JSON_ARRAY()), '$', 'whatsapp')
WHERE id = SEU_ID_ADMIN;
```

(Se `modulos_liberados` for `NULL`, todos os módulos já estão liberados.)

## Fluxo

1. **Comunicação** → conectar QR do número da escola  
2. **WhatsApp** → aba *Setores e atendentes* → vincular usuários  
3. Cliente manda mensagem → bot envia menu (1 Comercial, 2 Financeiro…)  
4. Cliente escolhe → conversa vai para a **fila do setor**  
5. Atendente **Assumir** → responde no inbox  

## Pacing anti-bloqueio (campanhas)

- Intervalo 1:1: mínimo **30s**, padrão **60s**, com jitter ±20% a cada envio.
- Máx./hora: padrão **20**.
- Grupos/listas: intervalo em minutos (padrão 60) + jitter ±20% em `agendada_para`.
- Ao **Iniciar** campanha WhatsApp 1:1, o worker já aplica delay (sem rajada).
- Opcional: **variar textos** (`whatsapp_variar_texto`) usa a IA de Configurações de IA + fallback local.
- SQL: `database/whatsapp_anti_ban.sql` (`whatsapp_variar_texto`, `campanha_fila.mensagem_enviada`).

Configurar limites em **Comunicação**; toggle de variação também em **Configurações de IA**.

## Multi-número (futuro)

Tabela `whatsapp_numeros` já existe; hoje sincroniza 1 registro *Principal* ao conectar.

## Mídia (emoji, imagem, áudio)

- Emoji: digite no campo ou use a barra rápida do inbox (UTF-8 / utf8mb4).
- Imagem: ícone de imagem no inbox (envia com legenda opcional do texto).
- Áudio: microfone (grava e envia) ou escolha de arquivo se o navegador bloquear o mic.
- Recebimento: webhook com `base64=true`; arquivos em `uploads/whatsapp/{id_admin}/...`.
- Após atualizar o código, clique **Conectar / QR** uma vez em Comunicação para reaplicar o webhook com base64.

## Cron de campanhas WhatsApp (produção)

Campanhas WA usam a mesma fila de e-mail. Com o painel fechado, configure no **cPanel → Cron Jobs** (a cada 1 minuto):

```cron
* * * * * curl -fsS "https://SEU-DOMINIO-PAINEL/cron/campanhas?token=SEU_SYSTEM_TOKEN&limite=1" >/dev/null 2>&1
```

- `SYSTEM_TOKEN` deve estar no `.env` do painel (mesmo valor usado na URL).
- `limite=1` evita timeout no HostGator e respeita o pacing 1:1 do WhatsApp.
- Alternativa CLI: `* * * * * php /caminho/painel-cti/worker/campanhas.php 0 1`
- Execute `database/campanha_worker_runs.sql` no phpMyAdmin para registrar histórico (visível na tela Campanhas).
- Teste no navegador (logado ou não): `https://SEU-DOMINIO-PAINEL/cron/campanhas?token=SEU_TOKEN&limite=1` — deve retornar JSON com `"success": true`.

Detalhes em `docs/OPERACAO_EMAIL.md` (seção Cron / HostGator).
