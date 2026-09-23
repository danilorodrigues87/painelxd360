# Conecta Jovem — Roadmap e operação

> Documento consolidado das fases 1–6 do portal **Conecta Jovem** (`conectajovem.com.br`).  
> Backend: **painel-cti** · SPA: **conectajovem** (React/Vite).

**Última atualização:** 2026-08-22

---

## 1. Visão geral

O Conecta Jovem conecta candidatos (alunos CTI e público externo) a empresas parceiras que publicam vagas. O portal é uma SPA estática; toda a lógica e dados ficam no monolito PHP.

```
conectajovem.com.br (SPA React)
        ↓ HTTPS + CORS
admin.ctieducacional.com.br/api/v1/conect/*
admin.ctieducacional.com.br/api/v1/conect-empresa/*
        ↓
MySQL (tabelas cj_*)
        ↓
Master /master/conect (moderação + branding)
Painel escola /painel/conect (candidatos da escola)
```

### Modelo híbrido (multi-tenant)

| Dado | Escopo |
|------|--------|
| Vagas, empresas publicadas | Rede global (portal único) |
| Candidatos, leads CRM | Por escola (`id_admin`) |
| Roteamento lead externo | Cidade → escola CTI ativa; fallback `CONECT_ESCOLA_FALLBACK_ID` (padrão `1`) |
| Aluno logado (Cliente/Candidato) | Sempre vinculado à escola do usuário |

---

## 2. Repositórios e paths

| Item | Caminho local | Produção |
|------|---------------|----------|
| SPA | `C:\xampp\htdocs\pjt\conectajovem` | `/home1/.../conectajovem.com.br` |
| API / painel | `C:\xampp\htdocs\pjt\painel-cti` | HostGator `admin.ctieducacional.com.br` |
| SQL principal | `painel-cti/database/conect_jovem.sql` | phpMyAdmin |
| SQL formação (opcional) | `painel-cti/database/conect_jovem_formacao_escola_index.sql` | Se índices extras forem necessários |
| Uploads branding | `painel-cti/uploads/img/conect/` | Gravável pelo PHP |

---

## 3. Fases implementadas

### Fase 0 — Infra CORS / API base ✅

- Preflight `OPTIONS` para `/api/v1/conect/*` e `/api/v1/conect-empresa/*`
- Middleware `cors-conect`, `candidato-jwt`, `empresa-jwt`
- Variável `CONECT_CORS_ORIGINS` no `.env` do painel

### Fase 1 — Home enriquecida ✅

**SPA apenas** — seções da landing, FAQ, depoimentos, vagas em destaque, chips de tipo.

| Arquivo | Papel |
|---------|--------|
| `conectajovem/src/pages/HomePage.tsx` | Orquestra seções |
| `conectajovem/src/components/home/*` | Hero, tipos, vagas, FAQ, etc. |
| `conectajovem/src/config/site.ts` | Copy fallback |
| `conectajovem/src/config/images.ts` | Unsplash fallback |
| `conectajovem/src/hooks/useBranding.ts` | Consome API com fallback |

### Fase 2 — Área candidato ✅

**Backend:** perfil, candidaturas, notificações.

| Endpoint | Descrição |
|----------|-----------|
| `GET/PUT /conect/me` | Perfil + habilidades |
| `GET/POST /conect/candidaturas` | Listar / candidatar-se |
| `GET /conect/notificacoes` | Inbox |
| `POST /conect/notificacoes/{id}/lida` | Marcar lida |

**SPA:** `CandidatoDashboardPage` — abas Perfil, Candidaturas, Notificações.  
**Login:** alunos Ascend (`nivel = Cliente`) e candidatos externos; mensagens claras na `LoginPage`.

### Fase 3 — Sync LMS + selo certificado ✅

- Formação sincronizada a partir de **certificado emitido pela escola** (`certificados`) → selo ativo
- Progresso EAD (`lms_certificados`) → referência sem selo
- Hooks em `Admin/Certificados.php` e `LmsCertificadoHelper`
- Bloco **Formação verificada** no dashboard candidato

### Fase 4 — Master branding ✅

| Tela | URL |
|------|-----|
| Marca do portal | `/master/conect-branding` |
| Moderação | `/master/conect` |

- Upload logo (2 MB) e hero (5 MB) em `uploads/img/conect/`
- Textos: nome do portal, institucional
- API pública: `GET /conect/public/branding`
- SPA: `BrandingContext`, logo/hero dinâmicos, favicon opcional

### Fase 5 — Empresa avançada + admin escola ✅

**Empresa (API):**

| Método | Endpoint | Função |
|--------|----------|--------|
| `PUT` | `/conect-empresa/me` | Atualizar perfil da empresa |
| `PUT` | `/conect-empresa/vagas/{id}` | Editar vaga |
| `POST` | `/conect-empresa/vagas/{id}/acao` | `pausar`, `retomar`, `encerrar`, `moderacao` |
| `GET` | `/conect-empresa/candidaturas` | Listar (filtros `vagaId`, `status`) |
| `GET` | `/conect-empresa/candidaturas/{id}` | Detalhe + perfil candidato |
| `PUT` | `/conect-empresa/candidaturas/{id}` | Atualizar status pipeline |

**Pipeline candidatura:** `enviada` → `visualizada` → `em_analise` → `pre_selecionado` → `contratado` / `recusado`

**Notificações:** nova candidatura → empresa; mudança de status → candidato.

**SPA:** `EmpresaDashboardPage` — abas Vagas, Candidaturas, Perfil.

**Painel escola:** `/painel/conect` — tabela de candidatos + cadastro manual.

### Fase 6 — Documentação ✅

Este arquivo + referências em `ARCHITECTURE.md` e `conectajovem/README.md`.

### Fase 7 — Relatórios Master + Analytics ✅

**Master:** `/master/conect-relatorios` (menu Conecta Jovem → Relatórios)

| Recurso | Descrição |
|---------|-----------|
| KPIs | Novos candidatos/empresas, views de vagas, visitantes únicos, pageviews, compartilhamentos |
| Gráficos | Série diária (tráfego × cadastros × shares), candidatos por escola, shares por plataforma |
| Funil | Visitas em `/cadastro` vs candidatos cadastrados no período |
| Top páginas | Caminhos mais visitados no portal |
| Lista candidatos | Filtros (escola, UF, tipo, status, busca), paginação, **export CSV** |

**SQL analytics:** `database/conect_jovem_analytics.sql` — tabelas `cj_analytics_visitantes`, `cj_analytics_visitas`, `cj_analytics_compartilhamentos`.

**API pública (coleta SPA):**

| POST | `/conect/public/analytics/event` |
|------|----------------------------------|
| `tipo=pageview` | `visitorKey` (UUID), `path`, `referrer?` |
| `tipo=share` | `plataforma` (`whatsapp`, `facebook`, `linkedin`, `twitter`, `copy`), `path`, `slug?`, `titulo?` |

Rate limit por IP (120 eventos/hora). Sem persistência de IP nas tabelas (LGPD).

**SPA:** `src/lib/analytics.ts` + `AnalyticsTracker` (pageview por rota); `BlogShare` registra cliques de compartilhamento.

**Backend:** `CjAnalytics.php`, `ConectRelatoriosHelper.php`, `Master/ConectRelatorios.php`, `resources/js/master-conect-relatorios.js`.

---

## 4. Checklist SQL (primeira instalação)

Execute no phpMyAdmin (ordem):

1. **`database/conect_jovem.sql`** — todas as tabelas `cj_*` (obrigatório)
2. **`database/conect_jovem_analytics.sql`** — visitantes, pageviews e compartilhamentos (relatórios Master)
3. **`database/conect_jovem_formacao_concluido_texto.sql`** — conclusão do selo como texto (`Janeiro de 2025`)
4. **`database/conect_jovem_formacao_escola_index.sql`** — opcional (performance índices formação)

Tabelas principais:

| Tabela | Uso |
|--------|-----|
| `cj_portal_branding` | Logo, hero, textos (Master) |
| `cj_empresas` | Empresas parceiras |
| `cj_candidatos` | Perfis de candidatos |
| `cj_candidato_habilidades` | Tags de habilidade |
| `cj_candidato_formacao` | Cursos/certificados |
| `cj_vagas` | Vagas (moderação Master) |
| `cj_candidaturas` | Candidaturas por vaga |
| `cj_notificacoes` | In-app candidato/empresa |
| `cj_analytics_visitantes` | Visitantes anônimos (UUID) |
| `cj_analytics_visitas` | Pageviews por rota |
| `cj_analytics_compartilhamentos` | Cliques em compartilhar (blog) |

---

## 5. Variáveis de ambiente

### painel-cti `.env`

```env
JWT_KEY=sua_chave_secreta

# Portal Conecta Jovem
CONECT_URL=https://conectajovem.com.br
CONECT_CORS_ORIGINS=https://conectajovem.com.br,https://www.conectajovem.com.br
CONECT_ESCOLA_FALLBACK_ID=1
```

**Local (XAMPP + Vite):**

```env
CONECT_CORS_ORIGINS=http://localhost:5173,http://127.0.0.1:5173
CONECT_URL=http://localhost:5173
```

### conectajovem `.env` / `.env.production`

```env
VITE_API_BASE_URL=https://admin.ctieducacional.com.br/api/v1
```

Local:

```env
VITE_API_BASE_URL=http://localhost/pjt/painel-cti/api/v1
```

### Pasta de uploads

Criar/garantir permissão de escrita:

```
painel-cti/uploads/img/conect/
```

---

## 6. API — referência completa

Base: `{URL}/api/v1`

### Público (sem auth)

| GET | `/conect/public/branding` |
| GET | `/conect/public/vagas?cidade=&empresa=&tipo=` |
| GET | `/conect/public/vagas/{slug}` |
| GET | `/conect/public/empresas?cidade=` |
| GET | `/conect/public/cidades` |
| GET | `/conect/public/estados` |
| GET | `/conect/public/estados/{id}/cidades` |
| POST | `/conect/public/analytics/event` — body `tipo`, `visitorKey`/`plataforma`, `path` |

### Candidato (`Authorization: Bearer`, nível Candidato ou Cliente)

| POST | `/conect/auth/login` |
| POST | `/conect/auth/register` |
| GET | `/conect/me` |
| PUT | `/conect/me` |
| GET | `/conect/candidaturas` |
| POST | `/conect/candidaturas` — body `{ vagaId, mensagem? }` |
| GET | `/conect/notificacoes` |
| POST | `/conect/notificacoes/{id}/lida` |

### Empresa (`Authorization: Bearer`, nível Empresa)

| POST | `/conect-empresa/auth/login` |
| POST | `/conect-empresa/auth/register` |
| GET | `/conect-empresa/me` |
| PUT | `/conect-empresa/me` |
| GET | `/conect-empresa/vagas` |
| POST | `/conect-empresa/vagas` |
| PUT | `/conect-empresa/vagas/{id}` |
| POST | `/conect-empresa/vagas/{id}/acao` — body `{ acao }` |
| GET | `/conect-empresa/candidaturas?vagaId=&status=` |
| GET | `/conect-empresa/candidaturas/{id}` |
| PUT | `/conect-empresa/candidaturas/{id}` — body `{ status, mensagemEmpresa? }` |

---

## 7. Telas (UI)

### Portal SPA (`conectajovem`)

| Rota | Página |
|------|--------|
| `/` | Home |
| `/vagas` | Listagem |
| `/vagas/:slug` | Detalhe + candidatar-se |
| `/empresas` | Empresas parceiras |
| `/como-funciona` | Institucional |
| `/login` | Login candidato / empresa |
| `/cadastro` | Cadastro candidato |
| `/cadastro/empresa` | Cadastro empresa |
| `/candidato` | Dashboard candidato (auth) |
| `/empresa` | Dashboard empresa (auth) |

### Master

| URL | Função |
|-----|--------|
| `/master/conect` | Aprovar empresas e vagas |
| `/master/conect-branding` | Logo, hero, textos |
| `/master/conect-relatorios` | KPIs, gráficos, lista candidatos, CSV |

### Painel escola

| URL | Função |
|-----|--------|
| `/painel/conect` | Candidatos da escola |
| `/painel/conect/candidatos/novo` | Cadastro manual |

---

## 8. Deploy

### 8.1 Backend (painel-cti)

1. Git pull / upload dos arquivos PHP alterados
2. Confirmar `.env` (`CONECT_CORS_ORIGINS`, `JWT_KEY`)
3. SQL aplicado (`conect_jovem.sql` + `conect_jovem_analytics.sql` se usar relatórios)
4. Pasta `uploads/img/conect/` gravável
5. Teste: `GET https://admin.ctieducacional.com.br/api/v1/conect/public/branding`
6. Teste CORS: preflight `OPTIONS` → **204**

### 8.2 SPA (conectajovem)

Detalhe passo a passo: **`conectajovem/DEPLOY.md`**

Resumo:

```powershell
cd C:\xampp\htdocs\pjt\conectajovem
npm install
npm run build
git add .
git commit -m "build: deploy Conecta Jovem"
git push origin main
```

→ cPanel → repo → **Deploy HEAD Commit**

### 8.3 Ordem recomendada em release

1. Backend primeiro (API + SQL)
2. Master branding (se houver uploads)
3. Build e deploy SPA
4. Smoke tests (abaixo)

---

## 9. Smoke test (produção)

### Público

- [ ] `https://conectajovem.com.br/` — home carrega, hero/branding
- [ ] `/vagas` — lista (pode estar vazia)
- [ ] API branding retorna JSON 200

### Candidato

- [ ] Login aluno Ascend (aba Candidato/Aluno)
- [ ] Dashboard: perfil, salvar habilidades
- [ ] Candidatar-se em vaga publicada
- [ ] Notificação aparece

### Empresa

- [ ] Cadastro empresa → status pendente
- [ ] Master aprova empresa
- [ ] Login empresa → criar vaga
- [ ] Master publica vaga
- [ ] Vaga visível no portal
- [ ] Candidatura aparece na aba Candidaturas
- [ ] WhatsApp link funciona
- [ ] Pausar / encerrar vaga

### Master

- [ ] `/master/conect` — filas pendentes
- [ ] `/master/conect-branding` — upload logo/hero, portal reflete após Ctrl+F5
- [ ] `/master/conect-relatorios` — KPIs carregam; após SQL analytics, visitantes/shares aparecem

### Analytics (pós-deploy SPA)

- [ ] Navegar no portal → eventos em `cj_analytics_visitas`
- [ ] Compartilhar artigo do blog → registro em `cj_analytics_compartilhamentos`
- [ ] Master relatórios reflete gráficos no período

### Painel escola

- [ ] `/painel/conect` — lista candidatos
- [ ] Cadastro manual candidato

---

## 10. Arquivos-chave (mapa rápido)

### Backend

| Área | Arquivos |
|------|----------|
| Rotas API | `routes/api/v1/conect.php` |
| CORS | `app/Http/Router.php`, `app/Http/Middleware/CorsConect.php` |
| Auth candidato | `app/Controller/Api/Conect/Auth.php`, `ConectCandidatoAuthHelper.php` |
| Auth empresa | `app/Controller/Api/ConectEmpresa/Auth.php` |
| Vagas empresa | `app/Controller/Api/ConectEmpresa/Vagas.php` |
| Candidaturas | `Conect/Candidaturas.php`, `ConectEmpresa/Candidaturas.php` |
| Formação/selo | `ConectCandidatoFormacaoHelper.php` |
| Branding | `Master/ConectBranding.php`, `CjPortalBranding.php`, `BrandingHelper.php` |
| Moderação | `Master/ConectJovem.php` |
| Relatórios | `Master/ConectRelatorios.php`, `ConectRelatoriosHelper.php`, `CjAnalytics.php` |
| Escola | `Admin/ConectJovem.php` |
| Mapper JSON | `ConectApiMapper.php` |

### SPA

| Área | Arquivos |
|------|----------|
| API client | `src/lib/api.ts` |
| Analytics | `src/lib/analytics.ts`, `src/components/AnalyticsTracker.tsx` |
| Branding | `src/context/BrandingContext.tsx` |
| Home | `src/pages/HomePage.tsx`, `src/components/home/*` |
| Candidato | `src/pages/CandidatoDashboardPage.tsx` |
| Empresa | `src/pages/EmpresaDashboardPage.tsx` |
| Auth | `src/pages/LoginPage.tsx` |

---

## 11. Backlog / aprimoramentos futuros

Itens **fora** das fases 1–6 — candidatos a ajustes na próxima rodada:

| Item | Notas |
|------|-------|
| Filtro candidatos por habilidade/curso (empresa) | Previsto no briefing original |
| Push / e-mail transacional | Hoje só notificações in-app |
| Painel escola: relatórios e exportação | CRM já recebe leads externos; Master tem relatórios globais |
| Edição de vaga com re-moderação parcial | Hoje título/descrição em vaga publicada → pendente |
| PWA / notificações mobile | Opcional |
| Módulo `conect_jovem` no plano SaaS | Liberar por escola se necessário |
| Testes automatizados API + E2E | Não implementados |
| i18n | Portal só PT-BR |

---

## 12. Troubleshooting rápido

| Sintoma | Causa provável | Ação |
|---------|----------------|------|
| "Não foi possível conectar à API" | CORS ou backend offline | Verificar `CONECT_CORS_ORIGINS`, Apache, deploy painel |
| OPTIONS 405 | Router sem handler OPTIONS | Confirmar `Router.php` atualizado |
| Login aluno → "Use login empresa" | Aba errada | Aba **Candidato / Aluno**; backend aceita `Cliente` |
| Empresa não publica vaga | Status `pendente` | Master aprovar em `/master/conect` |
| Branding não muda | Cache SPA | Ctrl+F5; logo via API não exige rebuild |
| Upload branding falha | Permissão pasta | `chmod`/gravável em `uploads/img/conect/` |
| Selo não aparece | Certificado não emitido pela escola | Emitir em Certificados admin; não confundir com EAD auto |
| Relatórios sem visitantes | SQL analytics não aplicado | Executar `conect_jovem_analytics.sql`; rebuild/deploy SPA |

---

## 13. Referências cruzadas

- Deploy SPA: [`conectajovem/DEPLOY.md`](../../conectajovem/DEPLOY.md)
- README SPA: [`conectajovem/README.md`](../../conectajovem/README.md)
- Arquitetura geral CTI: [`ARCHITECTURE.md`](../ARCHITECTURE.md) §5.13
- Env exemplo painel: [`.env.example`](../.env.example)
