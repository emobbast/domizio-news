# HANDOFF — Domizio News
**Versione:** v1.9 | **Data:** 26 aprile 2026, sera tardi | **Branch attivo:** develop

> Consolida v1.8 (5 deploy della giornata) aggiungendo: 6° deploy (paged404+vedialtro-style), verifica visiva SSR/SPA parity completata, audit completo bug immagini Google News + duplicati contenuti, decisione strategia SEO per cancellazioni. Sostituisce v1.8.

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

### 3.6 Paged archive 404 fix + Vedi altro style align ✅ DEPLOYATO (sera tardi)

**Branch:** `claude/paged404-and-vedialtro-style` (SHA `6b9fac8`) — merged → main

**Problema A (CRITICO SEO):** dopo v1.7 deploy, gli URL `/citta/<slug>/page/N/` venivano assorbiti dal force-home fallback con noindex. Causa: mismatch tra global query `posts_per_page` (~100, da Reading Settings) e SSR archive query `posts_per_page=20`. SSR emetteva link "Vedi altro" che superavano il `max_num_pages` della global, triggerando `handle_404` → `template_redirect` force-home + `noindex`. Tutte le pagine paged di city con <100 post erano deindicizzate da Google.

**Problema B (cosmetico ma SSR↔SPA parity):** bottone "Vedi altro" su SPA Città tab + SSR archivi usava `.dn-btn-primary` (rettangolo viola pieno), HOME usava `.dn-city-more` (pillola bianca con icona newspaper). Inconsistenza visiva.

**Modifiche FIX A (`functions.php`):**
- Nuovo filter `pre_get_posts` che setta `posts_per_page=20` su query main archivi city/category. Allineamento global ↔ SSR.
- Defensive guard in `template_redirect`: paged > 1 su archivi city/category bypassano force-home (renderizzano natural 404).

**Modifiche FIX B:**
- Nuovo helper `dnapp_ssr_vedi_altro_button($next_url, $type, $slug, $next_page)` in `index.php` — single source of truth per SSR.
- SPA `buildCities()` (`app.js:778-786`) emette stesso markup: `<div class="dn-city-more-wrap"><a class="dn-load-more dn-city-more">...newspaper icon...Vedi altro</a></div>`.
- `.dn-btn-primary` rule in `base.css` marcato come "cleanup candidate" (regola preservata, comment aggiunto).

**Touched files:** `functions.php` (+30), `index.php` (+32/-7), `app.js` (+7/-5), `base.css` (+1/-2), `CLAUDE.md` (+12).

**Verifica post-deploy:**
- ✅ `/citta/carinola/page/2/` → `data-ssr-archive="city"` + title "Carinola | Domizio News — Pagina 2" + `<meta robots index, follow>` (NO noindex)
- ✅ `/citta/cellole/page/2/` e `/citta/baia-domizia/page/2/` → marker SSR presente
- ⚠️ `/citta/carinola/page/99/` → 200 + noindex + body home. **Delta intentional: soft-404 SEO-safe.** Googlebot non segue link a page out-of-range (segue solo `<a href>` esistenti, e SSR emette solo link a pagine valide). Utenti che digitano manualmente vedono home + noindex, accettabile.

### 3.7 Verifica visiva SSR↔SPA parity (post v1.7 + 3.6) ✅ COMPLETATA

Verifica eseguita dall'utente con JavaScript disabilitato in Chrome (DevTools → Disable JavaScript).

| Path | JS off | JS on | Esito |
|---|---|---|---|
| `/citta/carinola/` | Top header + chip + card thumb + "Vedi altro" pillola bianca + bottom nav + footer | Stesso markup, swap invisibile, date passano da assolute a "Nh fa" | ✅ |
| Click chip città | Browser segue href, full reload | JS intercetta, fluido | ✅ |
| Click "Vedi altro" | Reload + scroll torna in cima (atteso, no JS) | Append card, scroll preservato | ✅ |
| Background `#FEF7FF` | ✅ Coerente | ✅ Coerente | ✅ |
| Material Symbols icons | ✅ Renderizzate (font caricato da `header.php`) | ✅ Renderizzate | ✅ |
| `.dn-ssr-*` classes | ❌ Assenti dal markup (dead code rimosso) | ❌ Assenti | ✅ |

**Conclusione:** la parità SSR↔SPA è confermata. Hydration takeover invisibile come progettato.

### 3.8 Audit Issue 1+2 — Coverage immagini + duplicati contenuti (read-only) ✅ COMPLETATO

Audit diagnostico eseguito su database produzione (1078 post pubblicati totali). Read-only, nessuna modifica.

#### Issue 1 — Image coverage map

**Aggregate totali (1078 post):**

| Categoria | Post | % |
|---|---|---|
| Featured image WP (`_thumbnail_id`) | 624 | 57.9% |
| URL esterno Unsplash (`_dnap_external_image`) | 208 | 19.3% |
| Nessuna immagine | 246 | 22.8% |

Coverage rate totale: 77.2%. Roughly 1 card su 4 mostra placeholder.

**Smoking gun — breakdown per fonte feed:**

| Host | Total | Real thumb | Unsplash | No image | Thumb % |
|---|---|---|---|---|---|
| **news.google.com** | 476 | 29 | 207 | **240** | **6%** |
| ecaserta.com | 133 | 133 | 0 | 0 | 100% |
| edizionecaserta.net | 129 | 129 | 0 | 0 | 100% |
| pupia.tv | 93 | 91 | 0 | 2 | 98% |
| thereportzone.it | 72 | 71 | 0 | 1 | 99% |
| cronachedi.it | 34 | 34 | 0 | 0 | 100% |
| ilmattino.it, ansa.it, altri | 100% | | | | |

**Diagnosi:** feed diretti funzionano al 98-100%. Google News (44% del corpus) è il problema unico. 240/246 post no-image vengono da `news.google.com`. Tutti i 207 placeholder Unsplash vengono da Google News.

**Causa tecnica (`media.php`):**
1. Step 0-3 (RSS enclosure / og:image / inline img): vuoti per Google News (RSS metadata-only)
2. Step 4 (`dnap_fetch_article_image`): early-return se host = google.com (`media.php:385`). Funziona solo se `dnap_resolve_google_news_url` ha decodificato il payload base64 e upgradeato `$source_url` al canonical publisher PRIMA di `dnap_set_featured_image`
3. Step 6 (Unsplash API): solo se `DNAP_UNSPLASH_KEY` definita e ≠ placeholder

I 240 no-image hanno fallito tutti e tre: base64 decode missato + URL non risolto a host non-Google + Unsplash API non risposta (intermittente).

**Distribuzione temporale (NON è artefatto di sviluppo):**
- 2026-02: 8 no-image
- 2026-03: 118 no-image
- 2026-04: 120 no-image

Il problema è **ongoing in produzione**, non legacy.

**Drift documentazione:** CLAUDE.md menziona `_dnap_unsplash_used = 1` ma il codice in `media.php:280` non scrive mai questo meta. Distinzione recuperabile via `_dnap_external_image` host check (Unsplash hostname).

**Render no-image:**
- SSR helper `dnapp_ssr_post_image($post_id)` ritorna empty string → SSR card emette placeholder div 80×80 viola `#EADDFF` con icona Material `article` `#6750A4` (`index.php:422-426`)
- SPA `buildArticleCard` emette identico placeholder (`app.js:511-524`)
- Hero card usa `buildImagePlaceholder()` variante 16:9

#### Issue 2 — Content duplicates

**2.1 Title duplicates esatti (case-insensitive):** 11 gruppi, 23 post

```
4x | 1931, 1936, 1988, 2022 | "Castel Volturno, scoperti 19 lavoratori in nero in un beach club"
2x | 2151, 2170 | "Aggressione a Mondragone: arrestato 37enne con una pala"
2x | 1443, 1948 | "Carinola conferisce cittadinanza onoraria al generale Luongo"
2x | 1995, 2030 | "Castel Volturno celebra il 25 aprile con enogastronomia e musica"
2x | 2388, 2397 | "Castel Volturno, 35enne sorpreso con moto rubata e targa falsa"
2x | 1255, 1256 | "Furto di bici elettrica a Mondragone: denunciato il responsabile"
2x | 1026, 1061 | "Furto in farmacia a Mondragone: arrestato dopo inseguimento"
2x | 2497, 2512 | "Incendio al depuratore aziendale a Sessa Aurunca"
2x | 1926, 1978 | "Incendio distrugge barche in capannone a Castel Volturno"
2x | 1983, 2076 | "Incendio distrugge rimessaggio barche a Castel Volturno"
2x | 1085, 1219 | "Mondragone: due denunciati per guida di auto rubata"
```

**2.2 Near-duplicate titles (60-char normalized):** 14 gruppi (3 extra catch encoding/punteggiatura):
- 921, 2074: "Litorale Domizio, maxi operazione contro l'abusivismo edilizio"
- 741, 760: "Rosa Di Maio guida Fratelli d'Italia a Mondragone"
- 2060, 2109: "Settimana Santa 2026 a Sessa Aurunca con il Vescovo Cirulli"

**2.3 Body content duplicates (200-char normalized):** 0 gruppi. Claude rewrite varia abbastanza da non collidere mai sul body, anche su titoli identici.

**2.4 Cluster Iannitti:** 11 post in 13 giorni. Arc giornalistico legittimo (ritrovamento → arresto → interrogatorio → udienza → scuse). Solo coppia 2366/2378 (4h apart, near-identical) borderline — entity meta gap.

**2.5 Causa dedup pipeline:**

| Layer | Status | Causa fallimento |
|---|---|---|
| Layer 1 (URL/hash/feed-title) | ✅ Funziona | Catch reposts da stesso feed |
| Layer 1.5 (Claude-title 12h) | ⚠️ Window troppo stretta | Misses same-event reposts >12h apart |
| Layer 2a/2b (entity 30gg/72h) | ⚠️ Underfed | Solo 38/1078 post (3.5%) hanno `_dnap_event_entity` |
| Layer 2c (keywords) | ⚠️ Underfed | Solo 85/1078 post (7.9%) hanno `_dnap_event_keywords` |

**Coverage meta keys:**
| Meta | Post con valore | % |
|---|---|---|
| `_source_url` | 1076 | 99.8% |
| `_source_hash` | 1076 | 99.8% |
| `_dnap_event_entity` | 38 | 3.5% |
| `_dnap_event_keywords` | 85 | 7.9% |
| `_dnap_event_city` | 96 | 8.9% |
| `_dnap_event_type` | 124 | 11.5% |

92%+ del corpus si affida solo a Layers 1+1.5. Per eventi senza persona/entità (collective subjects: "scoperti 19 lavoratori", "Settimana Santa") Claude restituisce entity vuoto e keywords sparse → dedup non scatta.

**Conclusione audit:** i due bug sono indipendenti. Immagini = scraping failure Google News-specifico. Duplicati = Claude metadata gap. Entrambi hanno fix code-level disponibili.

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
| 3.6 | Paged 404 fix + Vedi altro style align | 30 min | `6b9fac8` | ✅ Deployato |
| 4 | Hydration true takeover boot() | 30 min | — | 🟢 De facto già implementato in v1.7 |

**Nota Fase 4:** v1.7 ha già implementato l'hydration takeover — `boot()` rileva `[data-ssr-archive]` e va in modalità hydration senza innerHTML replacement. Verificato in 3.7 che lo swap è invisibile. Branch separato per Fase 4 non necessario.

**Roadmap hydration COMPLETATA** ✅ — la fondazione SSR/SPA è stabile.

---

## 6. Bug Status — 26 aprile sera tardi

### ✅ Risolti oggi (6 deploy)

| Bug/feature | Branch | SHA |
|---|---|---|
| Cities menu asymmetric | cities-menu-asymmetric | 00e385b |
| Aggregate post union | aggregate-post-union | a459a76 |
| Vedi altro aggregati (CITY_GOTO_TARGET) | aggregate-vedi-altro-fix | 2853669 |
| SPA pagination Città | spa-pagination-vedi-altro | 720efdb |
| SSR ↔ SPA HTML parity | ssr-spa-html-parity | 5008735 |
| Paged 404 + Vedi altro style align | paged404-and-vedialtro-style | 6b9fac8 |

### 🔴 Bug critici emersi oggi (audit completato, fix da pianificare)

#### **A. Cleanup duplicati DB con strategia SEO 301**
- 23 post duplicati esatti in 11 gruppi (sez. 3.8.2.1)
- **Strategia richiesta:** non solo cancellare, ma reindirizzare 301 dal "perdente" al "vincitore" della coppia per preservare SEO + backlink + indicizzazione Google
- **Vincitore = post più vecchio del gruppo** (probabile già indicizzato + più backlink)
- **Effort:** ~30 min (cleanup) + setup plugin Redirection o regole .htaccess

#### **A2. Recovery URL già cancellati indicizzati da Google**
- Aspetto SEO emerso: cancellazioni passate (es. ~45 Iannitti documentati in v1.6 da pulire) ritornano 404 senza redirect
- **Effort:** 1-2h (audit Google Search Console "Pagine non trovate" + decisione 301 vs 410 per ognuno)
- **Pre-requisito:** verificare se c'è già un plugin redirect installato + check GSC quanti URL 404 sono indicizzati

#### **B. Pipeline immagini Google News broken**
- 246 post no-image (22.8% del corpus), 207 placeholder Unsplash
- Causa: `dnap_resolve_google_news_url` base64 decode + URL upgrade a publisher canonical fallisce, Unsplash fallback intermittente
- **Effort:** 2-3h (patch `media.php`, debug iterativo del payload base64 Google News)
- **Beneficio:** sblocca 50%+ nuovi import; post storici no-image sono persi (fonti hanno perso le immagini originali nel frattempo)

#### **C. Pipeline dedup sotto-alimentata**
- Layer 1.5 finestra 12h troppo stretta
- Layer 2a/2b/2c richiede `_dnap_event_entity`/keywords ma solo 3.5-7.9% dei post li hanno
- **Effort:** 1-2h (patch `core.php`, allargare finestra Layer 1.5 a 72h + tightening Claude prompt per entity extraction sui collective subjects)
- **Beneficio:** preventivo, ferma i prossimi 23+ duplicati

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
- **#42** preconnect domini esterni
- **#sitemap-aggregates** — Aggregati non in `wp-sitemap-taxonomies-city` (count=0). Custom provider, ~30 min
- **#dn-btn-primary-cleanup** — Rule preserved in base.css ma marcato unused. Cleanup branch separato dopo verifica zero consumers altrove.

### 🟢 Risolti per side-effect oggi
- **#CSS-unification** — ✅ Risolto con v1.7 SSR↔SPA parity
- **Paged archive SEO regression** — ✅ Risolto con 3.6 (era stato introdotto da v1.7, fixato stesso giorno)
- Footer markup divergence SSR/SPA — unificato via helper
- Material Symbols caricato ma non usato su SSR — ora usato per chrome
- Roboto weight 300 mismatch SPA/base.css — allineato

### Task non-bug
- 16 simboli Unsplash da scaricare manualmente
- AdSense re-submit (24/4 +48h finestra) — DA FARE
- Verifica Google Search Console su URL 404 indicizzati (pre-requisito A2)

### Soft delta intentional documentati
- `/citta/<slug>/page/99/` (out-of-range) → 200 + noindex su body home. Soft-404 SEO-safe; Googlebot non segue link a pagine inesistenti, utenti raramente digitano URL out-of-range manualmente.

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

**Macro-task A — Strategia SEO post-cancellazione (struttura + framework)**
1. Verificare se plugin redirect è installato (es. "Redirection" by John Godley) — prerequisito per A1+A2
2. Audit Google Search Console → "Pagine non trovate (404)" e "Pagina con reindirizzamento" — capire perimetro reale di URL 404 indicizzati
3. Decidere strategia: 301 (a duplicato sopravvissuto) vs 410 Gone (contenuto morto senza sostituto)

**Task A1 — Cleanup 23 duplicati esatti con redirect 301**
- Per ogni gruppo: identificare il "vincitore" (post più vecchio = più probabile indicizzato + backlink)
- Cancellare i "perdenti" + creare 301 dal loro permalink al vincitore
- ~30 min

**Task A2 — Recovery cancellazioni passate (Iannitti cluster + altri)**
- Audit GSC per quantificare URL 404 indicizzati
- 301 verso post equivalente attuale o 410 Gone se nessun sostituto valido
- 1-2h

**Task B — Fix pipeline immagini Google News**
- Patch `media.php` step 4 (Google News URL resolution) e step 6 (Unsplash retry)
- Beneficio: sblocca 50%+ dei nuovi import Google News
- 2-3h, mente fresca consigliata

**Task C — Fix pipeline dedup**
- Patch `core.php`: Layer 1.5 finestra da 12h → 72h
- Patch Claude prompt: forzare entity extraction anche su collective subjects (gruppi/eventi senza persona singola)
- 1-2h

### 🎯 Dopo cleanup + fix pipeline
- **Re-submit AdSense** — finestra ottimale dopo che immagini Google News sono fixate (sito visivamente più ricco)
- **Ping Google Search Console** sui URL aggregati `/citta/cellole-baia-domizia/` e `/citta/falciano-carinola/`

### 🎯 Medio termine
5. **VIP/Slider bug #44-48** — pulizia accumulo sticky_post + dedup cross-slot
6. **#URL-tab-sync** — sincronizzare URL su click tab principali
7. **#sitemap-aggregates** — custom sitemap provider per aggregati
8. **#dn-btn-primary-cleanup** — rimuovere rule unused dopo verifica zero consumers

### 🟢 Side opportunities

9. **Quattro siti `<style>${STYLES}</style>` in render()** — possono essere rimossi (STYLES ora è `''`).
10. **Footer SPA hardcoded "© 2026"** (`app.js:734`) — passare a dinamico al rollover anno.

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

### Sera tardi (3.6 + audit issue 1+2)
- **Paged 404 SEO regression**: il bug era stato introdotto da v1.7 ma è preesistente nel comportamento (mascherato fino a quando wp_robots era is_paged-aware). Risolto con `pre_get_posts` + defensive guard nello stesso giorno = mai entrato in produzione "stantio".
- **`/citta/<slug>/page/99/` → soft-404**: accettato come delta intentional. Googlebot non segue link inesistenti, utenti raramente digitano URL out-of-range. Trade-off: noindex sulla home se URL fuori range, vs lavoro non banale per ricostruire `is_paged()` post-handle_404. Non vale il rischio.
- **Bottone "Vedi altro" stile pillola bianca per tutte le superfici**: deciso opzione "allinea SPA + SSR a HOME". Coerente con design language Material 3 più morbido.
- **Strategia SEO 301 invece di hard-delete**: per i 23 duplicati e per cancellazioni future, sempre 301 verso il "vincitore" (post più vecchio del gruppo). Massima conservazione SEO + UX (utenti da SERP non vedono 404).

### Pattern operativi consolidati
- **Audit-prima-di-implementare** (consolidata mattino, riapplicata 3 volte nella stessa giornata): per ogni decisione importante, prima audit read-only, poi implement. Ha permesso di evitare regressioni multiple.
- **Pattern audit → review → implement**: tre prompt separati, ogni prompt ha output deterministico, l'utente sa sempre dove siamo.
- **Pattern fix critico + cosmetico stesso branch**: quando i due fix toccano lo stesso file (es. paged404 + Vedi altro style toccano entrambi index.php), branch unico riduce friction senza accoppiare logicamente.

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

*Fine HANDOFF v1.9 — 26 aprile 2026, sera tardi*

*Sessione 26/4: 6 deploy in giornata (cities asymmetric → aggregate union → vedi-altro fix → SPA pagination → SSR/SPA parity → paged404+vedialtro-style)*

*Roadmap hydration COMPLETATA: Fase 1, 1.5, 2, 3, 3.6 ✅. Fase 4 de-facto già implementata in v1.7.*

*Bug critici emersi dall'audit serale: A (cleanup duplicati con SEO 301), A2 (recovery URL già cancellati), B (immagini Google News), C (dedup pipeline). Tutti pianificati con effort stimato.*

*Prossima priorità: Task A — strategia 301 + cleanup duplicati con preservazione SEO.*
