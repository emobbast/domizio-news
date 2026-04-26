# HANDOFF — Domizio News
**Versione:** v1.8 | **Data:** 26 aprile 2026, fine giornata | **Branch attivo:** develop

> Consolidato da v1.6 (infrastruttura + sessione mattino/pomeriggio) e v1.7 (SSR↔SPA parity, sera). Sostituisce entrambi.

---

## 1. Stack & Infrastruttura

### Server
| Parametro | Valore |
|---|---|
| Provider | AWS EC2 |
| Tipo | t3.micro (2 vCPU, 1GB RAM + 1GB swap) |
| OS | Ubuntu 22.04 LTS |
| IP | `13.62.37.76` |
| Regione | eu-north-1 (Stoccolma) |
| Storage | 20GB EBS (~79% usage) |
| Web server | Apache 2.4 + mod_rewrite |
| Database | MySQL 8.0 |
| PHP | 8.1 |
| SSL | Let's Encrypt (Certbot) |
| Timezone | Europe/Rome |

### Progetto
| Parametro | Valore |
|---|---|
| URL | domizionews.it |
| Tipo | Testata news aggregata locale |
| Comuni | Mondragone, Castel Volturno, Baia Domizia, Cellole, Falciano del Massico, Carinola, Sessa Aurunca |
| CMS | WordPress headless |
| Frontend | SPA vanilla JS (app.js), Material Design 3, mobile-first 430px, viola `#6750A4`, Roboto |
| Plugin | `domizionews-ai-publisher` v8.0 |
| AI | Claude Haiku 4.5, temp 0.7, max_tokens 1800 |
| AI caching | TTL 1h attivo |
| AI density | Density tuning v2 attivo dal 24/4 |
| SEO | SSR is_single + is_page + is_tax(city) + is_category + aggregate post union + **SSR↔SPA HTML parity (dal 26/4 sera)** |
| Deploy | GitHub Actions → SSH → `sudo git reset --hard origin/main` |
| Dominio/email | Tophost |

### Repository & Local Dev
| Parametro | Valore |
|---|---|
| GitHub | github.com/emobbast/domizio-news |
| Branch strategy | `claude/<feature>` → `develop` → `main` |
| LocalWP | `C:\Users\sorre\Local Sites\domizio-news\app\public` |
| WP Admin | `/accesso-domizio`, user `admin` |
| SSH key | `C:\Users\sorre\Desktop\DOMIZIO-NEWS\domizionews-server.pem` |
| Log plugin | `/var/www/html/wp-content/uploads/dnap.log` |

### API Keys (in wp_options)
| Key | Uso |
|---|---|
| `dnap_anthropic_key` | Claude Haiku 4.5 — autoload=off |
| `DNAPP_UNSPLASH_KEY` | Costante in wp-config.php |
| `dnap_telegram_token` | Bot `@domizionews_bot` — autoload=off |
| `dnap_telegram_channel` | `@domizionews` |
| `dnap_feeds` | Array feed RSS (22 attivi) |
| `dnap_token_usage` | Contatori token Claude (30gg rolling) |
| `dnap_vip_tags` | VIP tags system |

---

## 2. File Chiave (post 26/4 sera)

```
wp-content/plugins/domizionews-ai-publisher/
├── domizionews-ai-publisher.php
├── includes/
│   ├── core.php                     # Import + dedup + Telegram + aggregate terms + helper subterms
│   ├── gpt.php                      # Claude API + caching + density v2
│   ├── media.php
│   ├── admin.php
│   ├── scopri.php
│   ├── log.php
│   └── rest.php                     # /domizio/v1/posts con aggregate-aware city filter
└── admin/
    ├── dashboard.php
    └── feeds.php

wp-content/themes/domizionews-theme/
├── index.php                        # SSR rewritten — SPA-style chrome + cards (12 helper functions)
├── functions.php                    # Untouched in v1.7 (SEO callbacks intatti)
├── header.php                       # Material Symbols loaded (ora finalmente usato)
├── footer.php
├── assets/
│   ├── js/app.js                    # STYLES emptied, hydrateTimestamps, .dn-load-more converged
│   └── css/base.css                 # Single source of truth — SSR + SPA condividono tutto
```

**File rimossi da concept (dead code):**
- Classi `.dn-ssr-main`, `.dn-ssr-h1`, `.dn-ssr-content`, `.dn-back-link`, `.dn-breadcrumb*`, `.dn-archive-list`, `.dn-archive-item*`, `.dn-archive-page-label`, `.dn-archive-empty`, `.dn-ssr-footer*` — eliminate da base.css
- Costante `CITY_GOTO_TARGET` — eliminata da app.js (sessione pomeriggio)

---

## 3. Sessione 26 aprile — Stato finale (3 deploy in giornata)

### 3.1 Cities menu asymmetric ✅ DEPLOYATO (mattina)

**Branch:** `claude/cities-menu-asymmetric` (SHA `00e385b`) — merged → main

**Spec:** tab "Città" mostra 7 città individuali (per disambiguazione), home mostra 5 voci con aggregati (per raggruppamento visivo).

**Modifiche:**
- `functions.php`: rimosso filtro server-side `$hidden_from_menu` da `dnapp_rest_config`. REST `/wp-json/dnapp/v1/config` ora ritorna tutte le 9 città.
- `app.js`: nuovo `AGGREGATE_CITY_SLUGS = ['cellole-baia-domizia', 'falciano-carinola']`
- `app.js`: `CITY_SLUG_LABELS` esteso con 'cellole' e 'baia-domizia' (capitalizzazione corretta)
- `app.js`: `buildCities()` chip bar applica `.filter(c => !AGGREGATE_CITY_SLUGS.includes(c.slug))` — tab Città mostra solo 7 individuali
- `buildHome()` invariata: già iterava `CITY_SLUGS` hardcoded (5 voci con aggregati)

### 3.2 Aggregate post union ✅ DEPLOYATO E VERIFICATO (pomeriggio)

**Branch:** `claude/aggregate-post-union` (SHA `a459a76`) — merged → main

**Problema:** i term aggregati (`cellole-baia-domizia`, `falciano-carinola`) avevano 0 post assegnati direttamente. SSR archives e REST ritornavano vuoto.

**Modifiche:**
- `core.php`: nuovo helper `dnap_get_aggregate_city_subterms($slug)` — single source of truth
- `rest.php:119-129`: `/domizio/v1/posts?city=<slug>` espande slug aggregati in tax_query multi-slug con `operator=IN`
- `index.php:35-52`: SSR `is_tax('city')` branch detecta aggregato, switch tax_query da term_id a slug-array
- `app.js`: rimossi 2 blocchi duplicati di merge aggregate; `loadCategoryFeed` ristrutturata in pattern `loadData`

### 3.3 Aggregate "Vedi altro" fix ✅ DEPLOYATO (pomeriggio)

**Branch:** `claude/aggregate-vedi-altro-fix` (SHA `2853669`) — merged → main

**Problema:** dopo aggregate-post-union, `CITY_GOTO_TARGET` in app.js:204-207 diventato dannoso — redirezionava aggregati al sub-term (cellole-baia-domizia → cellole) facendo perdere all'utente il contesto della pagina aggregata.

**Modifiche:**
- Rimossa costante `CITY_GOTO_TARGET` (4 righe)
- Inlinato `slug` direttamente dove veniva usato `CITY_GOTO_TARGET[slug] || slug`
- 2 file changed, +3 -9

### 3.4 SPA pagination Città tab ✅ DEPLOYATO (sera, parte 1)

**Branch:** `claude/spa-pagination-vedi-altro` (SHA `720efdb`) — merged → main

**Spec:** "Vedi altro" anche su tab Città SPA con cumulative loading.

**Modifiche:**
- `loadCityFeed(slug, page=1, append=false)` — 3-param signature
- `state.cityPage`, `state.cityFeedTotalPages` aggiunti
- `buildCities()` emette `<a class="dn-load-more dn-btn-primary">` con dataset SPA
- Handler `.dn-load-more` esteso con `isSpaContext = !!loadMore.closest('.dn-feed')` per discriminare SSR archive (insertion `dn-archive-item`) da SPA tab (insertion `dn-card-list`). **Nota: questa logica viene poi rimossa nella v1.7 (3.5) quando i due DOM convergono.**

### 3.5 SSR ↔ SPA HTML parity (Fase 3 hydration) ✅ DEPLOYATO (sera, parte 2)

**Branch:** `claude/ssr-spa-html-parity` (SHA `5008735`) — merged → main

**Goal:** eliminare la divergenza visiva e strutturale tra SSR e SPA. Una sola sorgente CSS, hydration takeover invisibile.

**Touched files:**
- `wp-content/themes/domizionews-theme/index.php` (+445 / -147)
- `wp-content/themes/domizionews-theme/assets/css/base.css` (+245 / -145)
- `wp-content/themes/domizionews-theme/assets/js/app.js` (+79 / -303)
- `CLAUDE.md` (changelog appended)

**Decisioni chiave:**

1. **`timeAgo` decision A** — SSR emette data assoluta come label iniziale dentro ogni `.dn-time` span + ISO timestamp in `data-timestamp`. JS function `hydrateTimestamps(root)` (`app.js:37`) riscrive ogni label nel formato relativo dopo render() e dopo boot() in hydration mode. Cache-safe.

2. **Excerpt rimosso da SSR card** — SPA card non hanno excerpt; SSR ora matcha esattamente.

3. **Handler `.dn-load-more` convergente** — rimossa branch `isSpaContext`. SSR + SPA emettono identico `.dn-feed > .dn-card-list` shape, single code path. (Reverte la divergenza temporanea introdotta in 3.4.)

4. **Bottom nav, chip bar, card, logo, footer link emessi come `<a>` su SSR** — JS-off users hanno href reali; SPA handler usano `e.preventDefault()` per restare client-side in hydration mode.

5. **Hydration marker rewritten** — class `.dn-archive-city`/`.dn-archive-category` su `<main>` sostituite da `data-ssr-archive="city|category"` su `.dn-screen` wrapper.

6. **Footer + bottom nav mantenuti su SSR single-article view** (delta vs SPA che li omette). Utili per crawler e JS-off users; SPA replace innerHTML su takeover quindi nessun conflitto.

**Helper inventory (`index.php`):**

| Helper | Mirrors | Lines |
|---|---|---|
| `dnapp_ssr_city_label($slug)` | `CITY_SLUG_LABELS` | 9 |
| `dnapp_ssr_top_header()` | `buildHeader()` (app.js:579) | ~12 |
| `dnapp_ssr_bottom_nav($active_tab)` | `buildNav()` (app.js:1010) | ~22 |
| `dnapp_ssr_footer()` | `buildFooter()` (app.js:723) | ~18 |
| `dnapp_ssr_city_chips($active_slug)` | chip bar of `buildCities()` | ~18 |
| `dnapp_ssr_category_chips($active_cat)` | `buildCategoryChipsBar()` | ~24 |
| `dnapp_ssr_pick_city($post_id)` | aggregate-filtered city pick | 8 |
| `dnapp_ssr_post_image($post_id, $size)` | featured + Unsplash fallback | 6 |
| `dnapp_ssr_article_card($post_id)` | `buildArticleCard()` (app.js:491) | ~30 |
| `dnapp_ssr_hero_card($post_id)` | `buildHeroCard()` (app.js:475) | ~32 |
| `dnapp_ssr_detail_header($title)` | sticky title bar | 5 |
| `dnapp_ssr_home_city_section($slug)` | section of `buildHome()` (app.js:692) | ~50 |

Tutti usano `esc_html()` / `esc_url()` / `esc_attr()` rigorosamente.

**Render branches (`index.php`):**

| ID | When | Body markup |
|---|---|---|
| **S1** | `is_single()` | `.dn-detail-header` + `.dn-detail-img-wrap` + `.dn-detail-body` (badges + h1 + `.dn-byline` con `.dn-time[data-timestamp]` + `.dn-detail-content`) + footer + bottom nav |
| **S2** | `is_page()` | top header + `.dn-legal-page` (detail header + `.dn-legal-content`) + footer + bottom nav |
| **S3+S4** | `is_tax('city')` o `is_category()` | top header + `.dn-screen[data-ssr-archive]` (detail header + chip bar + `.dn-feed` + "Vedi altro") + footer + bottom nav |
| **S5** | home fallback | top header + `.dn-screen` (category chip bar "Tutte" active + 5 city sections) + footer + bottom nav (`home`) |
| **S6** | 404 force-home | identico a S5; `wp_robots` aggiunge `noindex` |

Slider (`buildSlider`) intenzionalmente omesso su SSR — UX flair, non SEO-critical.

**CSS migration (`base.css`):**
- `@import` Roboto upgraded `400;500;700` → `300;400;500;700`
- Nuovo `:root` MD3 variables block
- ~245 righe di component CSS migrate da `app.js STYLES`
- Anchor variants aggiunte (`a.dn-chip`, `a.dn-card-list`, `a.dn-card-hero`, `a.dn-section-label`, `a.dn-nav-tab`) con `text-decoration:none; color:inherit`
- Dead rules eliminate (vedi sezione 2)
- Mantenuto: `.dn-btn-primary` + `:hover` (usato da `.dn-load-more` su entrambe le superfici)

**JS changes (`app.js`):**
1. `STYLES` const svuotato a `''` (line 1065). Quattro siti `<style>${STYLES}</style>` in `render()` lasciati intatti (empty `<style>` tag innocuo).
2. `hydrateTimestamps(root)` aggiunto a line 37; chiamato da render() end, boot() hydration mode, `.dn-load-more` handler.
3. SPA `buildArticleCard` e `buildHeroCard` aggiornati a emettere `data-timestamp`.
4. `.dn-load-more` handler convergente — single code path.
5. `boot()` hydration detection switched da class selector a attribute selector `[data-ssr-archive]`.
6. `e.preventDefault()` aggiunto su handler per `#dn-logo-home`, `#dn-article-logo`, `[data-home-cat]`, `[data-goto-city]`, `[data-city]`, `[data-tab]`. Conditional su `[data-post-id]`.

**SEO verification (untouchable rule rispettata):**
- `functions.php` is untouched (`git diff HEAD -- functions.php` empty)
- Tutte le `wp_head` callbacks, filter, JSON-LD emission vivono in `index.php` lines 111-219 (setup block, prima di `get_header()`)
- Body markup region (line 540+) emette zero `<meta>` / `<link rel>` / JSON-LD scripts
- Validati: pre_get_document_title, document_title_parts, meta description, og:*, canonical, prev/next, BreadcrumbList JSON-LD, ItemList + NewsMediaOrganization, wp_robots, template_redirect 404→home

**Performance check:**
- Home SSR (S5): 5 `WP_Query` con `no_found_rows=true` + 1 ItemList JSON-LD + 1 SEO meta = 7 queries totali. Within budget.
- Aggregate cities usano multi-slug `tax_query` via `dnap_get_aggregate_city_subterms()`.

---

## 4. Architettura post 26/4

```
┌────────────────────── BROWSER ─────────────────────┐
│                                                    │
│  /citta/carinola/  →  Apache → WordPress → SSR    │
│                       index.php emits             │
│                       <div class="dn-screen"      │
│                            data-ssr-archive=     │
│                            "city">              │
│                                                    │
│  ↓ HTML byte-identical (modulo data-attrs)        │
│  ↓ a quello che SPA renderizzerebbe                │
│                                                    │
│  ┌─── browser parses + paints ────────────────┐  │
│  │  Material Symbols loaded (header.php:11)   │  │
│  │  base.css con tutti gli stili SPA          │  │
│  │  → user vede l'UI finale ESATTA            │  │
│  └────────────────────────────────────────────┘  │
│                                                    │
│  ↓ DOMContentLoaded → app.js boot()               │
│                                                    │
│  ┌─── boot() ─────────────────────────────────┐  │
│  │  rootEl.querySelector('[data-ssr-archive]')│  │
│  │  found → hydration mode                    │  │
│  │  state.hydrated = true                     │  │
│  │  state.tab/selectedCity set from URL       │  │
│  │  setupGlobalDelegation()                   │  │
│  │  hydrateTimestamps(rootEl) ← rewrite       │  │
│  │       date assolute → "2h fa"              │  │
│  │  loadData() ← popola state, no render     │  │
│  │  return — NO innerHTML replacement         │  │
│  └────────────────────────────────────────────┘  │
│                                                    │
│  ↓ user clicca tab/chip/card/logo                 │
│                                                    │
│  ┌─── setState() ─────────────────────────────┐  │
│  │  state.hydrated = false                    │  │
│  │  render()                                  │  │
│  │  → root.innerHTML = ...SPA HTML...         │  │
│  │  → hydrateTimestamps(root)                 │  │
│  │  (DOM identico a quello prima;             │  │
│  │   no flash perché entrambe versioni        │  │
│  │   dipingono lo stesso markup.)             │  │
│  └────────────────────────────────────────────┘  │
│                                                    │
└────────────────────────────────────────────────────┘
```

---

## 5. Roadmap Hydration "True takeover"

| Fase | Cosa | Effort | SHA | Stato |
|---|---|---|---|---|
| 1 | Aggregate post union | 45 min | `a459a76` | ✅ Deployato |
| 1.5 | Fix "Vedi altro" aggregati (CITY_GOTO_TARGET) | 5 min | `2853669` | ✅ Deployato |
| 2 | SPA paginazione + "Vedi altro" in buildCities | 35 min | `720efdb` | ✅ Deployato |
| 3 | SSR pixel-identical (card + chrome + CSS unification) | 4h | `5008735` | ✅ Deployato |
| 4 | Hydration true takeover boot() | 30 min | — | 🔜 Possibile prossimo (vedi nota) |

**Nota Fase 4:** la v1.7 ha già parzialmente implementato l'hydration takeover — `boot()` rileva `[data-ssr-archive]` e va in modalità hydration senza fare innerHTML replacement. Il `hydrateTimestamps()` rinfresca le date. Il marker class-based è stato sostituito da data-attribute. Quindi Fase 4 originale potrebbe essere già al 70%; resta da valutare se serve ancora un branch separato o se il lavoro è già confluito qui.

---

## 6. Bug Status — 26 aprile fine giornata

### ✅ Risolti oggi (5 deploy)

| Bug/feature | Branch | SHA |
|---|---|---|
| Cities menu asymmetric | cities-menu-asymmetric | 00e385b |
| Aggregate post union | aggregate-post-union | a459a76 |
| Vedi altro aggregati (CITY_GOTO_TARGET) | aggregate-vedi-altro-fix | 2853669 |
| SPA pagination Città | spa-pagination-vedi-altro | 720efdb |
| SSR ↔ SPA HTML parity | ssr-spa-html-parity | 5008735 |

### 🟠 Possibile prossimo
- Fase 4 hydration takeover — verificare se v1.7 lo copre o serve fine-tuning separato

### 🟠 P1 aperti (preesistenti)
- **#URL-tab-sync** — Tab principali (Home, Città, Scopri, Cerca) non aggiornano URL su click
- **#44** Dedup cross-slot slider — `rest.php:25-85`
- **#45** `stick_post()` accumulo infinito (33 sticky dead) — `core.php:842-843`
- **#46** Import VIP substring match — `core.php:546`
- **#47** `_is_sticky` post_meta dead write — `core.php:842`
- **#48** Priority 1 VIP match troppo largo — `rest.php:57-74`
- **#11** `dnap_create_default_cities` non gira su deploy
- **#30** Query N+1 sticky_per_city

### 🟡 P2 aperti
- **#34** rsync+symlink deploy atomico
- **#35** CI gate `php -l` pre-deploy
- **#36** opcache reload post-deploy
- **#CSS-unification** — ✅ RISOLTO con v1.7 SSR↔SPA parity (era previsto naturalmente con Fase 3)
- **#import-aggregate-cities** — Plugin auto-assegna aggregati ai nuovi articoli (ora meno urgente con post union server-side)
- **#42** preconnect domini esterni
- **#sitemap-aggregates** — Aggregati non in `wp-sitemap-taxonomies-city` (count=0). Custom provider, ~30 min

### 🟢 Risolti per side-effect v1.7
- Footer markup divergence SSR/SPA — unificato via helper
- Material Symbols caricato ma non usato su SSR — ora usato per chrome
- Roboto weight 300 mismatch SPA/base.css — allineato

### Task non-bug
- 16 simboli Unsplash da scaricare manualmente
- AdSense re-submit (24/4 +48h finestra) — DA FARE

---

## 7. Manual QA checklist v1.7 (da verificare post-deploy)

- [ ] `/` (home) renderizza con 5 city sections, ognuna con 1 hero + 2 list cards. Top header + category chips + bottom nav presenti.
- [ ] `/citta/carinola/` mostra top header → "Carinola" detail header → city chip bar (Carinola active) → article cards → "Vedi altro" se >20 post.
- [ ] `/citta/cellole-baia-domizia/` mostra post merged Cellole + Baia Domizia; chip bar mostra 7 città individuali.
- [ ] `/category/cronaca/` mirrora con "Cronaca" header + category chip bar.
- [ ] `/<articolo-slug>/` (single article) mostra `.dn-detail-header` (back arrow + logo + share placeholder) + image + badges + h1 + byline + content + footer + bottom nav.
- [ ] `/chi-siamo/` (legal page) mostra top header + page title in detail header + content + footer + bottom nav.
- [ ] **Con JavaScript disabilitato**: ogni link in chip bar, bottom nav, footer, card, logo, "Vedi altro" funziona. No 404 eccetto `/citta/` (force-home noindex).
- [ ] **Con JavaScript abilitato** landing su `/citta/carinola/`: SSR visibile, SPA boot in hydration mode, **no flash**, date riscritte da "26 apr 2026" a "2h fa". Click "Vedi altro" → SPA fetcha page 2 e appende card nello stesso .dn-feed senza scroll loss.
- [ ] Dopo SPA boot, click logo → home view (no full reload). Click bottom nav tabs → SPA switcha view.
- [ ] Page title `<title>` e meta description match expected per route.
- [ ] BreadcrumbList JSON-LD valida in https://search.google.com/test/rich-results.

---

## 8. Metriche

| Metrica | Valore |
|---|---|
| Articoli pubblicati | ~2700 |
| Import ratio | ~5 articoli/run ogni 30min (~120/giorno) |
| Densità articoli (post density-v2) | ~1378 char media |
| Cache hit rate Claude | ~88% |
| Errori per run | 0 |
| AdSense | Rejection 24/4 — fix completati, re-submit pianificato |

### Costi mensili stimati

| Voce | Costo |
|---|---|
| Claude Haiku 4.5 | ~$21 |
| AWS EC2 t3.micro | ~$8 |
| Dominio Tophost | ~€1.7 |
| **Totale** | **~$31/mese** |

---

## 9. Prossimi Step (in ordine)

### 🔴 Imminente (prossima sessione)

1. **Manual QA checklist v1.7** completa (sezione 7) — verifica visiva su prod su tutti i path principali, sia con JS che senza.
2. **Re-submit AdSense** — finestra ottimale ora (+48h dal deploy 24/4 e con SSR/SPA parity live, l'esperienza utente è massimamente coerente).
3. **Ping Google Search Console** sui URL aggregati `/citta/cellole-baia-domizia/` e `/citta/falciano-carinola/` per accelerare indicizzazione.

### 🎯 Medio termine

4. **Fase 4 hydration** (eventuale) — verificare se serve ancora separatamente dopo v1.7.
5. **VIP/Slider bug #44-48** — pulizia accumulo sticky_post + dedup cross-slot.
6. **#URL-tab-sync** — sincronizzare URL su click tab principali.
7. **#sitemap-aggregates** — custom sitemap provider per aggregati.

### 🟢 Side opportunities

8. **Quattro siti `<style>${STYLES}</style>` in render()** — possono essere rimossi (STYLES ora è `''`). Out of scope di v1.7 per limitare surface area.
9. **Footer SPA hardcoded "© 2026"** (`app.js:734`) — passare a dinamico al rollover anno.

---

## 10. Decisioni Prese 26/4 con Razionale

### Mattino/pomeriggio
- **Cities menu asimmetrico**: tab Città = vista esplorativa per disambiguazione → 7 individuali; home = vetrina con raggruppamenti naturali → 5 con aggregati. Due contesti diversi, logiche diverse.
- **Filtro server-side rimosso, filter client-side per superficie UI**: REST ritorna dataset completo, ogni vista applica il proprio filtro.
- **Aggregate post union server-side (non WP-CLI)**: zero data migration, funziona per tutti i post esistenti e futuri. Single source of truth via `dnap_get_aggregate_city_subterms()`.
- **CITY_GOTO_TARGET rimosso**: era valido pre-server-union, post-deploy diventa dannoso. Inline slug = stessa logica, meno indirezione.

### Sera (v1.7 SSR↔SPA parity)
- **timeAgo decision A (data-timestamp + JS rehydration)**: cache-safe. Server emette label assoluta + ISO; JS riscrive a "Nh fa" dopo boot. Date mai stantie nemmeno con page cache.
- **Excerpt drop su SSR card**: SPA non lo ha, allineamento totale. Riduce divergenza visiva e snellisce loop.
- **`.dn-load-more` handler convergente**: dopo che SSR e SPA emettono identico DOM, la logica `isSpaContext` perde senso. Single code path = meno superficie d'attacco bug.
- **Bottom nav + chip + card + logo come `<a>` su SSR**: progressive enhancement reale. JS-off users navigano via href; SPA usa preventDefault per stay client-side.
- **Hydration marker via `data-ssr-archive` invece di class**: più semantico, separa marker funzionale da styling.
- **Footer + bottom nav su SSR single article (delta vs SPA)**: utile per crawler e JS-off; SPA replace innerHTML quindi nessun conflitto runtime.
- **Slider omesso su SSR**: pure UX flair, nessun impatto SEO. Mantiene SSR snello e veloce.
- **`functions.php` intoccato**: prova rigorosa che il lavoro v1.7 è body-only. SEO tutto in wp_head, callback intatti.

### Pattern operativi consolidati
- **Audit-prima-di-implementare**: quando ci sono dubbi sul codice (selettori, signature, attributi), Claude chat genera prompt PHASE 1 read-only, lancia, poi sulla base dell'output reale costruisce PHASE 2 implement. Mai indovinare. Mai chiedere all'utente di copiare codice.
- **Pattern audit → review → implement**: tre prompt separati. Ogni prompt ha output deterministico. L'utente sa sempre dove siamo.

---

## 11. Comandi Utili

### SSH server
```bash
ssh -i "C:\Users\sorre\Desktop\DOMIZIO-NEWS\domizionews-server.pem" ubuntu@13.62.37.76
```

### Import manuale
```bash
cd /var/www/html && sudo -u www-data wp eval 'dnap_import_now();' && sudo tail -50 /var/www/html/wp-content/uploads/dnap.log
```

### Verifica cache Claude
```bash
sudo grep "Claude usage" /var/www/html/wp-content/uploads/dnap.log | tail -15
```

### Verifica aggregati post union
```bash
curl -s "https://domizionews.it/wp-json/domizio/v1/posts?city=cellole-baia-domizia&per_page=10" | head -c 1500
curl -s "https://domizionews.it/citta/cellole-baia-domizia/" | sed -n '/<body/,$p' | head -c 2000
```

### Verifica SSR↔SPA parity post v1.7
```bash
# 1. SSR puro (no JS): scarica HTML e ispeziona
curl -s "https://domizionews.it/citta/carinola/" | grep -E '(dn-screen|dn-card-list|dn-bottom-nav|data-ssr-archive)' | head -10

# 2. Dead class check (deve essere VUOTO)
curl -s "https://domizionews.it/citta/carinola/" | grep -E '(dn-ssr-|dn-archive-)' || echo "Dead classes assenti — OK"

# 3. SEO intatto (BreadcrumbList JSON-LD presente)
curl -s "https://domizionews.it/citta/carinola/" | grep -A 2 '"BreadcrumbList"' | head -5
```

### Lista term city con count
```bash
sudo -u www-data wp term list city --format=table --fields=term_id,slug,name,count
```

### Sblocco lock manuale
```bash
sudo -u www-data wp option delete dnap_import_lock
```

### Backup DB compresso (da PC)
```bash
ssh -i "C:\Users\sorre\Desktop\DOMIZIO-NEWS\domizionews-server.pem" ubuntu@13.62.37.76 "cd /var/www/html && sudo -u www-data wp db export - | gzip" > "C:\Users\sorre\Desktop\DOMIZIO-NEWS\backup-prod-$(date +%Y%m%d-%H%M%S).sql.gz"
```

### Deploy flow standard (comando unico)
```bash
cd "C:/Users/sorre/Local Sites/domizio-news/app/public" && git checkout develop && git pull origin develop && git fetch origin && git merge origin/claude/<BRANCH> --no-edit && git push origin develop && git checkout main && git pull origin main && git merge develop --no-edit && git push origin main && git checkout develop
```

---

## 12. Regole Operative

### Claude Code
- Prompt sempre in inglese
- Mai toccare `develop` o `main` direttamente — solo `claude/<feature-name>`
- `git -c commit.gpgsign=false commit`
- Verifica push con `git ls-remote origin claude/<branch>`
- Force-push: solo `--force-with-lease`
- Mai API key o token in commit/chat
- Pattern: Phase 1 audit read-only → Phase 2 implement → Phase 3 commit/push/verify
- Ogni prompt termina con istruzione di aggiornare `CLAUDE.md` in repo root
- A fine sessione, creare nuovo `HANDOFF-v[major].[minor]-[YYYYMMDD].md` in `docs/handoff/`

### Audit preventivo (regola consolidata 26/4 sera)
Quando Claude (chat) ha dubbi sullo stato attuale del codice — selettori CSS, struttura DOM, signature di funzioni, attributi data-*, classi specifiche, contenuto esatto di un blocco — NON deve indovinare né chiedere all'utente di copiare codice manualmente. Deve invece:
1. Generare un prompt PHASE 1 read-only per Claude Code
2. Far girare l'audit
3. Solo dopo, sulla base dell'output reale, generare il prompt di implementazione PHASE 2

Pattern: **audit → review output → implement**. Mai implement diretto se ci sono dubbi sullo stato corrente.

### 🔴 Regola SSR↔SPA parity (consolidata 26/4 sera, post v1.7)
**Dopo v1.7, SSR e SPA condividono lo stesso DOM e lo stesso CSS.** Ogni modifica su una superficie DEVE essere replicata sull'altra, oppure la parità si rompe e l'hydration takeover smette di essere invisibile (riappare il flash che v1.7 ha eliminato).

**Cosa significa concretamente:**

| Modifica su... | Va replicata su... |
|---|---|
| `app.js` `buildArticleCard()` | `index.php` `dnapp_ssr_article_card()` |
| `app.js` `buildHeroCard()` | `index.php` `dnapp_ssr_hero_card()` |
| `app.js` `buildHeader()` | `index.php` `dnapp_ssr_top_header()` |
| `app.js` `buildNav()` | `index.php` `dnapp_ssr_bottom_nav()` |
| `app.js` `buildFooter()` | `index.php` `dnapp_ssr_footer()` |
| `app.js` `buildCities()` chip bar | `index.php` `dnapp_ssr_city_chips()` |
| `app.js` `buildCategoryChipsBar()` | `index.php` `dnapp_ssr_category_chips()` |
| `app.js` `buildHome()` city section | `index.php` `dnapp_ssr_home_city_section()` |
| Nuova classe CSS in `STYLES` | `base.css` (STYLES dovrebbe restare vuoto) |
| Nuovo data-attribute SPA | Stesso attribute in helper SSR |
| Nuova icona Material Symbols | Già caricata da `header.php`, ok riusare |

**Regola operativa per Claude Code:**
- Ogni prompt che modifica un componente UI DEVE includere esplicitamente "modifica anche l'helper SSR equivalente" come task
- Il prompt deve listare entrambi i file da editare (`app.js` E `index.php`) negli `git add`
- Il commit message deve menzionare entrambe le superfici (es. "feat: nuovo X (SPA + SSR)")
- Se la modifica è SOLO su una superficie per scelta esplicita, documentarlo come "delta intentional" in CLAUDE.md (vedi precedenti: footer SPA omits article-detail, slider SSR-omitted)

**Regola operativa per Claude (chat):**
- Quando l'utente chiede una modifica UI, valutare SEMPRE l'impatto su entrambe le superfici prima di generare il prompt
- Se l'utente non specifica, partire dall'assunzione "modifica entrambe" e chiedere conferma solo se ha senso un delta intenzionale
- Includere nel prompt una mini-checklist finale "verifica che SPA e SSR producano DOM identico per il componente toccato"

**Eccezioni documentate (delta intentional ammessi):**
- SPA single-article view omette footer + bottom nav (SSR li mantiene per crawler/JS-off)
- SSR home omette slider (UX flair SPA-only)
- SSR cards non hanno excerpt (matcha SPA)
- SPA share button visibile solo dopo boot (richiede `navigator.share`)
- SPA avatar e search button solo decorativi su SSR finché JS non aggancia listener

Tutti gli altri delta sono **bug** e vanno corretti subito, non documentati.

### Regole Project Claude
- **SSH login premesso** SEMPRE a ogni blocco comandi server
- **Deploy comando unico** concatenato con `&&`
- **Spiegazioni sintetiche** (3-5 righe) quando utente chiede sintesi
- **Italiano** nel chat, tono amichevole e diretto

### Regole Handoff
- Versioning incrementale: `HANDOFF-v1.X-YYYYMMDD.md`
- A fine sessione genera nuova versione in `docs/handoff/`
- CLAUDE.md in repo aggiornato in parallelo da Claude Code
- Quando un HANDOFF nuovo consolida più sessioni precedenti, esplicitarlo in nota apertura

---

## 13. Contatti e Risorse

- **Google Search Console:** sitemap `/wp-sitemap.xml`
- **Google Publisher Center:** configurato
- **Pagina Facebook:** facebook.com/domizionews
- **AdSense:** `ca-pub-6979338420884576`
  - Rejection 24/4: "contenuti scarso valore" + "schermate senza contenuti"
  - Fix completati 24/4 sera (density v2 + Fix Pack C)
  - Ulteriori miglioramenti coerenza UX 26/4 sera (SSR↔SPA parity)
  - **Re-submit pianificato prossima sessione**
- **Slot AdSense:** home-feed `6860504195`, article-bottom `3708427948`, banner-nav `7559376761`

---

*Fine HANDOFF v1.8 — 26 aprile 2026, fine giornata*

*Sessione 26/4: 5 deploy in giornata (cities asymmetric → aggregate union → vedi-altro fix → SPA pagination → SSR/SPA parity)*

*Roadmap hydration: Fase 1, 1.5, 2, 3 ✅ — solo Fase 4 (eventuale) residua*

*Prossima priorità: QA checklist v1.7 → re-submit AdSense → ping GSC → valutazione Fase 4*
