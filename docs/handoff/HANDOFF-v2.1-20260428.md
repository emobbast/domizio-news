# HANDOFF — Domizio News
**Versione:** v2.1 | **Data:** 28 aprile 2026, fine giornata | **Branch attivo:** develop

> Consolida v2.0 aggiungendo sessione 28/4 (continuazione 27 sera tardi): Phase A + Phase B di Dedup Pipeline v2 deployate in produzione. Sito ora cattura `event_date`, `event_scope`, `event_location_city`, `mentioned_cities` da Claude. Skip-too-old gate attivo (14 giorni). Term `litorale-domizio` live. Stato: **monitoraggio post-deploy**, prossima decisione operativa dopo primo cron import.

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

### 3.9 Sessione 27/4 — Cleanup SEO esteso (3 deploy + DB cleanup)

Sessione lunga (8+ ore) con 3 deploy + cleanup DB diretto + esperienza fallimentare plugin Redirection. Risultato netto: stato SEO sito significativamente migliorato.

#### 3.9.1 Audit GSC — Scoperta crisi indicizzazione

Export Coverage da Google Search Console ha rivelato che il sito è in stato **critico SEO**, ben oltre i 13 duplicati DB visti ieri:

| Categoria GSC | Pagine | Severità |
|---|---|---|
| Indicizzate | 93 | — (4.3% del corpus!) |
| Pagina alternativa con canonical appropriato | 65 | OK |
| Pagina duplicata senza canonical (DIVERSI dai 13 DB) | 38 | 🔴 |
| Duplicata con canonical Google diverso | 3 | 🟠 |
| Non trovata (404) | 2 | 🟢 |
| Pagina con reindirizzamento | 2 | 🟢 |
| **Rilevata ma non indicizzata** | **2082** | 🚨 |
| Scansionata ma non indicizzata | 79 | 🟠 |

**Diagnosi via Python pandas analysis dei 1000 URL "rilevata non indicizzata":**

| Tipo URL | % | Note |
|---|---|---|
| Tag pages (`/tag/<keyword>/`) | 58.9% | 🔴 **Causa principale crisi** |
| Singoli articoli | 40.5% | Mai scansionati (data 1970-01-01) |
| Archivio città | 0.5% | OK |
| Author/Category | <1% | Marginale |

**Verifica sitemap effettiva:**

| Sub-sitemap | URL count | Atteso | Anomalia |
|---|---|---|---|
| `posts-post-1.xml` | 1080 | ~1080 | OK |
| `posts-page-1.xml` | 7 | 7 (con home) | OK |
| `taxonomies-category-1.xml` | 9 | 8 | +Uncategorized |
| **`taxonomies-post_tag-1.xml`** | **1490** | **0** | 🔴 PROBLEMA |
| `taxonomies-city-1.xml` | 7 | 9 | -2 aggregati mancanti |
| `users-1.xml` | 1 | 0 | Da rimuovere |

#### 3.9.2 Branch `claude/sitemap-noindex-cleanup` ✅ DEPLOYATO

**SHA:** `32bb96e` — merged → main

**4 fix correlati in `functions.php`:**

1. **`wp_robots` esteso**: `noindex` su `is_tag()` (1490 tag pages — recupera crawl budget)
2. **`wp_sitemaps_taxonomies` esteso** (filtro city già esistente, riutilizzato): `unset($taxonomies['post_tag'])` — sub-sitemap tag rimossa
3. **`wp_sitemaps_add_provider`**: rimuove provider `users` da sitemap (1 admin, già `noindex` via wp_robots esistente)
4. **`wp_sitemaps_taxonomies_query_args`**: esclude term_id=1 (`uncategorized`, count=0 dopo manuale)

**Pre-step manuale:** post ID 2475 ("Processo per corruzione all'Asl") ricategorizzato da `uncategorized` → `cronaca`. Era l'unico post in Uncategorized; categoria ora vuota.

**Verifica post-deploy (curl):**
- ✅ Sitemap index pulita (4 sub-sitemap invece di 6)
- ✅ Tag pages emettono `<meta name='robots' content='max-image-preview:large, noindex, follow' />`
- ✅ Category sitemap mostra 8 categorie (no Uncategorized)
- ⚠️ `wp-sitemap-users-1.xml` URL diretto ritorna soft-404 (force-home + noindex). Delta intentional accettato.

**Effetto SEO atteso (in 14-30 giorni):**
- 1490 URL tag deindicizzati da Google
- Recupero crawl budget per articoli reali
- Counter "Rilevate ma non indicizzate" deve scendere

#### 3.9.3 Branch `claude/favicon-512` ✅ DEPLOYATO

**SHA:** `9bf8b76` — merged → main

**Diagnosi:** WordPress core `wp_site_icon()` emette solo `<link rel="icon">` con `sizes="32x32"` e `sizes="192x192"`. Google SERP cerchietto richiede sizes ≥48×48 (ottimale 512×512). Senza dichiarazione esplicita, Google falleggia con la 192×192 auto-scaled (sfocata nel cerchietto).

**Bug pregresso scoperto (NON sistemato):** WordPress dichiara `sizes="32x32"` per un file 80×80 (mismatch). Innocuo ma da P2.

**Setup pre-fix:** verificato che `/var/www/html/wp-content/uploads/2026/04/logo-domizio-news.png` è già 512×512 originale (non occorre re-upload).

**Fix:** filter `wp_head` priorità 99 che aggiunge:
```html
<link rel="icon" sizes="512x512" href="https://domizionews.it/wp-content/uploads/2026/04/logo-domizio-news.png" />
```

Filtro additivo (priorità 99 dopo core 10), nessuna dichiarazione esistente alterata. Tab browser/iOS/Android invariati, Google SERP riceve nuova dichiarazione.

**Verifica post-deploy:** 4 link icon presenti in `<head>` (3 esistenti + 1 nuova 512×512).

**Tempo propagazione Google:** 3-15 giorni. Per accelerare: GSC URL Inspection → Request Indexing su home.

#### 3.9.4 Branch `claude/template-redirect-redirection-bypass` ✅ DEPLOYATO (poi inerte)

**SHA:** `b023e31` — merged → main

**Contesto:** plugin Redirection installato per gestire i 13 redirect 301 del cleanup duplicati. I 301 non scattavano perché il custom `template_redirect` (functions.php:316) intercettava i 404 con force-home prima del plugin Redirection.

**Fix implementato:** secondo bypass nel `template_redirect` (oltre a quello paged-archive di ieri):
- Guard `defined('REDIRECTION_VERSION')` + `SHOW TABLES LIKE` per evitare query inutile se plugin disinstallato
- Query SELECT su `wp_redirection_items` per URL corrente (con varianti trailing-slash)
- Se match trovato: `return` early → permette al plugin di processare

**Stato attuale:** il fix è **deployato ma inerte** dopo la disinstallazione di Redirection (il guard `defined('REDIRECTION_VERSION')` ora restituisce false). Codice innocuo, **technical debt** da rimuovere in futuro (P2 #template-redirect-cleanup).

#### 3.9.5 Cleanup DB — 13 post duplicati cancellati

**Mappa rivista durante audit pre-cleanup** (con inversioni rispetto al "tieni più vecchio"):

| # | Mantieni | Cancella | Motivo scelta vincitore |
|---|---|---|---|
| 1 | 1931 | 1936, 1988, 2022 | Più vecchio + slug pulito |
| 2 | 1026 | 1061 | **Fonte ecaserta.com** (immagine reale) |
| 3 | 1085 | 1219 | Più vecchio |
| 4 | **1256** | **1255** | ⚠️ Inversione: 1256 da pupia.tv ha fonte vera |
| 5 | 1443 | 1948 | **Fonte ilmattino.it** |
| 6 | 1926 | 1978 | Più vecchio (entrambi Google News) |
| 7 | 1983 | 2076 | **Fonte cronachedi.it** + content ricco |
| 8 | 1995 | 2030 | **Fonte ansa.it** |
| 9 | 2151 | 2170 | thereportzone.it + content più ricco |
| 10 | 2388 | 2397 | Più vecchio |
| 11 | 2497 | 2512 | Più vecchio |

**Procedura via wp-cli (4 step):**

1. ✅ Backup DB: `backup-pre-cleanup.sql` 8.2MB
2. ✅ Pipeline import bloccata (`dnap_import_lock = 1`)
3. ✅ Dry-run pre-flight: 11 vincitori + 13 perdenti tutti trovati
4. ✅ 13 redirect 301 inseriti in `wp_redirection_items` (poi rivelatisi inutili)
5. ✅ 13 post cancellati con `wp_delete_post(..., true)` (force, no trash)
6. ✅ Verifica finale: 0 duplicati title rimanenti, **1067 post pubblicati** totali (era 1080)
7. ✅ Pipeline import sbloccata

**Decisione utente:** non cancellare immagini orfane. ~2MB di file fisici restano in `wp-content/uploads/` come carcasse innocue.

#### 3.9.6 Esperienza fallimentare plugin Redirection (lezione critica)

**Sequenza problemi (4 ore di debug):**

1. Plugin Redirection 5.7.5 installato + setup wizard completato
2. 13 record `wp_redirection_items` creati via wp-cli
3. Test redirect: HTTP 200 (force-home, plugin saltato) → fix deploy `b023e31`
4. Re-test: HTTP 404 invece di 301 → plugin trova zero match
5. Diagnosi 1: tabella `wp_redirection_modules` mancante → creata manualmente via SQL
6. Diagnosi 2: campo `match_url` vuoto nei record → popolato via UPDATE
7. Diagnosi 3: campo `match_data` vuoto → popolato con default JSON
8. Diagnosi 4: `Red_Item::get_for_url()` ritorna 0 anche bypassando HTTP — plugin NON registra hook frontend, solo `Redirection_Admin::init`
9. Tentativo deactivate/reactivate → nessun cambio
10. **Decisione:** disinstallato Redirection completamente

**Risultato finale:** 13 URL "perdenti" tornano a soft-404 con noindex (SEO-safe). Per i 13 URL **a 0 hits totali, 0 backlink, 0 indicizzati**, il valore SEO marginale di 301 vs soft-404 è praticamente zero.

**Lezione operativa critica consolidata** (vedi sez. 12 "Regole operative"):
- Per redirect statici a basso volume (<50): preferire `.htaccess` direttamente, non plugin third-party
- Plugin third-party con setup runtime complesso (richiedono dashboard click per tabelle aggiuntive) sono fragili in pipeline automatizzate
- Quando il problema è semplice ma il fix richiede 4+ diagnosi → STOP, ripensare l'approccio

#### 3.9.7 Bug critici emersi durante audit visivo del sito

Durante review post-cleanup l'utente ha identificato **4 bug strutturali** mai documentati prima:

**Bug #1 — Event date vs publish date**
Esempio: post 2552 importato 27/4 09:47 con titolo "Incendio al rimessaggio di barche a Castel Volturno nella notte". Il body cita testualmente "nella notte tra il 22 e il 23 aprile". Il sito mostra `post_date` (27/4) come "1h fa", facendo apparire come news fresca un fatto di 5 giorni fa. Confonde lettori e Google.

**Bug #2 — Cluster duplicati semantici (oltre i title-identici)**
Stesso evento "incendio rimessaggio barche Castel Volturno" pubblicato **10 volte** in 11 giorni con titoli rewritten diversi:
- 16/4: 7 post (ID 1926, 1933, 1934, 1938, 1953, 1959, 1977)
- 17/4: 4 post (ID 1978, 1982, 1983, 2076)
- 27/4: 1 replay (ID 2552, 11 giorni dopo!)

Layer 1.5 finestra 12h non li cattura, Layer 2 entity sotto-popolato (3.5%). Tag overlap potrebbe catturarli ma non è implementato come signal.

**Bug #3 — Multi-city transversal articles**
Articoli su tematiche litorale-wide assegnati a 4-6 città individuali:
- Post 2549 "Litorale domizio, sequestrata tubazione per scarichi abusivi": Baia Domizia + Castel Volturno + Cellole + Mondragone (4 città)
- Post 2460 "Centro migranti sul litorale, Cgil e vescovo Lagnese": Baia Domizia + Castel Volturno + Cellole + Falciano + Mondragone + Sessa Aurunca (6 città)

Effetti negativi: gonfia city archive count, dilui SEO signal su tutti i comuni, lettore vede badge sovraffollati nelle card.

**Bug #4 — Wrong-city attribution (police HQ false positive)**
Pattern "Carabinieri di Mondragone arrestano X a Castel Volturno" → assegnato sia Castel Volturno (luogo evento) sia Mondragone (sede caserma). Esempi:
- Post 2495: "Castel Volturno, arrestato 58enne per armi"
- Post 2486: "Castel Volturno, furto energia elettrica"
- Post 2550: "Sessa Aurunca e Cellole, controlli rafforzati" (HQ Caserta)

Causa: `core.php:1029-1048` merge tra `dnap_get_cities_from_text($title + $description)` e Claude's `cities` array. Il keyword scan non distingue "Carabinieri di Mondragone" (riferimento istituzionale) da "rapina a Mondragone" (luogo evento).

#### 3.9.8 Sblocco pipeline import e analisi falso bug "articoli vecchi"

L'utente ha segnalato sospetto: "il sistema importa articoli di giorni fa". Verifica via wp-cli + tail log:

- Ultimi 20 articoli importati: tutti del 26-27 aprile (recenti)
- Log: "Importati 1 | Saltati 282" su singolo cron run (filtri funzionano)
- L'output del primo comando wp-cli aveva mescolato due liste, dando l'impressione di articoli vecchi

**Conclusione:** non è un bug nel filtro temporale. È **Bug #1** (event date vs publish date) che fa apparire articoli con `post_date` recente ma fatti vecchi citati nel body. Sintomo dello stesso problema, manifestazione diversa.

### 3.10 Design "Dedup Pipeline v2" — Documento di progettazione completo

Prima di implementare, design consultation con Claude Code. Iterazioni: design v1 da Claude Code → review utente con 5 modifiche → re-review da Claude Code con disagreement costruttivo → versione finale concordata.

#### 3.10.1 Decisioni finali concordate

**Algoritmo Layer 3 (additivo, dopo Layer 2c esistente):**

```
Candidate set query: posts pubblicati ultimi 30 giorni con almeno uno di:
  - shared non-generic tag
  - same primary_event_city
  - matching _dnap_event_entity

Per candidate, score:
  + 30  if shared_non_generic_tags ≥ 2
  + 20  if entity_match
  + 15  if title_jaccard_keywords ≥ 0.5
  + 10  if same primary_event_city
  + 10  if event_date present in BOTH AND |Δ| ≤ 2 days
  +  5  if publish-date Δ ≤ 7 days
  - 25  if new_significant_tag (≥1 non-generic tag in new article that NO candidate has)
  - 15  if title contains evolution_verb on new entity action

Decisione:
  if score ≥ 55  → SKIP (mark duplicate, log)
  if 30 ≤ score < 55 → IMPORT + write _dnap_related_to = root_id
  if score < 30 → import normal
```

**Soglie finali (modificate da v1 design):**
- `dnap_dedup_skip_threshold` = **55** (non 50, non 60 — compromesso)
- `dnap_dedup_related_threshold` = **30** (non 25, banda 30-55 di 25 punti)

**Trace verifica su 3 casi:**

| Caso | Score | Decisione | Margine |
|---|---|---|---|
| Cluster incendio post 2552 vs 1926 | 65 | SKIP ✓ | +10 sopra skip |
| Cluster incendio con 1 signal mancante | 55 | SKIP ✓ | at threshold |
| Iannitti Day 2 vs Day 1 | 35 | RELATED ✓ | +20 sotto skip |
| Random unrelated post pair | ≤10 | NORMAL ✓ | n/a |

**Never-generic stoplist** (root nouns sempre discriminanti, mai auto-promossi a generic):
```
arresto, sequestro, incendio, omicidio, aggressione, incidente,
rapina, truffa, denuncia, inchiesta, condanna, sentenza,
scomparsa, ritrovamento, fuga, evasione
```

**Generic tags seed** (esclusi dal calcolo overlap):
- Categorie WP (cronaca, sport, politica, ecc)
- Slug città individuali e aggregate (mondragone, castel-volturno, ecc)
- Generici (caserta, casertano, campania, italia, comunale, regionale, ecc)

**Auto-promotion threshold:** `dnap_generic_tag_threshold_pct` = **15%** (non 10% — più permissivo)

**Evolution verbs** (penalty -15 quando titolo contiene azione nuova):
```
identificato, identificata, fermato, fermata, arrestato, arrestata,
condannato, condannata, confessa, confessato, assolto, assolta,
riconosciuto, riconosciuta, accusato, accusata, rilasciato,
scarcerato, prosciolto, indagato, denunciato, ricoverato,
dimesso, deceduto, ritrovato, scomparso, sentenza, ergastolo
```

#### 3.10.2 Refactor schema Claude (cities → event_location_city + scope)

Sostituisce l'attuale `cities` array con:

```json
{
  "event_location_city": "castel-volturno",   // single primary
  "event_scope": "single_city",                // single_city | multi_city | transversal
  "mentioned_cities": ["mondragone"]           // context (police HQ, residenza), NON taxonomy
}
```

**Logica taxonomy assignment (sostituisce `core.php:1029-1048`):**
- `single_city`: assegna [event_location_city] (~85% degli articoli)
- `multi_city`: assegna [event_location_city, ...mentioned_cities] (2-3 città legittime)
- `transversal`: assegna `litorale-domizio` (term virtuale, NO union semantics, posti SOLO lì)

Risolve **Bug #3 e #4 in un colpo**. Costo prompt extra: ~120 input tokens cached + 10 output tokens = **+$0.30/mese**.

#### 3.10.3 Term virtuale `litorale-domizio` (decisione utente: visibile)

Da implementare in Phase A:
- ✅ Aggiunto a `dnap_ensure_aggregate_city_terms()` come 3° aggregato
- ✅ **Visibile come 8° chip** nella tab Città (no exclusion da chip menu)
- ✅ **Card homepage dedicata** "Tutto il Litorale" come 6° city section
- ✅ **NO aggregate union** (diversamente da cellole-baia-domizia e falciano-carinola): posti transversal stanno **solo** in `/citta/litorale-domizio/`, non appaiono negli archivi città individuali
- ✅ Inclusa in sitemap se count > 0

Discoverability scelta utente: chip menu + homepage card.

#### 3.10.4 Event date extraction

Schema Claude esteso con campo `event_date` (ISO 8601 YYYY-MM-DD).

**Prompt addendum (~80 tokens cached):**
```
event_date — data dell'evento principale
NON la data di pubblicazione del feed.
- "ieri" → data feed - 1
- "nella notte tra il 22 e il 23 aprile" → 2026-04-23
- "questa mattina" → data feed
- "nelle scorse settimane" o ambigua → null
- evento futuro → data evento
```

**Storage e downstream:**
- `_dnap_event_date` (post_meta)
- Se `|event_date - today| > 1` giorno → `post_date_gmt` riscritto a `event_date 12:00:00` (sito mostra data corretta del fatto)
- Skip-too-old gate: se `event_date < today - 14d` → skip
- Layer 3 dedup score: `+10` se entrambi hanno event_date e Δ ≤ 2 giorni

Risolve **Bug #1**. Costo: +$0.12/mese.

#### 3.10.5 Strategia migrazione (1067 post esistenti)

**Backfill DB-only (no Claude calls):**

| Backfill | Cosa | Costo |
|---|---|---|
| `_dnap_event_scope` | Heuristic tiered: 1 città→single, 2-3→multi, ≥4→transversal | DB-only, ~30s |
| `_dnap_event_location_city` | Da `_dnap_event_city` esistente o `cities[0]` | DB-only |
| `_dnap_event_date` retroattivo | NON fare (costerebbe $6.40, valore basso su archivio) | skip |
| `_dnap_related_to` retroattivo | NON fare (clusters storici non utili) | skip |

**Audit manuale post-migration:**
- WP-CLI script esporta CSV con `post_id, title, current_cities, heuristic_scope, body_excerpt_300_char`
- Utente apre in spreadsheet, rivede ~30-50 post con `count cities >= 4`
- Corregge classifica (transversal vs multi_city) dove l'heuristic ha sbagliato (false positive previsti: processioni, joint sweeps, ricapitoli)
- Secondo WP-CLI script applica le correzioni dal CSV editato

#### 3.10.6 Plan implementazione — branch unico `feature/dedup-v2` in 4 fasi atomiche

**Phase A — Schema & taxonomy (no behavior change)**
- Aggiungere term `litorale-domizio` a `dnap_ensure_aggregate_city_terms()`
- Registrare nuovi post_meta keys (no writes ancora)
- Aggiungere wp_options con defaults
- Helper SSR `dnapp_ssr_city_chips` aggiunge chip litorale-domizio
- Helper SSR `dnapp_ssr_home_city_section` aggiunge 6° section
- SPA `buildCities()` chip filter + `buildHome()` city section iteration **(REGOLA SSR↔SPA PARITY)**
- Risk: zero, additivo

**Phase B — Claude prompt extension (data flowing, no skip)**
- Estendere JSON schema Claude: `event_date`, `event_scope`, `event_location_city`, `mentioned_cities`
- Persist nei nuovi post_meta
- Mantenere ANCHE legacy `cities` field (backwards compat)
- NON cambiare ancora taxonomy assignment (keyword merge attivo)
- Cache prompt invalidata 1 volta (~$0.002)
- Risk: low, additivo

**Phase C — Layer 3 in shadow mode 48-72h**
- Implementare scoring layer dopo Layer 2c
- `dnap_dedup_skip_threshold = 999` (NO skips, solo log)
- Scrivere `_dnap_dedup_score` + `_dnap_dedup_signals` per ogni post
- Verbose log on (gated da `dnap_dedup_log_all = true`)
- Run 48-72h, review distribuzione scores
- Risk: low (no skips happen)

**Phase D — Activate & migrate**
- Drop threshold a 55 via `wp option update` (active skip)
- Switch taxonomy assignment da keyword-merge a event_scope-driven
- Drop legacy `cities` field e `dnap_get_cities_from_text` merge
- Run WP-CLI migration script (`_dnap_event_scope` + `_dnap_event_location_city` su 1067 post)
- Run audit CSV export → review manuale → batch-apply correzioni
- Remove legacy fallback (`core.php:886-931`)
- Risk: medium, monitoraggio richiesto

#### 3.10.7 Pre-implementation checklist (DA FARE prima di Phase A)

**MUST do:**

- [ ] **Query SQL #1 — Top-30 tag distribution su prod:**
```bash
ssh -i "C:\Users\sorre\Desktop\DOMIZIO-NEWS\domizionews-server.pem" ubuntu@13.62.37.76 "cd /var/www/html && sudo -u www-data wp eval '
global \$wpdb;
\$rows = \$wpdb->get_results(\"
  SELECT t.name, tt.count
  FROM {\$wpdb->terms} t
  INNER JOIN {\$wpdb->term_taxonomy} tt ON t.term_id=tt.term_id
  WHERE tt.taxonomy=\\\"post_tag\\\"
  ORDER BY tt.count DESC LIMIT 30
\");
foreach (\$rows as \$r) echo \"\$r->name: \$r->count\n\";
'"
```
Output: seed list per `dnap_generic_tags_manual` (escludendo i never-generic stoplist).

- [ ] **Query SQL #2 — Cluster overlap incendio rimessaggio:**
```bash
ssh -i "C:\Users\sorre\Desktop\DOMIZIO-NEWS\domizionews-server.pem" ubuntu@13.62.37.76 "cd /var/www/html && sudo -u www-data wp eval '
global \$wpdb;
\$ids = [1926, 1933, 1934, 1938, 1953, 1959, 1977, 1982, 1983, 2076, 2552];
\$rows = \$wpdb->get_results(\$wpdb->prepare(\"
  SELECT t.name, COUNT(DISTINCT p.ID) as posts
  FROM {\$wpdb->posts} p
  INNER JOIN {\$wpdb->term_relationships} tr ON p.ID = tr.object_id
  INNER JOIN {\$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
  INNER JOIN {\$wpdb->terms} t ON tt.term_id = t.term_id
  WHERE p.ID IN (\" . implode(\",\", array_fill(0, 11, \"%d\")) . \")
    AND tt.taxonomy = \\\"post_tag\\\"
  GROUP BY t.term_id
  ORDER BY posts DESC
\", \$ids));
foreach (\$rows as \$r) echo \"\$r->posts/11: \$r->name\n\";
'"
```
Output: conferma assumption "≥2 shared non-generic tags catch the cluster".

- [ ] **Verificare** che `dnap_log()` scriva su path persistente (non `/tmp`) per retention 30 giorni del dedup log

**Out of scope per ora (rimandato):**
- Vector embedding similarity (cost-prohibitive a Haiku tier)
- Image-based dedup
- Cross-language clustering (sito IT-only)
- A/B testing weights (single-deploy tuning sufficiente)

---

### 3.11 Sessione 28/4 — Phase A + Phase B deployate in produzione

Continuazione della sessione 27/4 (dopo design Dedup Pipeline v2). Realizzate Phase A (infrastruttura) e Phase B (Claude prompt extension), deployate insieme in produzione.

#### 3.11.1 Pre-implementation queries SQL su prod (28/4 mattina)

**Query #1 — Top-30 post_tag distribution su 1067 post:**

| Tag | Count | % | Verdict |
|---|---|---|---|
| sicurezza | 263 | 24.6% | 🔴 Generic |
| carabinieri | 233 | 21.8% | 🔴 Generic |
| litorale domizio | 167 | 15.6% | 🔴 Generic |
| arresto | 94 | 8.8% | 🟢 Never-generic |
| sicurezza stradale | 80 | 7.5% | 🟡 |
| controlli | 57 | 5.3% | 🟡 |
| spaccio | 55 | 5.2% | 🟢 Discriminante |
| giovani | 55 | 5.2% | 🔴 Generic |
| giustizia | 52 | 4.9% | 🟡 |
| droga | 50 | 4.7% | 🟢 Discriminante |
| forze dell'ordine | 50 | 4.7% | 🔴 Generic |
| indagini | 46 | 4.3% | 🟡 |
| denuncia | 45 | 4.2% | 🟢 Never-generic |
| sequestro | 42 | 3.9% | 🟢 Never-generic |
| ambiente | 42 | 3.9% | 🔴 Generic |
| furto | 42 | 3.9% | 🟢 Discriminante |
| territorio | 40 | 3.7% | 🔴 Generic |
| comunità | 40 | 3.7% | 🔴 Generic |
| inseguimento | 38 | 3.6% | 🟢 Discriminante |
| criminalità | 37 | 3.5% | 🟡 |
| **incendio** | 36 | 3.4% | 🟢 Never-generic |
| legalità | 36 | 3.4% | 🔴 Generic |
| politica locale | 35 | 3.3% | 🟡 |
| omicidio | 35 | 3.3% | 🟢 Never-generic |
| polizia | 34 | 3.2% | 🔴 Generic |
| turismo | 33 | 3.1% | 🟢 Discriminante |
| aggressione | 32 | 3.0% | 🟢 Never-generic |
| rifiuti | 30 | 2.8% | 🟢 Discriminante |
| incidente stradale | 27 | 2.5% | 🟢 Discriminante |
| cultura | 26 | 2.4% | 🔴 Generic |

**Insight chiave:** "sicurezza" (24.6%) e "carabinieri" (21.8%) sono troppo generici per essere discriminanti. Vanno in `dnap_generic_tags_manual`. Threshold auto-promotion confermato a **15%** (a 10% si sarebbe incluso "arresto" 8.8%, perdendo il signal più forte).

**Query #2 — Cluster incendio rimessaggio (11 post, validation positivo):**

| Tag | Posts | Non-generic? |
|---|---|---|
| incendio | 9/11 | ✅ |
| sicurezza | 8/11 | 🔴 (escluso) |
| litorale domizio | 5/11 | 🔴 (escluso) |
| vigili del fuoco | 4/11 | ✅ |
| barche | 4/11 | ✅ |

**Verdict:** cluster ha 3 tag non-generic condivisi tra ≥2 post (`incendio`, `vigili del fuoco`, `barche`). Algoritmo Layer 3 li catturerebbe correttamente.

**Verifica post 2552 specifico (replay 27/4):**
- Tag: `incendio, litorale, rimessaggio, sicurezza, vigili del fuoco`
- Non-generic condivisi con cluster: 3 (`incendio`, `rimessaggio`, `vigili del fuoco`)
- Score atteso: +30 (shared tags) +15 (title jaccard) +10 (city) +10 (event_date) = **65 → SKIP** ✓

**Query #3 — Cluster Iannitti (11 post, validation negativo):**

| Tag | Posts |
|---|---|
| omicidio | 9/11 |
| carabinieri | 4/11 (escluso) |
| indagini | 4/11 |
| inchiesta | 4/11 |
| scomparsa | 3/11 |
| Vincenzo Iannitti | 3/11 |

**Verdict:** entity match (Vincenzo Iannitti) + tag overlap (omicidio + scomparsa) farebbero scattare +30+20 = 50, ma le penalty `new_significant_tag` (-25) e `evolution_verb` (-15) portano a 35 → **RELATED, non SKIP** ✓.

#### 3.11.2 Phase A deployata ✅ — `feature/dedup-v2` SHA `ce56b71`

**Componenti:**
- Term `litorale-domizio` (term_id 1707, count=0 iniziale) creato come 3° aggregato city
- Helper `dnap_aggregate_uses_union(string $slug)` — opt-in union semantics:
  - `cellole-baia-domizia` + `falciano-carinola`: union (posti subterm appaiono in aggregato)
  - `litorale-domizio`: NO union (posti restano solo lì, non auto-appaiono in archivi città individuali)
- 8 wp_options `dnap_dedup_*` registrate con `add_option` + autoload=false:
  - `dnap_dedup_skip_threshold = 999` (shadow mode default, da abbassare a 55 in Phase C)
  - `dnap_dedup_related_threshold = 30`
  - `dnap_dedup_window_days = 30`
  - `dnap_dedup_weights` (8 entries)
  - `dnap_generic_tags_manual` (13 seed entries)
  - `dnap_dedup_never_generic_tags` (21 root nouns)
  - `dnap_evolution_verbs` (28 IT past-participles)
  - `dnap_dedup_log_all = true`
- SSR + SPA: chip "Tutto il Litorale" 8° in tab Città, 6° city section in homepage (conditional count > 0)
- Diff: 6 file, +215/-22 righe

**Risk: zero** (tutto additivo, behavior change zero).

#### 3.11.3 Phase B deployata ✅ — `feature/dedup-v2` SHA `5942f48`

**Componenti:**
- Claude prompt extension con 4 nuovi campi (~825 input tokens cached):
  - `event_date` (YYYY-MM-DD del fatto principale, NON del feed)
  - `event_scope` (single_city | multi_city | transversal)
  - `event_location_city` (whitelist 7 slug individuali, mai aggregati)
  - `mentioned_cities` (array, comuni di contesto non-taxonomy, max 5)
- Validazione strict in `gpt.php`:
  - Regex YYYY-MM-DD per event_date
  - Enum scope con default safe `single_city`
  - Whitelist sanitize_title per location_city
  - Array sanitize per mentioned_cities
- Persistenza 4 nuovi post_meta in `core.php`:
  - `_dnap_event_date`
  - `_dnap_event_scope`
  - `_dnap_event_location_city`
  - `_dnap_mentioned_cities`
- **Bug #1 fix — post_date_gmt rewrite** (`core.php:1013-1052`):
  - Se `event_date` è settato e differisce da `now` di > 1 giorno
  - Override `post_date` e `post_date_gmt` a event_date 12:00:00 site-local
  - Usa `wp_timezone()` per evitare ambiguità day-boundary
  - Log: `📅 post_date override → ... (event_date=..., Δ=±N ore)`
- **Skip-too-old gate** (`core.php:790-819`):
  - Hard skip BEFORE Layer 1.5/2 dedup
  - Soglia `dnap_max_event_age_days = 14` (tunable, 9° wp_option)
  - Log: `⏭ Skip-too-old (event_date=..., età=N gg > soglia 14 gg)`
- Diff: 4 file, +249/-3 righe

**Costo Phase B:**
- Token delta input cached: ~825/articolo @ $0.10/Mtok = $0.000083
- Token delta output: ~30/articolo @ $5/Mtok = $0.000150
- Per articolo: ~$0.00023
- Mensile @ 1500 articoli: **~$0.35/mese**
- One-time cache invalidation: ~$0.012 (prompt cached prefix esteso 6500→9400 tokens)

**Risk: low** (additive, esistenti taxonomy assignment + 4 dedup layers byte-identical preservati).

#### 3.11.4 Verifica deploy A+B (28/4 fine giornata)

```
✅ SHA prod: 5942f48 (Phase B)
✅ Term litorale-domizio: term_id=1707, count=0
✅ wp_options: 11 totali registrate (8 dnap_dedup_* + 3 dnap_generic_*/dnap_evolution_* + dnap_max_event_age_days)
✅ dnap_max_event_age_days = 14
✅ Chip 8° SSR /citta/<x>/: 8 data-city values incluso litorale-domizio
✅ Sitemap: 4 sub-sitemap (no regressione SEO da v2.0)
```

#### 3.11.5 Stato monitoraggio (28/4 fine giornata, da verificare 29/4)

**In attesa di verifica overnight:**

- [ ] **Primo cron import post-deploy** — verificare popolamento dei 4 nuovi `_dnap_event_*` post_meta su nuovi post (post_id > 2552)
- [ ] **Volume import normale** — atteso ~120 articoli/giorno. Se <80 → soglia 14 troppo aggressiva, alzare a 30
- [ ] **Skip-too-old log entries** — quanti articoli scartati per età? Plausibile pochi (<10/giorno) se Claude estrae correttamente
- [ ] **post_date override log entries** — quanti articoli hanno date riscritte? Plausibili 5-20/giorno (articoli "1h fa" su fatti vecchi)
- [ ] **Articoli con event_scope=transversal** — primi candidati per il chip "Tutto il Litorale". Se nessuno per 24h, possibile bug nel prompt o classifica troppo conservativa
- [ ] **Distribuzione event_scope** — atteso ~85% single_city, 10% multi_city, 5% transversal. Se sbilanciato, calibrare prompt
- [ ] **Wrong-city attribution** — verificare manualmente 2-3 articoli "Carabinieri di X arrestano a Y": i `mentioned_cities` includono X correttamente?

**Comandi monitoraggio overnight (lanciare 29/4 mattina):**

```bash
# Check 1: nuovi post con _dnap_event_* meta popolati
ssh -i "C:\Users\sorre\Desktop\DOMIZIO-NEWS\domizionews-server.pem" ubuntu@13.62.37.76 "cd /var/www/html && sudo -u www-data wp eval '
global \$wpdb;
\$rows = \$wpdb->get_results(\"
  SELECT p.ID, p.post_title, p.post_date,
    (SELECT meta_value FROM {\$wpdb->postmeta} WHERE post_id=p.ID AND meta_key=\\\"_dnap_event_date\\\") as ev_date,
    (SELECT meta_value FROM {\$wpdb->postmeta} WHERE post_id=p.ID AND meta_key=\\\"_dnap_event_scope\\\") as scope,
    (SELECT meta_value FROM {\$wpdb->postmeta} WHERE post_id=p.ID AND meta_key=\\\"_dnap_event_location_city\\\") as loc_city
  FROM {\$wpdb->posts} p
  WHERE p.post_status=\\\"publish\\\" AND p.post_type=\\\"post\\\"
    AND p.ID > 2552
  ORDER BY p.ID DESC LIMIT 20
\");
foreach (\$rows as \$r) echo \"ID \$r->ID | \$r->post_date | event_date=\$r->ev_date | scope=\$r->scope | loc=\$r->loc_city\n\";
'"

# Check 2: log skip-too-old + post_date override
ssh -i "C:\Users\sorre\Desktop\DOMIZIO-NEWS\domizionews-server.pem" ubuntu@13.62.37.76 "echo '=== Skip too old ===' && sudo grep 'Skip-too-old' /var/www/html/wp-content/uploads/dnap.log | tail -20 && echo '' && echo '=== post_date override ===' && sudo grep 'post_date override' /var/www/html/wp-content/uploads/dnap.log | tail -20"

# Check 3: distribuzione event_scope
ssh -i "C:\Users\sorre\Desktop\DOMIZIO-NEWS\domizionews-server.pem" ubuntu@13.62.37.76 "cd /var/www/html && sudo -u www-data wp eval '
global \$wpdb;
\$rows = \$wpdb->get_results(\"
  SELECT meta_value as scope, COUNT(*) as cnt
  FROM {\$wpdb->postmeta}
  WHERE meta_key=\\\"_dnap_event_scope\\\"
  GROUP BY meta_value ORDER BY cnt DESC
\");
foreach (\$rows as \$r) echo \"\$r->scope: \$r->cnt\n\";
'"

# Check 4: volume import ultime 24h
ssh -i "C:\Users\sorre\Desktop\DOMIZIO-NEWS\domizionews-server.pem" ubuntu@13.62.37.76 "cd /var/www/html && sudo -u www-data wp eval '
global \$wpdb;
\$count = \$wpdb->get_var(\"SELECT COUNT(*) FROM {\$wpdb->posts} WHERE post_status=\\\"publish\\\" AND post_type=\\\"post\\\" AND post_date >= NOW() - INTERVAL 24 HOUR\");
echo \"Importati ultime 24h: \$count\n\";
'"
```

#### 3.11.6 Decisioni operative se monitoraggio rivela problemi

**Scenario A: volume import drasticamente calato (<80/giorno)**
- Diagnosi probabile: skip-too-old gate troppo aggressivo, Claude estrae event_date sbagliato (es. su articoli con date ambigue)
- Fix: `wp option update dnap_max_event_age_days 30` (oppure 999 per disabilitare temporaneamente)

**Scenario B: nessun articolo con scope=transversal in 24h**
- Diagnosi probabile: Claude troppo conservativo, classifica tutto come single_city
- Fix: rivedere esempi nel prompt, possibilmente aggiungere più casi "transversal"

**Scenario C: troppi articoli con scope=multi_city (>30%)**
- Diagnosi probabile: Claude assegna multi_city quando dovrebbe essere single_city con mentioned_cities
- Fix: rivedere distinzione "luogo del fatto" vs "comune menzionato" nel prompt

**Scenario D: nessun problema visibile**
- Procedere domani con Phase C (Layer 3 scoring in shadow mode)

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

## 5. Roadmap Hydration "True takeover" — COMPLETATA ✅

| Fase | Cosa | Effort | SHA | Stato |
|---|---|---|---|---|
| 1 | Aggregate post union | 45 min | `a459a76` | ✅ Deployato 26/4 |
| 1.5 | Fix "Vedi altro" aggregati (CITY_GOTO_TARGET) | 5 min | `2853669` | ✅ Deployato 26/4 |
| 2 | SPA paginazione + "Vedi altro" in buildCities | 35 min | `720efdb` | ✅ Deployato 26/4 |
| 3 | SSR pixel-identical (card + chrome + CSS unification) | 4h | `5008735` | ✅ Deployato 26/4 |
| 3.6 | Paged 404 fix + Vedi altro style align | 30 min | `6b9fac8` | ✅ Deployato 26/4 |
| 4 | Hydration true takeover boot() | 30 min | de-facto in `5008735` | ✅ Già implementata |

**Roadmap hydration COMPLETATA al 100%.** Fondazione SSR/SPA stabile.

## 5b. Roadmap SEO 27/4 — COMPLETATA ✅

| Branch | Cosa | SHA | Stato |
|---|---|---|---|
| `claude/sitemap-noindex-cleanup` | Sitemap cleanup + noindex tag/users/uncategorized | `32bb96e` | ✅ Deployato |
| `claude/favicon-512` | Favicon 512×512 per Google SERP cerchietto | `9bf8b76` | ✅ Deployato |
| `claude/template-redirect-redirection-bypass` | Bypass per Redirection (ora inerte) | `b023e31` | ✅ Deployato |
| Cleanup DB | 13 post duplicati cancellati + 1 categoria fix | (no branch) | ✅ Eseguito |

## 5c. Roadmap Dedup Pipeline v2 — Phase A+B COMPLETATE ✅

| Phase | Cosa | SHA | Stato |
|---|---|---|---|
| A | Schema & taxonomy (term litorale-domizio + 8 wp_options + UI scaffolding) | `ce56b71` | ✅ Deployato 28/4 |
| B | Claude prompt extension (4 nuovi campi + post_date override + skip-too-old) | `5942f48` | ✅ Deployato 28/4 |
| **C** | **Layer 3 shadow mode 48-72h + activate** | — | 🔜 Prossimo (29/4) |
| **D** | **Migration CSV audit + activate event_scope-driven taxonomy** | — | 🔜 Dopo Phase C |

---

## 6. Bug Status — 27 aprile fine giornata

### ✅ Risolti oggi 27/4 (3 deploy + cleanup DB)

| Item | Branch | SHA |
|---|---|---|
| Sitemap cleanup + noindex archivi sottili | sitemap-noindex-cleanup | 32bb96e |
| Favicon 512×512 per Google SERP | favicon-512 | 9bf8b76 |
| Template_redirect bypass per Redirection | template-redirect-redirection-bypass | b023e31 |
| Cleanup 13 duplicati DB title-identici | (no branch, wp-cli) | — |
| Categoria Uncategorized vuota (post 2475 → cronaca) | (no branch, wp-cli) | — |

### 🔴 Bug critici emersi oggi (design completato, implementazione in roadmap)

#### **Dedup Pipeline v2** (consolidata da Task C, D, F del v1.9)
Risolve in un singolo branch coordinato 4 sub-bug:

- **Cluster duplicati semantici** (es. incendio rimessaggio: 10 post stesso evento)
- **Event date vs publish date** (es. post 2552 mostra "1h fa" per fatto del 22/4)
- **Multi-city transversal articles** (es. post 2549, 2460 con 4-6 città assegnate)
- **Wrong-city attribution** (es. post 2495 "Carabinieri di Mondragone arrestano X a Castel Volturno" → 2 città)

**Stato:** design completato (sez. 3.10), pre-implementation checklist in attesa, implementazione su branch `feature/dedup-v2` in 4 fasi atomiche (~3-4h totali).

### 🔴 Altri bug critici aperti (non in dedup-v2)

| # | Bug | Effort |
|---|---|---|
| **B** | Fix pipeline immagini Google News (246 no-image, 207 placeholder Unsplash, 50%+ nuovi import) | 2-3h |
| **A2** | Recovery URL già cancellati indicizzati Google (audit GSC + 301 retroattivi via .htaccess) | 1-2h |

### 🟠 P1 aperti (preesistenti, non risolti)

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
- **#sitemap-aggregates** — `cellole-baia-domizia` + `falciano-carinola` non in sitemap (count=0). Risolto naturalmente con dedup-v2 quando aggiungiamo `litorale-domizio` (custom provider già necessario).
- **#dn-btn-primary-cleanup** — Rule unused in base.css (cleanup branch separato)
- **#chip-incidenti-sicurezza** — Categoria "Incidenti & Sicurezza" (58 post) non visibile nei chip SPA
- **#htaccess-versioning** — `.htaccess` non versionato in repo (vive solo su EC2)
- **#template-redirect-cleanup** — Codice bypass Redirection ora inerte dopo disinstallazione
- **#wp-site-icon-32x32-mismatch** — WP dichiara `sizes="32x32"` per file 80×80 (innocuo)

### 🟢 Risolti per side-effect 27/4

- 1490 tag pages "rilevate non indicizzate" → ora `noindex` (in 14-30gg Google le toglie)
- 1 user page (admin) in sitemap → rimossa
- Uncategorized in sitemap → escluso, con count=0
- 13 URL "perdenti" duplicati → soft-404 con noindex (SEO-safe)
- Favicon 512×512 dichiarata in head (Google SERP usa la nitida)

### 🟢 Bug "monitoring only" (sintomi da osservare)

| Cosa | Quando ricontrollare |
|---|---|
| Brand SERP "domizio news" (sparito dalla SERP) | 2-6 settimane |
| Favicon 512×512 in cerchietto Google | 3-7 giorni (deploy 27/4) |
| 1490 tag pages noindex deindicizzate | 14-30 giorni |
| 13 URL "perdenti" soft-404 deindicizzati | 14-30 giorni |
| GSC "Rilevata, non indicizzata" 2082 → calo | 2-4 settimane |
| GSC "93 indicizzate" → crescita | 2-6 settimane |

### Task non-bug (azioni manuali da fare)

| Task | Effort | Quando |
|---|---|---|
| Re-submit AdSense | 5 min | DOPO Task B (immagini) e dedup-v2 |
| Submit sitemap GSC + Convalida correzione | 10 min | DOPO dedup-v2 (sito pulito) |
| Request Indexing su home GSC (per favicon) | 2 min | Adesso (può andare oggi) |
| 16 simboli Unsplash da scaricare manualmente | manuale | quando possibile |

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

## 9. Prossimi Step (in ordine, dopo 28/4)

### 🔴 Imminente (29/4 mattina)

**TASK 0 — Verifica monitoraggio Phase A+B post-deploy**
1. Lanciare 4 check di monitoraggio (sez. 3.11.5)
2. Verificare distribuzione `_dnap_event_scope`, volume import, log skip-too-old
3. Decidere scenario A/B/C/D (sez. 3.11.6)
4. Ribilanciare soglie via `wp option update` se necessario

**TASK 1 — Phase C: Layer 3 shadow mode**
- Branch `feature/dedup-v2` (continua sopra `5942f48`)
- Implementare scoring Layer 3 dopo Layer 2c
- Default `dnap_dedup_skip_threshold = 999` (NO skip, solo log)
- Scrivere `_dnap_dedup_score` + `_dnap_dedup_signals` per ogni post
- Run 48-72h, monitorare distribuzione score
- Effort: 2h implementazione + 2-3 giorni shadow

**TASK 2 — Phase D: Activation + Migration**
- Drop `dnap_dedup_skip_threshold` da 999 → 55 via `wp option update`
- Switch taxonomy assignment da keyword-merge a event_scope-driven
- Drop legacy `cities` field
- Run WP-CLI migration script per `_dnap_event_scope` + `_dnap_event_location_city` su 1067 post storici
- CSV audit per post con `count cities >= 4` (~30-50 post)
- Remove legacy fallback `core.php:886-931`
- Effort: 1.5h implementazione + audit manuale CSV

### 🎯 Dopo Phase C+D stabilizzate

- **Task B — Fix pipeline immagini Google News** (~2-3h, indipendente, branch separato)
- **Re-submit AdSense** (dopo dedup-v2 + immagini fixate)
- **Submit sitemap GSC + Convalida correzione** (dopo cleanup completo)
- **Recovery URL 404 indicizzati Google** (Task A2, via `.htaccess`)

### 🎯 Medio termine

- VIP/Slider bug #44-48
- #URL-tab-sync — sincronizzare URL su click tab principali
- #chip-incidenti-sicurezza — categoria 58 post non visibile in chip SPA
- #htaccess-versioning — versionare `.htaccess` in repo

### 🟢 Side opportunities (cleanup tecnico)

- #template-redirect-cleanup — rimuovere bypass Redirection ora inerte
- #dn-btn-primary-cleanup — rimuovere rule unused in base.css
- Quattro siti `<style>${STYLES}</style>` in render() — rimuovibili
- Footer SPA hardcoded "© 2026" → dinamico
- #wp-site-icon-32x32-mismatch — fix dichiarazione sizes

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

### 27 aprile — Cleanup SEO + scoperta crisi indicizzazione
- **Audit GSC ha rivelato situazione critica**: solo 4.3% del sito indicizzato (93/2175 URL noti). Il problema dei 13 duplicati title-identici era marginale rispetto alle 1490 tag pages e 38 URL "duplicati per Google".
- **Tag pages con `noindex`**: scelta strategica per recuperare crawl budget. WordPress crea automaticamente una pagina archive per ogni tag, ma sono thin content (lista articoli che esistono già). Google le marca duplicate-content.
- **Favicon 512×512 dichiarata in head**: WordPress core emette solo 32x32 e 192x192. Per il cerchietto SERP serve dichiarazione esplicita ≥48×48 (ottimale 512×512). Filtro additivo, niente alterazione comportamento esistente.
- **Plugin Redirection abbandonato**: dopo 4 ore di debug (tabella mancante, campi vuoti, hook non registrati), disinstallato. **Lezione operativa**: per redirect statici a basso volume (<50), `.htaccess` è infinitamente più affidabile e veloce. Plugin third-party con setup runtime complesso fragili in pipeline automatizzate.
- **13 redirect 301 → soft-404 noindex**: accettato come delta. Per URL a 0 hits totali, 0 backlink, 0 indicizzati su Google, valore SEO marginale di 301 vs soft-404 è zero.
- **`litorale-domizio` come 3° aggregato visibile** (decisione utente): coerente con `cellole-baia-domizia` e `falciano-carinola` come pattern di aggregazione, ma con **NO union semantics** — diverso dagli altri due perché posti transversal stanno SOLO lì, non appaiono negli archivi città individuali (eviterebbe duplicate content reale).
- **Skip threshold dedup-v2 = 55** (compromesso): non 50 (troppo aggressivo, rischia falsi positivi su evolution chains tipo Iannitti), non 60 (troppo permissivo, lascia passare duplicati con segnale parziale). 55 è il punto in cui Iannitti ha margine +20 e cluster incendio +10. Tunable via wp_option.
- **Shadow mode 48-72h prima di attivare dedup**: anti-pattern sicurezza. Deploy con `threshold=999` (no skip), solo log. Vedi distribuzione scores reali, poi calibri con dati prod, poi attivi. Elimina rischio "deploy bad threshold → cron skippa 200 articoli legittimi".
- **Never-generic stoplist** (intuizione Claude Code): 16 root nouns delle news cronaca (`arresto, sequestro, incendio, omicidio, ...`) non possono mai essere auto-promossi a generic anche se >15% usage. Sono sempre discriminanti. Senza stoplist, dopo 6-12 mesi il sistema perderebbe la capacità di catturare cluster come "incendio rimessaggio" perché "incendio" diventerebbe generic.

### 28 aprile — Phase A+B deploy (validazione design con dati reali)

- **Query SQL pre-implementation confermano il design**: cluster incendio condivide 3 tag non-generic ≥2 post (`incendio`, `vigili del fuoco`, `barche`); cluster Iannitti ha entity overlap ma le penalty discriminano (algoritmo torna sui numeri).
- **`dnap_max_event_age_days = 14` da subito (no shadow mode)**: scelta utente esplicita, accettando rischio falsi positivi. Mitigazione: tunable via `wp option update`, rollback in 5 secondi se >5 skip ingiustificati nelle prime 24h.
- **post_date_gmt rewrite con `wp_timezone()`**: scelta tecnica di Claude Code per evitare ambiguità day-boundary. Alternative `DateTime` "naive" avrebbero generato off-by-one quando event_date era a mezzanotte server-tz.
- **Skip-too-old gate piazzato BEFORE Layer 1.5/2 dedup**: ottimizzazione di Claude Code — risparmia query DB per articoli che verrebbero scartati comunque. Plausibile guadagno 5-10ms per import.
- **Deploy unico A+B invece di separato**: Phase A da sola fa apparire chip "Tutto il Litorale" con 0 articoli (UX strana per ore). Deploy combinato A+B ha senso UX reale — Claude inizia subito a popolare i campi.
- **Costo finale Phase B**: $0.35/mese (era stima $0.42, allineato con margine 17%). One-time cache invalidation $0.012 (era stima $0.002, 6x ma trascurabile su singolo evento).

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

### 🔴 Regola "Evita over-engineering" (consolidata 27/4 sera)

Quando un problema è semplice ma il fix richiede 4+ diagnosi successive senza progresso, **STOP e ripensare l'approccio**. Non insistere a debuggare la soluzione complessa.

**Caso scuola (27/4 plugin Redirection):** 13 redirect statici da gestire. Plugin Redirection installato, 4 ore di debug per:
1. Tabella `wp_redirection_modules` mancante (creata manualmente)
2. Campo `match_url` vuoto nei record (popolato via UPDATE)
3. Campo `match_data` vuoto (popolato con default JSON)
4. `Red_Item::get_for_url()` ritorna 0 anche bypassando HTTP — plugin non registra hook frontend

Ogni passo "risolveva" un problema, ma rivelava il successivo. **L'approccio era sbagliato dall'inizio.** Per 13 redirect statici, `.htaccess` è la soluzione corretta:
- 13 righe RewriteRule
- Apache li applica prima del PHP
- Niente DB, niente plugin, niente cache
- Versionable in repo
- Deterministic

**Pattern operativo:**
- Se la soluzione richiede plugin third-party con setup runtime complesso → considerare alternativa nativa
- Se la soluzione richiede 3+ workaround a problemi infrastrutturali del plugin → STOP, abbandonare
- Costo opportunità del tempo speso > beneficio funzionale del 301 vs soft-404 per URL a 0 hits

**Domanda da porsi a metà debug:**
> "Se questo plugin non esistesse, come risolverei il problema?"

Se la risposta è "in 10 minuti con [tecnologia nativa]", probabilmente quella è la strada giusta.

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

*Fine HANDOFF v2.1 — 28 aprile 2026, fine giornata*

*Sessione 26/4: 6 deploy hydration*
*Sessione 27/4: 3 deploy SEO + cleanup DB 13 duplicati + design Dedup Pipeline v2*
*Sessione 28/4: Phase A + Phase B Dedup Pipeline v2 deployate*

*Roadmap hydration ✅ | Roadmap SEO 27/4 ✅ | Dedup-v2 Phase A+B ✅*

*Stato attuale: monitoraggio post-deploy 28/4 sera. Da verificare 29/4 mattina (4 check + decisioni operative se scenario A/B/C/D).*

*Prossimi step: Phase C (Layer 3 shadow mode) → Phase D (activation + migration). Task B (immagini Google News) parallelo.*

*Lezioni operative consolidate: SSR↔SPA parity rule, audit-prima-di-implementare, evita over-engineering, design consultation prima di coding (intelligenza emergente Claude Code).*
