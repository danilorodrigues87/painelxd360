# ARCHITECTURE.md — Contexto completo do Painel CTI

> **Público-alvo:** desenvolvedores humanos e **agentes de IA** (Cursor, VS Code Copilot/Continue, etc.).  
> Leia este arquivo **antes** de alterar o código. Preferir seguir os padrões já existentes a inventar novos.

**Última atualização:** 2026-09-09 (Marketing UX Fases 6–10)  
**Repo:** `painel-cti`  
**DB local XAMPP:** `cti_admin` (produção: conforme `.env`)  
**Linguagem:** PHP (MVC próprio) · Ambiente: XAMPP local + Linux produção  
**Estilo:** segurança e performance · Migração de upload manual → Git em andamento

---

## 1. Visão geral do produto

Painel administrativo multi-tenant para **escolas** (assinantes). Cada escola é isolada por `id_admin`.  
Há um **Painel Master** em `/master`: escolas, planos (com valor mensal) e **Assinaturas** (cobrança SaaS via PIX da conta CTI).  
O Diretor da escola paga a mensalidade do painel em **Financeiro → Assinatura** (`/painel/assinatura`).

```
Painel Master (/master) — e-mails em MASTER_EMAILS (.env)
        ↓ libera módulos (modulos_liberados)
clientes_assinantes (tenant = id = id_admin)
        ↓
usuarios (acesso JSON ∩ modulos_liberados da escola)
        ↓
dados pedagógicos / financeiros / CRM / agenda / comunicação
```

---

## 2. Stack e bootstrap

| Peça | Onde |
|------|------|
| Entrada | `index.php` → `includes/app.php` |
| Autoload | Composer PSR-4: `App\` → `app/` |
| Env | `App\Common\Environment` lê `.env` |
| DB | `App\Model\Db\Database` (PDO MySQL, `DB_*` do `.env`) |
| Views | `App\Utils\View` + templates em `resources/view/` |
| Rotas | `routes/admin.php` inclui arquivos em `routes/admin/*` |
| Sessão | `App\Session\User\Login` — chave `$_SESSION['usuario-mvc-1']` |
| Front | Bootstrap 5.2, jQuery, SweetAlert2, Font Awesome · tema claro/escuro (`panel-theme.js` + `data-bs-theme`) |

**Constante `URL`:** em `includes/app.php` — host/path alinhados ao request e à pasta do `index.php` (`SCRIPT_NAME`). Sem barra final. O `.env` `URL` deve ser a URL pública (HTTPS em produção); path errado no `.env` é corrigido pelo path real do deploy.

**Router:** o prefixo de rotas vem de `dirname(SCRIPT_NAME)` (onde o app está montado), **não** do path do `.env`. Assim local (`/pjt/painel-cti`) e produção (raiz ou subpasta) resolvem `/`, `/privacidade`, `/painel` sem gambiarra.

**CORS API aluno:** só Origins em `STUDENT_CORS_ORIGINS` (+ localhost em dev). **Não** ecoar Origin arbitrário. Headers em `CorsStudent` + `Router` — **não** no `index.php`.

**AJAX obrigatório:** sempre prefixar com `url_base` (`resources/js/url-base.js`). Sem isso, em subpastas XAMPP as rotas quebram.

**Tema UI (escola + Master):** toggle no dropdown do usuário; preferência em `localStorage` chave `painel-cti-theme` (`light`|`dark`); `html[data-bs-theme]` + CSS `resources/css/panel-theme.css`. Login e portal do aluno não usam este toggle.

---

## 2.1 Para IAs / higiene (obrigatório)

- Regras Cursor: `.cursor/rules/painel-cti.mdc` + `.cursorrules`.
- **Branding:** textos visíveis = **portal do aluno** / CTI Educacional. Nunca “Ascend”/“Aurora” na UI. Pasta `ascend-academy` é só nome técnico.
- **Testes temporários:** `_test_*.php`, `_diag_*.php`, `_curl_*`, dumps, `#region agent log` — apagar ao terminar a tarefa.
- **Docs:** ao concluir feature/fase, atualizar este `ARCHITECTURE.md` (e checklist SQL se houver).
- Sem commit Git sem pedido explícito do usuário.

### Backlog pós-auditoria (jul/2026)

1. ~~**Fase 1 segurança:**~~ **Feito (2026-07-28):** leftovers `_diag_*`/`_curl_*` removidos; CORS allowlist em `CorsStudent`; `Database` sem `die()` PDO ao cliente; `CryptoHelper` sem fallback previsível; `.gitignore` dumps.
2. ~~**Dashboard Master rico:**~~ **Feito (2026-07-28):** `/master` com KPIs SaaS (trial, suspensas, faturas, receita), escolas recentes, lista Atenção e atalhos.
3. ~~**Shells CRUD Admin + layout alinhado ao Master:**~~ **Feito (2026-07-28):** sidenav irmão do content (`container-fluid`); H1/breadcrumb nas listas principais; API Testimony + stub `/users/me` removidos; JS “Copia” apagados.
4. Portal: certificados só visualizar (sem baixar) — intencional.

---

## 3. Multi-tenant e permissões

### Tenant
- Tabela de escolas: **`clientes_assinantes`** (antes: `empresas`)
- Entity: `App\Model\Entity\ClientesAssinantes`
- Sessão: `['escola']` (não mais `['empresa']`)
- Helper: `TenantHelper::getIdAdmin()`, `pertenceEscola()`, etc.

### Módulos (Fase 0 — feita)
- Catálogo com **slugs** + labels: `App\Common\SystemModules`
- Interseção escola ∩ usuário: `App\Common\Helpers\ModuleGateHelper`
- Coluna `clientes_assinantes.modulos_liberados` (JSON de slugs). `NULL` = todos liberados
- Menu / `getPanel()` / checkboxes de funcionários / sync de sessão usam módulos efetivos
- Alias legado: label `Laboratório` → `Agendamentos`

### Níveis de usuário
- Exemplos: `Diretor`, `Financeiro`, `Cliente` (aluno), etc.
- Telas **Comunicação** e **Campanhas**: acesso automático para `Diretor` em `Page::getPanel` (Comunicação não entra mais no checklist de funcionários — só Diretor).
- **Redes sociais:** **não** é auto-grant do Diretor — exige label `Redes sociais` em `usuarios.acesso` (interseção com o plano). **Conexão Meta** (submenu Config) aparece só se Diretor **e** já tem `Redes sociais`.
- Catálogo de permissões: removidos mortos `Vouchers`, `Vendas`, `Recorrente`, `Escolas` e `Comunicação` (checklist). Slug `ead` → **Cursos Online**; slug `conquistas_ead` → **Conquistas EAD** (checkbox separado quando o plano tem `ead`). Menu EAD respeita `usuarios.acesso` para todos os níveis (incluindo Diretor). Progresso EAD (tela auxiliar) e **Configurações de IA** (só Diretor; se plano tiver EAD, `assistente_ia` ou `whatsapp`) acompanham os módulos liberados. Diretor ainda recebe automático: Comunicação, Campanhas, WhatsApp, Assinatura, Dados da escola (+ Modelo de contrato / Pagamentos se o plano liberar).
- **Termos de Uso (LGPD):** gate em `Page::getPanel` até aceite da versão vigente (`TermosDeUso::VERSAO`). Texto em view; aceite usa só o usuário da sessão. SQL opcional: `database/usuarios_termos_versao.sql` (`termos_aceito_em`, `termos_versao`). Sem as colunas, mantém só flag `termos_uso`.
- **Ajuda:** menu padrão (após aceite dos termos) → `/painel/ajuda`; pública `/ajuda`. Conteúdo editável no Master.

### Preferências do produto (não quebrar)
- Diretor da escola **não** deve conseguir marcar permissões de módulos que a escola não contratou
- Painel mestre futuro usará os **slugs** de `SystemModules`, não labels livres
- **Master RBAC (futuro, não implementado):** hoje acesso binário via `MASTER_EMAILS` + `MasterGateHelper`. Evolução planejada: super-admins no `.env` + staff Master em `usuarios` (`id_admin=0`) com `acesso` JSON (slugs: `ead_cursos`, `planos`, `escolas`, …) espelhando o CRUD de [`Admin/User.php`](app/Controller/Admin/User.php). Checks de autorização devem passar por helpers (`MasterGateHelper` → `ModuleGateHelper` adaptado), não e-mail espalhado no código. Editor Ascend do catálogo CTI: [`EditorAuthHelper::canEditCatalogCti`](app/Common/Helpers/EditorAuthHelper.php) — expandir para permissão `ead_cursos` quando RBAC existir.

---

## 4. Padrão MVC / AJAX do projeto

### Fluxo típico de tela
1. `GET /painel/...` → Controller `index()` → View HTML + JS
2. JS faz `$.post(url_base + 'painel/...', { acao: '...' })`
3. Controller `getInfo()` / método dedicado retorna **JSON string** (`json_encode`)
4. `Response` padrão costuma ser `text/html` com body JSON (o jQuery parseia com `'json'`)

### Convenções
- Controllers em `app/Controller/Admin/`
- Entities em `app/Model/Entity/`
- Se controller e entity tiverem o **mesmo nome** (ex.: `Campanhas`), no controller usar:
  ```php
  use App\Model\Entity\Campanhas as EntityCampanhas;
  ```
- Views: `resources/view/admin/modules/<modulo>/`
- JS: `resources/js/<modulo>.js` com cache-bust `?v=YYYYMMDD`
- Rotas: um arquivo por domínio em `routes/admin/` + `include` em `routes/admin.php`
- SQL: usuário prefere **colar no phpMyAdmin**; evitar criar arquivos `.sql` no repo salvo pedido explícito
- **Não commitar** a menos que o usuário peça

### Filtros de lista (Alunos / Responsáveis / Matrículas / Carnês)
- Toolbar na view: `#barra-filtros-lista` com `input[name]` / `select[name]`
- `ajax.js` envia os campos no POST de `listar()` (debounce na busca; paginação via `irPagina(N)` mantém filtros)
- Params comuns: `busca`, `ativo` (`s`/`n`), `matricula` (`ativa`/`sem`), `status` (`0`/`1`/`3`), `parcela` (`aberto`/`atraso`)
- Helpers: `TenantHelper::termoLike()`, `TenantHelper::idsAlunosPorBusca()`

### Ciclo de vida da matrícula (`MatriculaStatusHelper`)
- Códigos: `0` andamento · `1` encerrado · `3` cancelado
- **Ativa** (única definição): `status = 0 AND (fim IS NULL OR fim >= CURDATE())` — filtro Alunos, dashboard, agenda, campanhas, `StudentEntitlement`
- Ao listar Matrículas/Carnês/extrato: `encerrarVencidasTenant()` marca `status=1` se `fim < hoje`
- **Bolsista:** coluna `matriculas.bolsista` (`database/matriculas_bolsista.sql`) — matrícula sem carnê/débitos no `caixa`; valor 0; “parcelas” = duração do curso
- **Cancelar:** `status=3` + baixa administrativa nas parcelas abertas (`status=1`, `valor_pago=0`, `tipo_pagamento=Cancelamento`) — **não apaga** carnê
- **Encerrar** (botão): só `status=1`, sem mexer no caixa
- Higiene legado (opt-in): `database/matricula_status_higiene.sql` (preview → apply no phpMyAdmin)
- KPIs de receita: excluir `tipo_pagamento` Cancelamento/Renegociação (`sqlExcluirNaoReceita`)

### Segurança esperada
- Validar `id_admin` / `TenantHelper` em listagens e updates
- Senhas SMTP: `CryptoHelper` (AES-256-CBC, chave `APP_KEY` ou fallback `SYSTEM_TOKEN`)
- E-mails: `EmailValidator` bloqueia placeholders (`sem@email.com`, etc.)
- Nunca hardcodar tokens de Meta/WhatsApp (legado `Mensagens.php` está quebrado/inseguro)

---

## 5. Módulos principais (estado atual)

### 5.1 Pedagógico / usuários
- Alunos (`Clientes`), Responsáveis, Funcionários (`User`), Trilhas, Matrículas, Certificados
- Aluno = `usuarios.nivel = 'Cliente'`

### 5.2 Financeiro
- `caixa` — títulos de entrada/saída; carnê gera parcelas com `status` `Em aberto` / pago (`0`/`1` misturado no legado — tratar ambos via `FinanceiroAlunoHelper::sqlTituloAberto` / `sqlTituloPago` / `tituloAberto` / `tituloPago`)
- Carnês ligados a `matriculas` via `caixa.id_ref`
- **Extrato consolidado do aluno:** `/painel/alunos/{id}/extrato` (atalho em Alunos) — todas as matrículas + acordos; PDF via html2pdf; SQL `database/financeiro_acordos.sql`
- **Renegociação:** marca títulos abertos como `tipo_pagamento=Renegociação` (histórico) e cria `financeiro_acordos` + novas parcelas (`caixa.id_acordo`, `id_ref=0`) sem liberar EAD
- **Pontualidade (10%):** `FinanceiroAlunoHelper::calcularPontualidade` — Carnê Simples, baixa em Carnês/Entrada/Carrinho se vencimento > hoje; desativado com PIX
- KPIs dashboard: “a receber semana” = só abertos; inadimplentes = abertos vencidos (triplo status); receita exclui Cancelamento/Renegociação
- **Status canônico ao gravar:** aberto = `Em aberto` (`STATUS_ABERTO`); pago = `1` (`STATUS_PAGO`). Leitura continua aceitando legado `0`/`Pago`
- **Webhook PIX escola:** só baixa se `transaction_amount` ≈ face do título (± R$ 0,05); divergência → `error_log`, não marca pago
- Higiene status legado (opt-in): `database/caixa_status_normalizar.sql`
- Portal aluno: `GET /api/v1/student/finance` + página `/finance` (só leitura, menu se houver títulos)
- Carrinho de pagamento + recibos (baixa manual dinheiro/cartão)
- Gateway **Mercado Pago** (PIX QR no carnê) em `app/Common/Gateways/MercadoPago/`
  - Credenciais por escola: `escola_integracoes` (`mp_*`) + tela `/painel/config/pagamentos`
  - Webhook: `POST /webhook/mercadopago/{idAdmin}/{token}` → baixa automática
  - SQL: `database/escola_integracoes_mercadopago.sql`
  - Sem MP ativo: matrícula só oferece **Carnê Simples**
  - Interface `PixGatewayInterface` para próximos bancos
- Desconto pontualidade: só no **Carnê Simples** (desativado com PIX); helper `calcularPontualidade` em Carnês, Caixa Entrada e Carrinho
- **Estoque + PDV (MVP):** SQL `database/estoque_vendas.sql`. Painel `/painel/estoque` + PDV. **API pública `/api/v1/estoque/*` removida** (sem uso; vazava multi-tenant).
  - Menu Financeiro → **Estoque** (`/painel/estoque`) e **PDV** (`/painel/estoque/pdv`)
  - Slugs: `estoque`, `vendas` (label PDV); Diretor ganha automático se o plano liberar
  - Produtos/categorias/movimentações (`stq_*`); venda baixa estoque + Entrada paga no `caixa` (`referencia=venda_stq`)
  - Fora do MVP: imagens, fornecedores UI, estorno, PIX MP na venda, loja no Ascend

### 5.2b Configurações da escola (Diretor)
- `/painel/config/escola` — edita telefone, e-mail, site, endereço, logo, modelo cert, redes
- Bloqueado no Diretor (só Master): nome, CNPJ, ativo, plano/módulos
- Menu **Marketing** (Agenda, Biblioteca, Mensagens, Campanhas, Aniversariantes) — ver §5.15; WhatsApp permanece item próprio no menu
- Config = Dados / Comunicação / Conexão Meta / Pagamentos / Contrato; Financeiro começa em Carnês

### 5.3 CRM
- `crm_leads` Kanban, funis, histórico, importação planilha
- Tarefas estilo Trello (`crm_tarefas_*`)
- **WhatsApp no CRM:** um botão “WhatsApp” → `iniciarAtendimentoWa` — com módulo + Evolution conectada abre o Inbox; sem plano/desconectado abre **WhatsApp Web** (`wa.me`)
- Auto-mensagem ao mudar status (`novo` / `em_atendimento` / `matriculado`): Evolution via `WhatsappEscolaService::enviarTexto`; templates editáveis em `/painel/crm/automacao` (Diretor); se escola sem módulo ou desconectada, registra no histórico e `status_wa=pulado` (não tenta enviar)
- **Relatórios CRM (Diretor):** `/painel/crm/relatorios` — KPIs, por status, por funil, motivos de perda, origens (filtro por período de cadastro). Menu só para Diretor com permissão Leads.
- **Roadmap relatório de tarefas:** cards Kanban (`crm_tarefas_cartoes`) sem status/data de conclusão padronizados — planejar: contagem por lista, cards criados no período, checklists % concluídos, tempo médio na lista (exige `updated_at`/histórico de movimento ou campos novos).

### 5.4 Agenda Laboratório v2 (feita)
Arquitetura:
```
laboratorios → horarios (laboratorio_id) → agenda_plano → agenda_aulas → presencas
```
- Controllers: `AgendaLaboratorios`, `AgendaHorarios`, `AgendaLaboratorio`, `AgendaDiario`
- Helper: `AgendaHelper`
- Menu: Laboratórios / Horários / Agendamentos / Diário
- Migração legado `agenda_aula` → `agenda_plano` no primeiro acesso

### 5.5 Comunicação / e-mail (Fases 1–7 — feitas)

#### UI — `/painel/config/comunicacao` (Fase 7: abas)
Tela reorganizada em **nav-tabs** (deep-link `?tab=smtp|auto|wa|audit`):

| Aba | Conteúdo |
|-----|----------|
| **SMTP** | Host, porta, credenciais, teste de envio |
| **Automações** | Cobrança de mensalidades + aniversário (e-mail/WA); simular / enviar agora |
| **WhatsApp** | Evolution API — QR, status da instância |
| **Auditoria** | Relatório de e-mails e WhatsApp inválidos/ausentes |

Controller: `ConfigComunicacao` · JS: `config-email.js`

#### SMTP
- `Email::sistema()` → `.env` (recovery de senha)
- `Email::escola($idAdmin)` → `escola_integracoes` se SMTP ativo; senão fallback sistema
- Entity: `EscolaIntegracoes` — senha criptografada (`CryptoHelper`)

#### Campanhas (módulo separado — ver também §5.15)
- Tabelas: `campanhas`, `campanha_fila`, `campanha_worker_runs`
- UI: `/painel/campanhas` (menu Marketing)
- Worker: `worker/campanhas.php` **ou** HTTP `GET/POST /cron/campanhas?token={SYSTEM_TOKEN}`
- Fallback com painel aberto: `campanha-heartbeat.js` + botão “Processar fila”
- Segmentos (`CampanhaSegmentoHelper`): matriculados, ex-alunos, menores/maiores 18, e-mails inválidos (só WA), aniversariantes mês/dia (**com `aniv_situacao`**), leads, inadimplentes, grupos/listas WA
- Variáveis: `{nome}`, `{email}`, `{whatsapp}`, `{curso}`, `{escola}`, `{valor_debito}`, `{qtd_parcelas_atraso}`, `{primeiro_vencimento_atraso}` (inadimplentes)

#### Cobrança automática de mensalidades
- Config na aba **Automações** (dias antes / no dia / depois)
- Service: `CobrancaEmailService`
- Log anti-duplicidade: `email_cobranca_log` (UNIQUE caixa+tipo+dias)
- Worker CLI: `worker/cobranca.php` · Cron HTTP: `/cron/cobranca?token={SYSTEM_TOKEN}`
- **Simular hoje** usa dados do formulário e **não envia**; **Enviar agora** envia de verdade
- Destinatário: e-mail do aluno; se inválido/ausente, responsável (se habilitado)

#### Aniversário automático (e-mail / WhatsApp)
- Config na aba **Automações** (mensagem, canal, matriculados ou todos)
- Service: `AniversarioEmailService` · Log: `email_aniversario_log` (1× por aluno/ano)
- Worker CLI: `worker/aniversario.php` · Cron HTTP: `/cron/aniversario?token={SYSTEM_TOKEN}`

#### Cron HTTP + logs de execução (Fase 6)
- Endpoints: `/cron/cobranca`, `/cron/aniversario` (mesmo padrão de `/cron/campanhas` e `/cron/social`)
- Controllers: `App\Controller\Cron\Cobranca`, `Aniversario`
- Tabela: `comunicacao_worker_runs` — última execução, enviados/erros por tipo
- SQL: `database/comunicacao_fase6.sql`
- Diagnóstico: `php worker/status-email.php [id_admin]`
- Doc operacional: `docs/OPERACAO_EMAIL.md`

#### Validador / auditoria (Fase 6 ampliada)
- `EmailValidator` — rejeita fakes (`sememail@email`, `sem@email.com`, domínios placeholder…)
- Aplicado em campanhas, cobrança, teste SMTP
- `EmailAuditoriaHelper::auditarContatosEscola` — alunos, responsáveis, leads + **WhatsApp inválido/ausente**
- Aba **Auditoria** na Comunicação; rodar antes de campanhas em massa

### 5.6 WhatsApp / Evolution API (Fase 3)
- Credenciais: `EVOLUTION_URL`, `EVOLUTION_API_KEY`, `EVOLUTION_WEBHOOK_SECRET` no `.env`
- 1 número por escola hoje (`escola_{id_admin}`); tabela `whatsapp_numeros` preparada para multi
- Módulo/plano: slug `whatsapp` → label `WhatsApp` (Diretor liberado automático)
- Inbox: `/painel/whatsapp` — conversas, assumir, transferir setor, responder
- Setores + atendentes (Diretor na aba do inbox)
- Chatbot legado: menu numérico de setores → fila → humano (`WhatsappChatbotService`) — **fallback** se nenhum fluxo casar
- **Fluxos do bot (Fase A + B):** `/painel/whatsapp/fluxos` — SQL `database/whatsapp_fluxos.sql` (`whatsapp_fluxos`, `whatsapp_fluxo_sessoes`, `whatsapp_fluxo_logs`). Motor `WhatsappFlowRunner`:
  - **Fase A:** triggers (keyword / primeira msg / saudação), passos texto/mídia/pergunta/opções numéricas/condição/delay/setor/humano/fim. Sem botões nativos nem canvas n8n. Opt-out: `sair`/`parar`; `menu` volta ao menu de setores.
  - **Fase B:** variáveis `{{nome}}` / `{{ultima_resposta}}`; nós `criar_lead` (CRM) e `set_var`; timeout por fluxo (`settings.timeout_horas` / `timeout_acao`: humano|encerrar — checado ao receber msg + ação `processar_timeouts`); templates prontos (`WhatsappFlowTemplates`); simulador dry-run no editor (ação `simular`, sem WA/CRM real).
- Webhook grava msgs e dispara chatbot → FlowRunner → fallback menu
- Ops: `docs/OPERACAO_WHATSAPP.md`

### 5.7 Validação de e-mail nos cadastros
- `EmailValidator` aplicado em: Alunos, Responsáveis, Funcionários, Perfil, Leads (form + planilha), Register
- Funcionários/perfil/register: e-mail **obrigatório** e válido
- Alunos/responsáveis/leads: e-mail **opcional**; se preenchido, não pode ser fake (`sem@email.com`, etc.)

### 5.8 LMS / Cursos Online (EAD) + portal aluno
Camada **paralela** às Trilhas — **não** altera contratos/`matriculas` comerciais.

```
trilhas + matriculas → carnê/contrato (comercial)
lms_cursos (independente; titulo próprio; id_trilha opcional/legado)
  → lms_modulos → lms_aulas → videos/materiais/atividades/roleplay
lms_matricula_ead → libera aluno no Ascend (sem carnê)
vitrine: lms_vitrine_assinaturas + itens na saas_faturas + lms_vitrine_repasses
catalogo_cti: planos_cursos + lms_escola_cursos_cti (cursos origem=cti, incluídos no plano SaaS)
```

- **Catálogo CTI (Master):** `/master/ead-cursos` + vínculo em **Planos** (`planos_cursos`). Provisionamento: `CtiCatalogProvisioner` → `lms_escola_cursos_cti`. Escola: card **Cursos CTI** em `/painel/ead` (somente leitura, matrícula manual). SQL: `database/lms_catalogo_cti.sql`.
- **Editor Master (sem impersonate):** GET `/master/ead-cursos/editor/{id}` — layout Master + JS compartilhado `ead-editor.js` (`window.EAD_EDITOR_API` → POST `/master/ead-cursos/editor/{id}`). API roda em `TenantHelper::withTenant(idCatalogo)` gravando no tenant catálogo com sessão Master intacta. Botão **Editor interativo (Ascend):** `abrir_editor` cria `lms_editor_tokens` com `id_admin` catálogo + `id_usuario` Master → `ASCEND_URL/editor/auth?token=`. Exchange JWT: [`EditorAuthHelper`](app/Common/Helpers/EditorAuthHelper.php) aceita mismatch tenant (Master ≠ catálogo); claim `master_catalog` no JWT; [`EditorJwtAuth`](app/Http/Middleware/EditorJwtAuth.php) usa `resolveIdAdmin()` para APIs `/api/v1/editor/*`.
- **Editor Master (sem impersonate):** GET `/master/ead-cursos/editor/{id}` — layout Master + JS compartilhado `ead-editor.js` (`window.EAD_EDITOR_API` → POST `/master/ead-cursos/editor/{id}`). API roda em `TenantHelper::withTenant(idCatalogo)` gravando no tenant catálogo com sessão Master intacta. Botão **Editor interativo (Ascend):** `abrir_editor` cria `lms_editor_tokens` com `id_admin` catálogo + `id_usuario` Master → `ASCEND_URL/editor/auth?token=`. Exchange JWT: [`EditorAuthHelper`](app/Common/Helpers/EditorAuthHelper.php) aceita mismatch tenant (Master ≠ catálogo); claim `master_catalog` no JWT; [`EditorJwtAuth`](app/Http/Middleware/EditorJwtAuth.php) usa `resolveIdAdmin()` para APIs `/api/v1/editor/*`.
- **Aulas interativas (L-Editor):** SQL `database/lms_aulas_interativas.sql` + `database/plataforma_bunny.sql` (`tipo_conteudo`, `lms_aula_cenas`, progresso, `lms_editor_tokens`; mídias em Bunny). Editor isolado no Ascend (`/editor/*`, sem layout do aluno). API `/api/v1/editor/*` (JWT professor) + cenas no `GET` lesson do aluno (`contentType: interactive`, `interactiveProgress: { passo, maxPasso, concluida }`). Upload: imagem/áudio → **Bunny Storage**; vídeo de cena → **Bunny Stream** (HLS assinado na leitura). Progresso de cena: `POST .../interactive-progress`. Portal: Continuar de onde parou / Começar do início. Painel: botão **Abrir editor** (token one-time → `ASCEND_URL/editor/auth?token=`).
- **Entitlement:** `lms_matricula_ead` ativa + curso `publicado=1` + escola dona **ou** licença vitrine **ou** provisionamento CTI ativo. Matrícula comercial **não** libera EAD.
- **Painel:** `/painel/ead` (cursos), `/painel/ead/curso/{id}` (editor + aba Alunos), `/painel/ead/vitrine`; Config IA `/painel/config/ia`
- **Vitrine (menu):** slug `vitrine` / label `Vitrine de cursos` no catálogo de permissões (plano com `ead` expande `vitrine`). Menu e banner só aparecem se houver curso compartilhado de outra escola **ou** licença ativa da escola; sem isso redireciona para `/painel/ead`.
- **Editor:** curso independente; matricular/desmatricular EAD; opcional vitrine (preço mensal). JS: `ead-cursos.js`, `ead-editor.js`, `ead-vitrine.js`
- **Trilhas:** `ativo` + filtros busca/categoria/status; nova matrícula só ativas
- **SQL extra:** `lms_ead_independente.sql`, `lms_vitrine.sql`, **`lms_catalogo_cti.sql`** (opcional limpar: `lms_ead_limpar_exemplos.sql`)
- **SQL (ordem base):** `lms_ead.sql` → `lms_xp.sql` → … → `lms_videos_bunny.sql` → **`lms_aulas_interativas.sql`** → **`plataforma_bunny.sql`** → **`lms_ead_independente.sql`** → **`lms_vitrine.sql`** — ver `database/LMS_CHECKLIST_PRODUCAO.md`
- **API aluno:** `/api/v1/student/*` (JWT Cliente; CORS; mapper Ascend). **Não** usar API legada `/api/v1/trilhas`
- **Portal:** `ascend-academy` — marca **CTI Educacional** (`public/brand/cti-logo.png`); build com `VITE_API_BASE_URL` apontando para a API
- **Player (estilo Udemy):** ao abrir `/courses/{id}` redireciona ao 1º item liberado; sidebar com currículo (aulas+atividades+roleplay) + aba Assistente IA; abas sob o vídeo (visão geral / materiais / anotações / comentários). Topbar: busca client-side em cursos/aulas/professores. Continuar/Começar respeita `locked` (mesma regra do dashboard). **Player de vídeo:** só **Bunny Stream** (HLS assinado, sem acelerar/seek/download reforçado). YouTube desativado (aulas legadas mostram aviso). Credenciais Bunny **globais** no **Master → Bunny** (`plataforma_bunny`: Stream + Storage); upload no editor EAD e no L-Editor. Playback assina HLS com `SHA256_HEX(token_security_key + video_id + expires)` (chave = Stream Security → Token authentication key; não usar formato Advanced do Pull Zone).
- **Menu global:** sem Avaliações/Roleplay/IA isolados — ficam no currículo do curso; Ranking via `GET /ranking?scope=school|global`
- **Auth aluno:** login + forgot/reset password; **alterar senha logado** `POST /me/password`; preferências de notificação no portal (localStorage, Configurações).
- **Admin Progresso EAD:** `/painel/ead/progresso` (turma: filtros por curso/status/%, totais, CSV) + `/painel/ead/aluno/{id}` (histórico + Liberar próxima aula; atalho em Alunos). Requer permissão **Cursos Online**.
- **Alunos online:** `lms_portal_presenca` + `POST /presence` (Ascend ping ~30s); admin `/painel/ead/alunos-online`.
- **Branding portal:** Master `/master/portal-branding` → `GET /branding`; logo também no login/navbar do painel.
- **Fluxo de atividade (sequencial):** `POST .../assessments/{id}/start` → `answer` (1 questão, trava) → `finalize`. V/F = botões true/false. Abertas corrigidas por IA (`LmsAiService::gradeEssay`). **N tentativas por ciclo** (`tentativas_max`, padrão 3). Média da unidade = atividades + roleplay (≥70% aprova). Se reprovar: `precisa_revisar` → reassistir aula → novo ciclo (+N)
- **Roleplay:** chat embutido no player; timer = `estimated_minutes`; `sendMessage`/`finish` bloqueiam sessão encerrada/tempo esgotado; `base_prompt` **nunca** no GET aluno
- **Assistente IA:** contexto = título/descrição da aula + labels dos materiais; guardrails; máx. ~40 msgs/conversa; modelo padrão Gemini `gemini-2.0-flash`
- **XP:** aula `5+min(dur,15)` (5–20); atividade aprovada `15+20*nota/100` (15–35); tentativa `2`; roleplay `20+score*0.15`; streak diário `3`. Nível: `floor(√(XP/50))+1` (curva inalterada — só créditos futuros mudam). Ranking **escola** = XP total; ranking **global** = XP dos **últimos 30 dias** (`periodDays`) para equilibrar escolas com catálogos de tamanhos diferentes. Admin avisa se `lms_xp_ledger` ausente.
- **Acesso por agenda (Fase B):** novas aulas só no horário do `agenda_plano` (dia/hora) ou `agenda_avulso` (reposição). Cota padrão **2 aulas/sessão** (`lms_sessao_cota`). Fora do horário: portal + revisão do já concluído. Admin: Agenda → **Reposição / avulso**. SQL: `database/lms_agenda_acesso.sql`. Trilha da agenda = **escola do aluno** (`LmsAgendaAcessoHelper::idTrilhaAgendaAluno` via matrícula comercial / plano local) — vale também em **curso licenciado da vitrine** (não usa `curso.id_trilha` do criador).
- **Polish portal (Fase C):** após finalizar atividade (sem `needsRewatch`), auto-avança ao próximo item do currículo (~1,8s) + botão Próximo; roleplay mostra XP e botão Próximo
- **Portal polish (Fase 2):** branding CTI; banner/badge “Reassistir” + média da unidade; certificado **EAD simbólico** em `lms_certificados` (nome da escola, sem QR) — emissão **automática** em 100% (e **backfill** ao listar `/certificates` se já estava 100% antes da feature); se a escola editar o curso e o progresso cair, status `outdated` até reconcluir (aí o snapshot atualiza, `codigo` permanece); `GET /certificates` + `GET /certificates/{id}/html` (403 se desatualizado). Certificado **comercial** (painel) continua em `certificados`; QR usa `clientes_assinantes.site`.
- **Streak / rating (Fase A gamificação):** `streakDays` = sessões de **agenda** consecutivas; XP `streak_daily` nesses dias. Avaliação de curso: `lms_curso_avaliacoes`.
- **Conquistas:** metas em `lms_conquistas_def` (v2 ~100 + v3 extras/`secreto`); progresso em `lms_conquistas_aluno` (`origem` auto|manual); Master CRUD `/master/conquistas`; portal `GET /achievements` + página `/achievements`; dashboard top 6. Escola: `lms_escola_conquistas` + liberação manual em `/painel/ead/conquistas`. Ranking diário: `lms_ranking_diario` + cron `worker/lms_ranking_diario.php`.
- **Tempo de estudo:** `LmsEstudoHelper::minutosAluno` = `GREATEST(proxy aulas concluídas, minutos reais do heartbeat)`; tabela `lms_estudo_sessao`; `POST /study/heartbeat` a cada ~30s no player; alimenta `user.totalStudyMinutes`, dashboard e conquistas `estudo_min`.
- **Esqueci senha:** `POST /auth/forgot-password` envia e-mail (SMTP escola/sistema) com JWT `purpose=pwd_reset`; `POST /auth/reset-password`; Ascend `/reset-password`. Requer `ASCEND_URL` no `.env`.
- **Comentários de aula:** `lms_aula_comentarios`; `GET/POST .../lessons/{id}/comments` (+ delete); UI na aba Comentários do player.
- **Anotações de aula:** `lms_aula_anotacoes`; `GET/PUT .../lessons/{id}/notes`; UI na aba Anotações (cache local + sync).
- **Conquistas escola:** Admin `/painel/ead/conquistas` — toggle `lms_escola_conquistas` + liberação manual (`origem=manual`); Master upload/remover badge.
- **Notificações in-app:** tabela `lms_notificacoes`; `GET/POST /notifications`; eventos em aula, atividade, roleplay, certificado e conquista.
- **Hard rules:** não misturar com `agenda_*` no sentido de reescrever o diário; só **consumir** plano/avulso no LMS; gabarito nunca no GET; chave AI com `CryptoHelper`
- **Agenda:** se o aluno **não** tem trilha/plano na escola dele → EAD livre; se tem → cota/horário se aplica a qualquer curso matriculado (próprio ou licença vitrine)
- **Vitrine (L6):** cards com `cover_url`; amostra pré-assinatura = descrições/tópicos + **1º vídeo** + PDFs (sem atividades/roleplay); contato da escola dona para demo completa; licença + SaaS + repasse

### 5.9 Redes sociais (Meta — Facebook / Instagram)

- **App Meta:** um só da CTI (`.env`: `META_APP_ID`, `META_APP_SECRET`, `META_WEBHOOK_VERIFY_TOKEN`, `META_GRAPH_VERSION`). Escolas **conectam** Page + IG Professional (OAuth ou token Dev).
- **Config (Conexão Meta — Fase 8 UI):** `/painel/config/social` (Diretor + permissão Redes sociais) — abas **Visão geral** (banner status, checklist, última publicação, worker/cron), **Conectar** (OAuth, switches FB/IG/automações), **Automações** (keyword → DM), **Diagnóstico** (webhook, teste, debug). Deep-link `?tab=visao|conectar|auto|diag`. Controller: `ConfigSocial` · JS: `config-social.js`. Tokens em `escola_integracoes` via `CryptoHelper`. SQL: `database/escola_integracoes_meta.sql`
- **Agenda (Fase A produto):** `/painel/social` — visão **semana/mês**, filtros status/formato, aba Histórico. Formatos **`feed` | `story` | `reel` | `carousel`**. SQL: `database/social_posts.sql` + `social_posts_formato.sql` + **`social_fase_a_produto.sql`** (`social_biblioteca`, `social_publish_log`, `social_worker_runs`).
- **Biblioteca de mídias (Marketing):** `/painel/marketing/biblioteca` — upload, edição de título, exclusão segura (bloqueia se path em post ou campanha). Service: `SocialBibliotecaService`. Upload em `uploads/social/{id_admin}/`. Pickers na Agenda e Campanhas reutilizam a mesma tabela.
- **Publicação:** Feed imagem FB+IG; Carrossel IG (e fotos FB); **Story/Reel só Instagram**. Arquivos da biblioteca **não** são apagados ao cancelar/publicar.
- **Worker / cron:** `php worker/social.php [id_admin] [limite]` **ou** HTTP `GET/POST /cron/social?token={SYSTEM_TOKEN}`. Agenda mostra última execução (`social_worker_runs`). **Poll na agenda aberta** (~45s, origem `poll`, lote ≤5): publica posts já devidos sem depender do botão; `claimPublicando` evita double-publish; registro em `social_worker_runs` só se processou algo. Cron no servidor continua sendo a fonte de verdade com o painel fechado. Agendamento nativo Meta (`scheduled_publish_time`) fica para o futuro.
- **Automações (Fase 2):** keyword em comentário → DM (private reply). SQL `database/social_automacoes.sql`. Toggle `meta_auto_ativo` + regras em Config Social. Webhook `POST /webhook/meta` (global, resolve escola por Page/IG id) ou `/webhook/meta/{idAdmin}/{token}`. Escopos extras: `instagram_manage_comments`, `instagram_manage_messages`, `pages_messaging`, `pages_manage_engagement`. Após OAuth: `subscribed_apps` na Page.
- **Inbox Messenger / Instagram Direct (Fase A):** SQL `database/meta_messaging.sql` (`meta_conversas`, `meta_mensagens`, `meta_webhook_log`). Webhook `messaging` → `MetaMessagingService`; envio via `MetaGraphHelper::sendMessage`.
- **Inbox UI (Fase B):** `/painel/social/mensagens` — listar conversas, histórico, responder, arquivar (`MetaInbox` + `social-mensagens.js`).
- **Permissão:** slug `social` no plano **e** label `Redes sociais` no checklist do usuário (sem auto-grant Diretor).
- **App Review Meta** obrigatório para Live (publicação + messaging/comments).
- **Roadmap produto:** B aprovação editorial · C legendas IA + Insights Meta · D fluxos tipo ManyChat.

### 5.15 Marketing — menu, Aniversariantes, Biblioteca (Fases 3–5, 9)

Menu lateral **Marketing** (`SystemModules` → slug `marketing`):

| Item | Rota | Permissão efetiva |
|------|------|-------------------|
| Agenda | `/painel/social` | `Redes sociais` |
| Biblioteca de mídias | `/painel/marketing/biblioteca` | `Redes sociais` **ou** `Campanhas` (`requires_any`) |
| Mensagens | `/painel/social/mensagens` | `Redes sociais` |
| Campanhas | `/painel/campanhas` | `Campanhas` |
| Aniversariantes | `/painel/marketing/aniversariantes` | `Campanhas` |

**WhatsApp inbox** (`/painel/whatsapp`) permanece item **próprio** no menu (não dentro de Marketing).

#### Biblioteca de mídias (Fase 5)
- Página dedicada (antes só aba na Agenda)
- Controller: `MarketingBiblioteca` · Service: `SocialBibliotecaService`
- Upload em `uploads/social/{id_admin}/` · tabela `social_biblioteca`
- Exclusão segura: bloqueia se path em post agendado ou campanha
- Pickers reutilizados na Agenda (`social-agenda.js`) e Campanhas (`campanhas.js`)
- JS compartilhado: `social-biblioteca-shared.js`, `marketing-biblioteca.js`

#### Aniversariantes (Fase 4 + 9)
- Controller: `MarketingAniversariantes` · Helper: `AniversariantesHelper`
- Filtros: período (hoje / esta semana / este mês), mês calendário, **situação do aluno**, busca por nome
- Situações (`aniv_situacao`): `todos`, `ativos`, `inativos`, `inadimplentes`, `ativos_inadimplentes`, `inativos_inadimplentes`, `inativos_regular`, `com_email`, `com_whatsapp`, `nao_enviado_ano`
- Lista limitada a **500** registros na UI (performance)
- Botão **Criar campanha** → deep-link:
  ```
  /painel/campanhas?segmento=aniversariantes_mes|aniversariantes_dia&aniv_situacao=...&titulo=Aniversariantes
  ```
- Automação **diária** (1 e-mail/WA por aluno/ano) continua em **Configurações → Comunicação** (aba Automações), não nesta página

#### Campanhas ↔ situação do aluno (Fase 9)
- `CampanhaSegmentoHelper` reutiliza `AniversariantesHelper::listar()` para segmentos `aniversariantes_mes` e `aniversariantes_dia`
- JSON do segmento salva `aniv_situacao`; modal de campanha exibe select quando o público é aniversariantes
- Preview e envio respeitam o mesmo filtro da página Aniversariantes
- Campanhas chamam `listar(..., limit: null)` — sem teto de 500 no envio

#### Guias operacionais — Aniversário + campanhas

**Cenário A — Aniversário + promoção (alunos ativos)**  
1. Marketing → **Aniversariantes** → período **Este mês** (ou **Hoje**)  
2. Situação: **Alunos ativos**  
3. **Criar campanha** → escolher canal (e-mail ou WhatsApp), redigir mensagem com `{nome}`, `{curso}`  
4. **Preview** → conferir quantidade → salvar rascunho → iniciar envio (cron `/cron/campanhas` processa a fila)

**Cenário B — Aniversário + negociação de dívida**  
1. Marketing → **Aniversariantes** → situação **Ativos inadimplentes** ou **Inadimplentes**  
2. **Criar campanha** (o filtro `aniv_situacao` já vem na URL)  
3. Mensagem combina parabéns + convite à negociação (sem variáveis de débito neste segmento)  
4. Se precisar de **`{valor_debito}`** e **`{qtd_parcelas_atraso}`**, use segmento **Inadimplentes** em Campanhas (filtros de parcelas/contrato) — ou duas campanhas complementares

**Cenário C — Só quem ainda não recebeu automação este ano**  
1. Aniversariantes → situação **Ainda não enviado este ano** (requer `email_aniversario_log`)  
2. Criar campanha manual para complementar a automação da Comunicação

#### Performance (Fase 9)
- Índices opcionais: `database/marketing_fase9_indexes.sql` (`usuarios`, `matriculas`, `caixa`)
- Cache-bust JS Marketing/Campanhas: `?v=20260909g` (bump ao alterar JS)

### 5.10 Documentação / Ajuda da plataforma

- SQL: `database/platform_help.sql` (`platform_help_categorias`, `platform_help_artigos`).
- Master: `/master/documentacao` — CRUD categorias/artigos + URL de vídeo (YouTube/Vimeo embed).
- **Tutoriais padrão:** botão **Carregar tutoriais padrão** no Master → `App\Common\Help\PlatformHelpSeed` (categorias + artigos de todos os módulos). `video_url` fica vazio; reaplicar **não** apaga vídeo já preenchido.
- **SQL para phpMyAdmin:** abra `database/export_platform_help_tutoriais.php` no navegador (gera/baixa `database/platform_help_tutoriais.sql`) ou rode `php database/export_platform_help_tutoriais.php`. Pré-requisito: `database/platform_help.sql`.
- **Assistente IA / Telegram (ajuda):** categoria + artigos no seed `PlatformHelpSeed` (e SQL `database/platform_help_assistente_ia.sql` / atualização nativa).
- Escola (logado): `/painel/ajuda` e `/painel/ajuda/{slug}` — placeholder “Vídeo em breve” quando não há URL.
- Público: `/ajuda` e `/ajuda/{slug}` (mesmo conteúdo publicado).
- Menu **Ajuda** entra nos módulos padrão após aceite dos termos.

### 5.11 Chamados de suporte (escola ↔ Master)

- SQL: `database/xd360/15_chamados_suporte.sql` (`chamados`, `chamado_mensagens`).
- Número: `CHM-{ano}-{id}` (ex.: `CHM-2026-00042`).
- Categorias fixas: dúvida, erro/bug, financeiro, acesso/login, sugestão, outro.
- Status: aberto → em_andamento → aguardando_escola (rótulo: Aguardando cliente) → resolvido / fechado.
- Escola: menu padrão **Suporte** (`/painel/suporte`) — abrir chamado, histórico, thread, anexo (print imagem ≤5 MB em `uploads/chamados/{id_admin}/`).
- Master: **Chamados** (`/master/chamados`) — fila global com filtros (escola, status, categoria), resposta, mudança de status.
- Anexos só por rota autenticada: `/painel/suporte/anexo/{id}` e `/master/chamados/anexo/{id}`.
- Entities: `Chamado`, `ChamadoMensagem`; helpers: `ChamadoHelper`, `ChamadoAnexoHelper`.

### 5.12 Agente Telegram nativo (Assistente IA)

Bot Telegram **nativo** no painel (somente leitura): consultas de agenda, financeiro, CRM, etc.

- **SQL:** `database/agent_escola_config.sql` + `database/telegram_agent_nativo.sql` (+ `telegram_agent_ia_opcional.sql` se já tinha a tabela)
- **Webhook:** `POST /webhook/telegram/{idAdmin}/{token}` (`TelegramBotApi::webhookToken`)
- **Worker (local/sem HTTPS):** `php worker/telegram_agent.php`
- **Core:** `TelegramAgentService` + `TelegramBotApi` + `AgentTelegramMensagem` + `AgentAnalyticsHelper`
- **Pré-requisitos escola:** módulo `assistente_ia` no plano, `llm_ativo`, token bot, **Chat ID allowlist**, chave em `escola_integracoes.ai_*`
- **IA opcional:** `telegram_ia_ativo` — desligado = só palavras-chave; ligado = também LLM (`LmsAiService`)
- **Rate limit:** 30 msgs/h/chat; histórico curto (8 msgs)
- **Config:** `/painel/config/ia` — credenciais + Telegram + ativar/remover webhook
- **Docs:** `docs/OPERACAO_TELEGRAM_AGENT.md`

> OpenClaw / Agent API HTTP foram removidos do produto. Tabelas `agent_api_*` (se existirem) são legado e podem ser descartadas.

### 5.13 Conecta Jovem (empregabilidade)

Portal público **Conecta Jovem** (`conectjovem.com.br`) — SPA React + API no painel.

- **SQL:** `database/conect_jovem.sql` (tabelas `cj_*`)
- **API:** `/api/v1/conect/*` (candidato), `/api/v1/conect-empresa/*` (empresa), `/api/v1/conect/public/*` (público)
- **CORS:** `CONECT_CORS_ORIGINS` no `.env`; middleware `cors-conect`
- **Master:** `/master/conect` (moderação), `/master/conect-branding` (logo/hero/textos), `/master/conect-relatorios` (KPIs, analytics, candidatos, CSV)
- **Analytics:** `database/conect_jovem_analytics.sql`; API `POST /conect/public/analytics/event`; SPA grava pageviews e shares first-party (sem GA)
- **Escola:** `/painel/conect` (candidatos vinculados + cadastro manual)
- **SPA:** repo `conectajovem` — consome API; deploy cPanel Git (`DEPLOY.md`)
- **Roadmap / operação / smoke:** **`docs/CONECT_ROADMAP.md`** (fases 1–6 consolidadas)

### 5.14 Prospecção de empresas (Master)

- **SQL:** `database/master_prospeccao_empresas.sql`
- **Tela:** `/master/prospeccao-empresas` — base local + importação Google sob demanda
- **Env:** `GOOGLE_PLACES_API_KEY` (Places API New; cobrada por busca)
- **Doc:** `docs/PROSPECCAO_EMPRESAS.md`

Contrato API aluno (resumo): `POST /auth/login` → `{user,tokens}`; `GET /courses` com `modules[].curriculum[]`; `videos[]` + `videoUrl` embed; `GET /dashboard` com `continueLesson` mesmo em 0%; `GET /ranking?scope=school|global`; assessments (`start`/`answer`/`finalize`); roleplay; AI tutor; certificates EAD; notes; presence; branding.

---

## 6. Arquivos-chave (mapa rápido)

| Tema | Caminhos |
|------|----------|
| Bootstrap | `includes/app.php`, `index.php` |
| Menu / painel | `SystemModules.php`, `Page.php`, `ModuleGateHelper.php` |
| Login / sessão | `Session/User/Login.php`, middlewares `RequireAdminLogin` |
| Tenant | `TenantHelper.php`, `ClientesAssinantes.php` |
| E-mail | `Common/Communication/Email.php`, `EscolaIntegracoes.php`, `CryptoHelper.php` |
| Campanhas | `Controller/Admin/Campanhas.php`, `CampanhaWorker.php`, `CampanhaSegmentoHelper.php`, `resources/js/campanhas.js` |
| Marketing Aniversariantes | `MarketingAniversariantes.php`, `AniversariantesHelper.php`, `marketing-aniversariantes.js` |
| Marketing Biblioteca | `MarketingBiblioteca.php`, `SocialBibliotecaService.php`, `marketing-biblioteca.js` |
| Comunicação (config) | `ConfigComunicacao.php`, `config-email.js`, `Cron/Cobranca.php`, `Cron/Aniversario.php`, `ComunicacaoWorkerRun.php` |
| Conexão Meta (config) | `ConfigSocial.php`, `config-social.js`, `SocialWorkerRun.php` |
| Cobrança | `CobrancaEmailService.php`, `EmailCobrancaLog.php`, `worker/cobranca.php` |
| Aniversário auto | `AniversarioEmailService.php`, `EmailAniversarioLog.php`, `worker/aniversario.php` |
| WhatsApp | `EvolutionApiService.php`, `WhatsappEscolaService.php`, `Controller/Webhook/Evolution.php` |
| Validador | `EmailValidator.php`, `EmailAuditoriaHelper.php` |
| Agenda | `AgendaHelper.php`, controllers `Agenda*` |
| CRM | `CrmLeads.php`, `resources/js/crm.js` |
| Suporte / chamados | `Controller/Admin/Suporte.php`, `Controller/Master/Chamados.php`, `Chamado*.php`, `resources/js/suporte.js` |
| Assistente Telegram nativo | `TelegramAgentService.php`, `TelegramBotApi.php`, `Webhook/Telegram.php`, `worker/telegram_agent.php`, `AgentAnalyticsHelper.php`, `docs/OPERACAO_TELEGRAM_AGENT.md` |
| URL AJAX | `resources/js/url-base.js` |

---

## 7. SQL — migrações relevantes (colar no phpMyAdmin)

### Já usadas no fluxo recente (confirmar se existem no banco)

```sql
-- Tenant / módulos
-- (se ainda for empresas:) RENAME TABLE empresas TO clientes_assinantes;
ALTER TABLE clientes_assinantes
  ADD COLUMN IF NOT EXISTS modulos_liberados JSON NULL;

-- SMTP por escola
CREATE TABLE IF NOT EXISTS escola_integracoes (
  id_admin INT UNSIGNED NOT NULL,
  smtp_host VARCHAR(255) DEFAULT NULL,
  smtp_port SMALLINT UNSIGNED NOT NULL DEFAULT 587,
  smtp_user VARCHAR(255) DEFAULT NULL,
  smtp_pass VARCHAR(512) DEFAULT NULL,
  smtp_from_email VARCHAR(255) DEFAULT NULL,
  smtp_from_name VARCHAR(255) DEFAULT NULL,
  smtp_encryption ENUM('tls','ssl','none') NOT NULL DEFAULT 'tls',
  smtp_ativo TINYINT(1) NOT NULL DEFAULT 0,
  email_delay_segundos INT UNSIGNED NOT NULL DEFAULT 3,
  email_max_hora INT UNSIGNED NOT NULL DEFAULT 80,
  cobranca_ativo TINYINT(1) NOT NULL DEFAULT 0,
  cobranca_dias_antes VARCHAR(50) NOT NULL DEFAULT '3,5',
  cobranca_aviso_vencimento TINYINT(1) NOT NULL DEFAULT 1,
  cobranca_dias_depois VARCHAR(50) NOT NULL DEFAULT '1,3,7',
  cobranca_enviar_responsavel TINYINT(1) NOT NULL DEFAULT 1,
  cobranca_assunto_antes VARCHAR(255) DEFAULT NULL,
  cobranca_assunto_vencimento VARCHAR(255) DEFAULT NULL,
  cobranca_assunto_atraso VARCHAR(255) DEFAULT NULL,
  cobranca_msg_antes TEXT DEFAULT NULL,
  cobranca_msg_vencimento TEXT DEFAULT NULL,
  cobranca_msg_atraso TEXT DEFAULT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id_admin)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Se escola_integracoes já existia SEM colunas de cobrança:
-- ALTER TABLE escola_integracoes ADD COLUMN cobranca_ativo ... (ver histórico do chat)

CREATE TABLE IF NOT EXISTS campanhas (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_admin INT UNSIGNED NOT NULL,
  canal ENUM('email','whatsapp') NOT NULL DEFAULT 'email',
  tipo VARCHAR(50) NOT NULL DEFAULT 'manual',
  titulo VARCHAR(200) NOT NULL,
  assunto VARCHAR(255) DEFAULT NULL,
  mensagem TEXT NOT NULL,
  segmento JSON DEFAULT NULL,
  status ENUM('rascunho','agendada','enviando','concluida','pausada','cancelada') NOT NULL DEFAULT 'rascunho',
  total INT UNSIGNED NOT NULL DEFAULT 0,
  enviados INT UNSIGNED NOT NULL DEFAULT 0,
  erros INT UNSIGNED NOT NULL DEFAULT 0,
  agendada_para DATETIME DEFAULT NULL,
  criada_por INT UNSIGNED NOT NULL,
  criada_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  atualizada_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_campanhas_admin (id_admin),
  KEY idx_campanhas_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS campanha_fila (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  campanha_id INT UNSIGNED NOT NULL,
  id_admin INT UNSIGNED NOT NULL,
  destinatario_tipo VARCHAR(30) NOT NULL,
  destinatario_id INT UNSIGNED DEFAULT NULL,
  nome VARCHAR(150) DEFAULT NULL,
  contato VARCHAR(255) NOT NULL,
  status ENUM('pendente','enviado','erro','cancelado') NOT NULL DEFAULT 'pendente',
  tentativas TINYINT UNSIGNED NOT NULL DEFAULT 0,
  erro_msg VARCHAR(500) DEFAULT NULL,
  enviado_em DATETIME DEFAULT NULL,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_fila_campanha (campanha_id),
  KEY idx_fila_pendente (id_admin, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS email_cobranca_log (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_admin INT UNSIGNED NOT NULL,
  caixa_id INT UNSIGNED NOT NULL,
  tipo ENUM('antes','vencimento','atraso') NOT NULL,
  dias INT NOT NULL DEFAULT 0,
  email_destino VARCHAR(255) NOT NULL,
  enviado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_cobranca_envio (caixa_id, tipo, dias),
  KEY idx_admin_data (id_admin, enviado_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### Marketing / Comunicação (Fases 5–9 — scripts prontos)

Rodar no phpMyAdmin quando a tela indicar SQL pendente:

| Arquivo | Conteúdo |
|---------|----------|
| `database/social_fase_a_produto.sql` | `social_biblioteca`, `social_publish_log`, `social_worker_runs` |
| `database/social_biblioteca_formato.sql` | Coluna `formato` na biblioteca |
| `database/comunicacao_fase6.sql` | `comunicacao_worker_runs`, `email_aniversario_log`, `email_cobranca_log` |
| `database/marketing_fase9_indexes.sql` | Índices opcionais (aniversariantes / inadimplentes) |

### WhatsApp / Evolution (colar se ainda não existir)

```sql
-- Colunas Evolution em escola_integracoes (ignore erro se a coluna já existir)
ALTER TABLE escola_integracoes ADD COLUMN evolution_instance VARCHAR(100) NULL;
ALTER TABLE escola_integracoes ADD COLUMN evolution_status VARCHAR(40) NOT NULL DEFAULT 'disconnected';
ALTER TABLE escola_integracoes ADD COLUMN evolution_ativo TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE escola_integracoes ADD COLUMN evolution_numero VARCHAR(30) NULL;
ALTER TABLE escola_integracoes ADD COLUMN whatsapp_delay_segundos INT UNSIGNED NOT NULL DEFAULT 5;
ALTER TABLE escola_integracoes ADD COLUMN whatsapp_max_hora INT UNSIGNED NOT NULL DEFAULT 40;
-- Anti-ban (Fase 1): defaults recomendados 60s / 20/h; ver database/whatsapp_anti_ban.sql
-- (piso 30s e jitter são aplicados no código; coluna whatsapp_variar_texto + campanha_fila.mensagem_enviada)

CREATE TABLE IF NOT EXISTS whatsapp_conversas (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_admin INT UNSIGNED NOT NULL,
  telefone VARCHAR(30) NOT NULL,
  nome_contato VARCHAR(150) DEFAULT NULL,
  status ENUM('aberta','em_atendimento','fechada') NOT NULL DEFAULT 'aberta',
  id_atendente INT UNSIGNED DEFAULT NULL,
  ultima_mensagem_em DATETIME DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_wa_admin_tel (id_admin, telefone),
  KEY idx_wa_admin_status (id_admin, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS whatsapp_mensagens (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_admin INT UNSIGNED NOT NULL,
  conversa_id INT UNSIGNED NOT NULL,
  direction ENUM('in','out') NOT NULL,
  tipo VARCHAR(30) NOT NULL DEFAULT 'text',
  corpo TEXT DEFAULT NULL,
  media_url TEXT DEFAULT NULL,
  wa_message_id VARCHAR(120) DEFAULT NULL,
  status VARCHAR(30) DEFAULT NULL,
  id_usuario INT UNSIGNED DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_wa_msg_conversa (conversa_id),
  KEY idx_wa_msg_admin (id_admin),
  KEY idx_wa_msg_waid (wa_message_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### WhatsApp inbox + setores + chatbot (Fase 3b)

```sql
-- Números (multi-ready; hoje 1 default por escola)
CREATE TABLE IF NOT EXISTS whatsapp_numeros (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_admin INT UNSIGNED NOT NULL,
  evolution_instance VARCHAR(100) NOT NULL,
  numero VARCHAR(30) DEFAULT NULL,
  apelido VARCHAR(80) DEFAULT NULL,
  status VARCHAR(40) NOT NULL DEFAULT 'disconnected',
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  is_default TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_wa_instance (evolution_instance),
  KEY idx_wa_num_admin (id_admin)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS whatsapp_setores (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_admin INT UNSIGNED NOT NULL,
  nome VARCHAR(80) NOT NULL,
  slug VARCHAR(40) NOT NULL,
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  ordem INT NOT NULL DEFAULT 0,
  mensagem_fila TEXT DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_wa_setor (id_admin, slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS whatsapp_atendentes (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_admin INT UNSIGNED NOT NULL,
  usuario_id INT UNSIGNED NOT NULL,
  setor_id INT UNSIGNED NOT NULL,
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uk_wa_user_setor (usuario_id, setor_id),
  KEY idx_wa_at_setor (setor_id),
  KEY idx_wa_at_admin (id_admin)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Extensões nas conversas (ignore se já existir)
ALTER TABLE whatsapp_conversas ADD COLUMN numero_id INT UNSIGNED NULL;
ALTER TABLE whatsapp_conversas ADD COLUMN setor_id INT UNSIGNED NULL;
ALTER TABLE whatsapp_conversas ADD COLUMN chatbot_estado VARCHAR(40) NOT NULL DEFAULT 'novo';
ALTER TABLE whatsapp_conversas ADD COLUMN assigned_at DATETIME NULL;
```

> Agenda v2, CRM, etc. têm SQLs próprios já aplicados em ambientes de desenvolvimento — conferir banco antes de recriar.

**Chamados de suporte (novo):** colar `database/xd360/15_chamados_suporte.sql`.

**Assistente Telegram nativo:** colar `database/agent_escola_config.sql` + `database/telegram_agent_nativo.sql`. Liberar slug `assistente_ia` no plano das escolas.

---

## 8. Roadmap (planejado × feito)

### Feito
| Item | Status |
|------|--------|
| Chamados de suporte (escola ↔ Master) | Feito |
| Agente Telegram nativo (Assistente IA) | Feito — SQL `agent_escola_config` + `telegram_agent_nativo` |
| CRM Kanban + tarefas + histórico | Feito |
| Agenda laboratório v2 + diário | Feito |
| Sync de permissões em tempo real (sessão) | Feito |
| Fase 0 módulos por escola (`modulos_liberados`) | Feito |
| Remoção Parcerias / rename `empresas` → `clientes_assinantes` (código) | Feito (SQL rename pode estar pendente em alguns ambientes) |
| Fase 1 e-mail: SMTP escola + sistema | Feito |
| Fase 2 campanhas e-mail + worker | Feito |
| Cobrança automática mensalidade (antes/dia/atraso) | Feito |
| Validador + auditoria de e-mails | Feito |
| Remoção legado WhatsApp Meta + Gemini | Feito |
| Validação de e-mail nos cadastros | Feito |
| Operação e-mail (composer limpo, status-email, checklist) | Feito |
| Automação aniversariantes por e-mail | Feito |
| Evolution API: .env + QR/status/teste + webhook + tabelas | Feito (base Fase 3) |
| Inbox + setores + chatbot menu | Feito (Fase 3b) |
| WhatsApp fluxos configuráveis (editor simples) | Feito (Fase A) |
| WhatsApp fluxos Fase B (simulador, templates, lead CRM, timeout, set_var) | Feito |
| Branding CTI UI + logo escola em impressos + rodapé | Feito (Fase A) |
| WA a partir de aluno/resp/lead + observações aluno + campanhas grupo recorrentes | Feito (Fase B) |
| Modelo de contrato por escola + frase certificado | Feito (Fase C) — SQL `database/escolas_modelo_contrato.sql` |
| Carnê PIX Mercado Pago (credenciais escola + webhook + carnê simples/PIX) | Feito (Fase D–E base) — SQL `database/escola_integracoes_mercadopago.sql` |
| Dados da escola (Diretor) + menu reorganizado | Feito |
| Webhook MP: validação `x-signature` quando secret configurado | Feito |
| CRM: mensagem WA automática ao mudar status (novo / em atendimento / matriculado) | Feito (Fase 5 enxuta) |
| CRM: templates editáveis de automação WA por escola | Feito (Fase 5+) — SQL `database/crm_automacao_wa.sql`, UI `/painel/crm/automacao` |
| **Master fase 2 — cobrança SaaS** (PIX conta CTI, faturas, webhook, worker, grace 5 dias) | Feito (MVP) — SQL `database/xd360/07_saas_assinatura.sql` |

### Master fase 2 — Assinaturas SaaS (FEITO — MVP operacional)
Dois Mercado Pago distintos:
1. **Escola** — carnê de alunos (`escola_integracoes.mp_*`, webhook `/webhook/mercadopago/{idAdmin}/{token}`)
2. **CTI** — assinatura SaaS (`.env` `MP_CTI_*`, webhook `/webhook/mercadopago/saas/{token}`)

| Peça | Onde |
|------|------|
| SQL | `database/xd360/07_saas_assinatura.sql` + `database/xd360/08_saas_faturas_pix_qr.sql` (coluna QR se tabela já existia) |
| Service | `app/Common/Helpers/SaasAssinaturaService.php` |
| MP CTI | `app/Common/Helpers/MercadoPagoCtiHelper.php` |
| Entity | `app/Model/Entity/SaasFatura.php` |
| Master UI | `/master/assinaturas` — Gerar mês / Gerar 1 escola / Rodar worker; PIX + QR; marcar paga |
| Escola UI | `/painel/assinatura` (só Diretor) — fatura aberta, QR + copia-e-cola, atualizar PIX, verificar pagamento |
| Planos | `planos_assinatura.valor_mensal` (Master → Planos) |
| Escola | `dia_vencimento_assinatura` (1–28), `assinatura_status`, `assinatura_proximo_vencimento` |
| Worker | `php worker/saas.php` — fatura do mês atual + suspende após **5 dias** de grace |
| QR PIX | Prefere `pix_qr_base64` (MP); fallback `api.qrserver.com` a partir do copia-e-cola |

**Regras de cobrança:**
- Gera fatura se houver **valor efetivo > 0**: `valor_mensal_custom` da escola **ou** `planos_assinatura.valor_mensal`
- **Personalizado** (`plan_id` vazio) **só é cobrado** se `valor_mensal_custom > 0`
- **Trial** (padrão 14 dias): `assinatura_status=trial` + `trial_ate` — worker não cobra até expirar
- Escola inativa (`ativo=n`): login **permitido**, mas acesso **só** a `/painel/assinatura` (Diretor paga PIX e reativa)
- E-mail de cobrança: SMTP sistema (`Email::sistema`) 1× por fatura (`email_enviado_em`); Master pode reenviar

**SQL fase 2+:** `database/xd360/09_saas_fase2plus.sql` (`valor_mensal_custom`, `trial_ate`, `email_enviado_em`)

**Dashboard Master:** cards Ativas / Trial / Suspensas / Abertas / Vencidas / Receita do mês em `/master/assinaturas`

**Botões Master:**
| Botão | Competência | Escopo | Suspende? |
|-------|-------------|---------|-----------|
| Gerar mês | filtro | elegíveis (não trial, com valor) | não |
| Gerar 1 escola | filtro | 1 escola (filtro) | não |
| Rodar worker | mês de hoje | filtro ou todas | sim (grace 5d) |

**Armadilha Response JSON:** `App\Http\Response` com `application/json` **não** deve re-encodar string já JSON (corrigido: se `is_string`, echo direto). Controllers Master/Admin costumam já devolver `json_encode(...)`.

### Checklist deploy (produção)
1. Subir código; rodar SQLs: `escolas_modelo_contrato.sql`, `escola_integracoes_mercadopago.sql`, `database/xd360/07_saas_assinatura.sql`, `database/xd360/08_saas_faturas_pix_qr.sql`, **`database/xd360/09_saas_fase2plus.sql`**
2. Liberar no plano: `pagamentos`, `contratos`, `dados_escola`, `ead` (ou “Todos os módulos”)
3. HTTPS; webhook MP escola + webhook SaaS CTI
4. `.env`: `MP_CTI_ACCESS_TOKEN`, opcional secret/token/payer email; cron `worker/saas.php` 1x/dia
5. Master: valor mensal nos planos → Assinaturas → Gerar mês
6. Diretor: Pagamentos (token alunos) → Assinatura (pagar CTI) → Dados / Comunicação/WA
7. Smoke: carnê PIX aluno; fatura SaaS + QR; pagamento → escola ativa

### Checklist deploy LMS / portal aluno
Detalhe: `database/LMS_CHECKLIST_PRODUCAO.md`

1. SQL na ordem do checklist (inclui v3, presença, branding, anotações)
2. Config IA (chave + modelo `gemini-2.0-flash` ou OpenAI)
3. Curso publicado + matrícula ativa do aluno
4. Ascend: `VITE_API_BASE_URL=https://…/api/v1/student` → `npm run build` → publicar `dist`
5. Cron: `php worker/lms_ranking_diario.php` 1×/dia
6. Smoke: login → player → atividade → roleplay → IA → ranking → anotações → presença admin → branding

### Próximo (ordem recomendada)
| Fase | Escopo | Notas |
|------|--------|-------|
| **LMS L0–L5 + Fase 1** | Cursos Online + API + Ascend + editor admin + checklist prod | Feito — ver §5.8 + `LMS_CHECKLIST_PRODUCAO.md` |
| **Estoque + PDV** | Produtos, categorias, movimentações, PDV → caixa | Feito (MVP) — `database/estoque_vendas.sql` |
| **LMS L6+** | Vitrine EAD entre escolas + royalties (CTI %) + aula demo | Futuro — não abrir sem pedido |
| **Fase D–E+** | Outros gateways atrás de `PixGatewayInterface` | Adiado |
| **Fase 3c** | Multi-números na UI + distribuição avançada | Schema `whatsapp_numeros` pronto |
| **Fase 5+** | Templates editáveis de automação CRM por escola | Feito — `/painel/crm/automacao` |
| **Conecta Jovem** | Portal empregabilidade (fases 1–6) | Feito — `docs/CONECT_ROADMAP.md` |
| **Master fase 2+** | Dashboard SaaS, e-mail cobrança, trial 14d, valor por escola, login só Assinatura se inativa | Feito — `database/xd360/09_saas_fase2plus.sql` |
| **Master RBAC** | CRUD `/master/usuarios` + permissões por slug (`ead_cursos`, …); instrutores editam catálogo CTI | Futuro — `EditorAuthHelper::canEditCatalogCti` já é ponto de extensão |
| **Master RBAC** | CRUD `/master/usuarios` + permissões por slug (`ead_cursos`, …); instrutores editam catálogo CTI | Futuro — `EditorAuthHelper::canEditCatalogCti` já é ponto de extensão |

### Decisões de produto já alinhadas
- Cada escola configura **SMTP próprio** (Gmail/corporativo); sistema tem fallback `no-reply@...` no `.env`
- Envio em massa **nunca** síncrono na request web sem fila/limites
- Evolution: **instância por escola** (`escola_{id_admin}`); API key global no `.env`
- Worker: cron Linux em produção; botões manuais no painel para testes XAMPP

---

## 9. Como a próxima IA deve trabalhar

1. **Ler** este arquivo + `.cursorrules` + `README.md`
2. **Não** reinventar router/views/AJAX — copiar padrão de CRM/Campanhas/Comunicação
3. **Sempre** filtrar por `id_admin` / `TenantHelper`
4. Novas permissões: adicionar slug em `SystemModules` + item de menu se necessário
5. SQL: entregar script para o usuário colar no phpMyAdmin
6. Front: usar `url_base`; bump `?v=` no script ao mudar JS
7. Evitar conflito de nomes Controller/Entity (usar alias)
8. Não commitar / não push sem pedido explícito
9. Código legado WhatsApp: **não** “consertar Meta token”; migrar para Evolution quando for a vez
10. Comunicação com o usuário: português, direto, SQL quando necessário

### Armadilhas já encontradas (não repetir)
- `lastInsertId()` = 0 quando PK não é auto-increment (`escola_integracoes.id_admin`) → não usar `(bool)$db->insert()`
- AJAX sem `url_base` falha em subpasta XAMPP
- `use Entity\Campanhas` dentro de `Controller\Campanhas` causa “Cannot declare class… already in use”
- Status de caixa legado: misturar `"Em aberto"` e `0` / `1`
- Simular cobrança deve funcionar **sem** exigir `cobranca_ativo` salvo; não enviar e-mail na simulação

---

## 10. Workers e operação

```bash
# Fila de campanhas (produção: cron * * * * *  OU  /cron/campanhas?token=SYSTEM_TOKEN)
php worker/campanhas.php [id_admin] [limite]

# Social FB/IG (produção: */5  OU  /cron/social?token=SYSTEM_TOKEN)
php worker/social.php [id_admin] [limite]

# Cobrança diária mensalidades alunos (produção: 0 8 * * *  OU  /cron/cobranca?token=SYSTEM_TOKEN)
php worker/cobranca.php [id_admin]

# Aniversário automático e-mail/WA (produção: 5 8 * * *  OU  /cron/aniversario?token=SYSTEM_TOKEN)
php worker/aniversario.php [id_admin]

# Assinatura SaaS escolas → CTI (produção: 0 7 * * *)
php worker/saas.php [id_admin]
```

**Crons HTTP recomendados (cPanel / HostGator):** ver exemplos completos em `docs/OPERACAO_EMAIL.md` (`/cron/campanhas`, `/cron/social`, `/cron/cobranca`, `/cron/aniversario`).

Painel (Diretor):
- `/painel/assinatura` — pagar mensualidade do Painel CTI (PIX)
- `/painel/config/comunicacao` — abas SMTP / Automações / WhatsApp / Auditoria
- `/painel/config/social` — Conexão Meta (OAuth, automações, diagnóstico)
- `/painel/config/pagamentos` — Mercado Pago da escola (carnê alunos)
- `/painel/config/contrato` — modelo HTML do contrato + frase do certificado
- `/painel/campanhas` — campanhas manuais + processar fila
- `/painel/marketing/aniversariantes` — segmentação + atalho para campanha
- `/painel/marketing/biblioteca` — mídias reutilizáveis (Agenda + Campanhas)

Master:
- `/master/escolas`, `/master/planos`, `/master/assinaturas`

Helper: `ContratoTemplateHelper` — placeholders `{{contratada}}`, `{{contratante}}`, `{{curso}}`, `{{parte1}}`, `{{clausulaExtra}}`, `{{data_contrato}}`, `{{URL}}`

Docs: `docs/OPERACAO_EMAIL.md`, `docs/OPERACAO_WHATSAPP.md`

---

## 11. Testes manuais sugeridos

### E-mail / Comunicação
1. SMTP escola: salvar Gmail (app password) + e-mail de **teste para você**
2. Campanha: preview público → salvar rascunho → iniciar → processar fila
3. Cobrança: **Simular hoje** (não envia) → só depois **Enviar agora**
4. **Auditar contatos** (aba Auditoria) → corrigir e-mails fakes e WhatsApp inválido
5. Confirmar recovery de senha ainda usa `Email::sistema()`
6. `php worker/status-email.php` → `"pronto_para_workers": true`

### Marketing / Aniversariantes
1. Aniversariantes → filtrar **Ativos inadimplentes** → **Criar campanha** → modal abre com segmento + `aniv_situacao`
2. Preview da campanha → total deve bater com a lista filtrada
3. Biblioteca → upload → usar picker na Agenda ou Campanha → tentar excluir (deve bloquear se em uso)
4. Conexão Meta → aba Visão geral → checklist e status coerentes com OAuth

---

## 12. Handoff para agente / contexto recente (set/2026)

**Leia primeiro:** este arquivo + `.cursorrules` + `.cursor/rules/painel-cti.mdc` + `README.md` + `.env.example`.

**Concluído recentemente (Marketing UX Fases 1–10):**
- Inbox WhatsApp — poll, mensagens, finalizar todas, permissões
- Menu **Marketing** (Agenda, Biblioteca, Mensagens, Campanhas, Aniversariantes)
- **Biblioteca de mídias** dedicada + pickers Agenda/Campanhas
- **Comunicação Fase 6–7** — cron HTTP cobrança/aniversário, auditoria WA, abas na config
- **Conexão Meta Fase 8** — UI em abas, checklist, status operacional
- **Campanhas Fase 9** — `aniv_situacao` integrado ao segmento aniversariantes
- **Docs Fase 10** — este arquivo §5.5, §5.15, guias operacionais

**MVP LMS:** painel Cursos Online + API `/api/v1/student` + portal do aluno (`ascend-academy`). SQL: `database/LMS_CHECKLIST_PRODUCAO.md`.

**NÃO reabrir** carnê MP aluno / Evolution sem pedido.

**Workspace multi-root:** `painel-cti` + `ascend-academy` — integração via API aluno (não compartilhar sessão admin).

**Próximo foco sugerido:** Social Meta App Review sob demanda; Fase 3c multi-números WA; legendas IA / Insights Meta.

**Fim do documento.** Atualize este `ARCHITECTURE.md` sempre que concluir uma fase do roadmap.
