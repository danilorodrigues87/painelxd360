# Prospecção de empresas (Master)

Módulo **Master → Prospecção → Empresas (Maps)** (`/master/prospeccao-empresas`).

Busca estabelecimentos via **Google Places API**, salva no MySQL e permite listar/exportar **sem nova cobrança** da API.

---

## 1. Instalação SQL

Execute no phpMyAdmin:

```
database/master_prospeccao_empresas.sql
```

Tabela: `master_prospeccao_empresas`

---

## 2. Google Cloud (API paga — usar com moderação)

1. [Google Cloud Console](https://console.cloud.google.com/) → projeto + **faturamento ativo**.
2. **APIs & Services → Library** → habilitar **Places API (New)**.
3. **Credentials → Create API key**.
4. Restringir a chave:
   - **Application restrictions:** IP do servidor (HostGator) ou IP local em dev.
   - **API restrictions:** apenas **Places API (New)**.
5. No `.env` do painel:

```env
GOOGLE_PLACES_API_KEY=sua_chave_aqui
```

**Custo referência:** Text Search ~US$ 0,032 por página (até 20 resultados). **Carregar mais** = nova requisição.

---

## 3. Uso no painel

### Base local (grátis)

- Abrir a tela → lista vem do **banco**.
- Filtrar por nome/endereço, “só com WhatsApp”.
- **Exportar CSV** — dados completos salvos.
- **Excluir** registro da base local.

### Importar do Google (pago)

- Card amarelo **“Importar do Google”**.
- **Buscar e salvar** — 1 página (20 resultados).
- **Todas as páginas** — até 3 páginas da busca atual (~60 resultados).
- **Só cidade** (ex.: `Guapiara SP`) — aparecem botões extras:
  - **Por categorias** — 15 segmentos (comércio, restaurantes, clínicas…) × 1 página cada.
  - **Importação completa** — busca geral + 15 categorias × até 3 páginas (~48 requisições, ~US$ 1,50).
- **Cidade + segmento** (ex.: `padaria em Ribeirão Preto SP`) → busca específica.
- **Carregar mais** — próxima página da última busca simples.
- Limite de segurança: **80 requisições/hora** por IP no painel.

**Custo referência:** Text Search ~US$ 0,032 por requisição.

---

## 4. Campos salvos

| Campo | Descrição |
|-------|-----------|
| Nome, endereço | Google Places |
| Telefone / WhatsApp | Link `wa.me/55...` quando houver número |
| Link Maps, site, nota | Quando disponíveis |
| Query origem | Texto da busca que importou |
| Importado / atualizado | Datas no banco |

---

## 5. Limitações

- Busca por **cidade** não traz 100% das empresas do município — o Google limita resultados por query (~60). **Importação completa** maximiza cobertura combinando categorias.
- Nem todo estabelecimento tem telefone no Google.
- Dados podem ficar desatualizados até nova importação.
- Sem API key: lista/CSV funcionam; importação Google bloqueada.

---

## 6. Arquivos

| Área | Caminho |
|------|---------|
| SQL | `database/master_prospeccao_empresas.sql` |
| Controller | `app/Controller/Master/ProspeccaoEmpresas.php` |
| Google API | `app/Common/Helpers/GooglePlacesHelper.php` |
| Listagem/CSV | `app/Common/Helpers/ProspeccaoEmpresasHelper.php` |
| Entity | `app/Model/Entity/ProspeccaoEmpresa.php` |
| View/JS | `resources/view/master/modules/prospeccao-empresas/`, `resources/js/master-prospeccao-empresas.js` |
