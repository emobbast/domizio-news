# Domizio News

## Project Overview

Local news site for **Litorale Domizio** (province of Caserta, Campania, Italy), covering Mondragone, Castel Volturno, Baia Domizia, Cellole, Falciano del Massico, Carinola, and Sessa Aurunca. Stack: headless WordPress + vanilla-JS SPA (`app.js`, no build step) + a custom plugin (`domizionews-ai-publisher`) that hourly ingests RSS feeds, rewrites articles with **Claude Haiku 4.5**, and auto-posts to the Telegram channel `@domizionews`.

## Architecture

- **Backend (WordPress)** exposes two REST namespaces:
  - `/wp-json/dnapp/v1/` — registered by the theme ([wp-content/themes/domizionews-theme/functions.php:105](wp-content/themes/domizionews-theme/functions.php:105)): `feed`, `config`.
  - `/wp-json/domizio/v1/` — registered by the plugin ([wp-content/plugins/domizionews-ai-publisher/includes/rest.php](wp-content/plugins/domizionews-ai-publisher/includes/rest.php)): `posts`, `scopri`, `sticky-news`.
- **Frontend SPA** in [wp-content/themes/domizionews-theme/assets/js/app.js](wp-content/themes/domizionews-theme/assets/js/app.js) — single UMD bundle, React loaded from CDN via importmap, consumes the REST APIs above. Config is injected through `window.DNAPP_CONFIG` by the theme's `wp_add_inline_script`.
- **PHP SSR fallback** in [wp-content/themes/domizionews-theme/index.php](wp-content/themes/domizionews-theme/index.php) renders article HTML for Googlebot and other crawlers; the theme also force-rewrites 404s to the home SSR branch (status 200) so SPA URLs are indexable.
- **Plugin DNAP pipeline**: hourly WP-Cron (`dnap_cron_import`) reads configured RSS feeds → resolves Google News `CBMi…` payloads via base64 decode → filters by local geo-keywords → calls Claude Haiku 4.5 to rewrite title/excerpt/content/meta/social caption → sideloads the image (feed enclosure / `media:thumbnail` / `og:image` / Unsplash fallback) → publishes the post → schedules an async Telegram dispatch 10 s later to `@domizionews`.
- **Design**: Material Design 3 palette, mobile-first at 430 px max-width, primary color violet `#6750A4`.

## Key Plugin Files

All paths are under `wp-content/plugins/domizionews-ai-publisher/`.

- [domizionews-ai-publisher.php](wp-content/plugins/domizionews-ai-publisher/domizionews-ai-publisher.php) — **114 lines**. Plugin bootstrap: registers activation/deactivation hooks, schedules the hourly cron, creates default `city` terms, runs the one-shot `admin_init` migration that flips `autoload=no` on the Anthropic and Telegram secrets, and loads all modules on `plugins_loaded`.
- [includes/core.php](wp-content/plugins/domizionews-ai-publisher/includes/core.php) — **1004 lines**. The import loop (`dnap_import_now`): the `city` taxonomy, anti-duplicate logic (URL + hash + 70%-similar title over 30 days), Google News URL resolver (base64 → enclosure → links → href in content → HTTP redirect), local-content keyword filter, city-slug extractor, VIP/sticky detection, ad-slot injection, and the async Telegram dispatcher. The import lock (`dnap_import_lock` WP option) has a TTL safety net (`DNAP_LOCK_TTL_SECONDS = 600`, B2.1): if the previous process died from a fatal error before `register_shutdown_function` could release it, the next run detects a lock older than 10 minutes and overwrites it rather than blocking cron indefinitely. Layer 2 event-based dedup (6 h window) falls back to the first city from `$rewritten['cities']` when `event_entity` is null (B4.2), and `_dnap_event_city` is persisted as the secondary dedup key on publish — this catches semantic duplicates like generic public-notice articles where Claude extracts no named entity.
- [includes/gpt.php](wp-content/plugins/domizionews-ai-publisher/includes/gpt.php) — **510 lines**. Anthropic API wrapper (`dnap_call_claude`, model `claude-haiku-4-5`) plus the editorial rewrite function `dnap_gpt_rewrite` that returns the JSON envelope (title, slug, excerpt, meta_description, content, category, cities, tags, image_prompt, image_symbol, social_caption, skip, event_type, event_entity). Includes retry with backoff on 429/5xx, a max_tokens retry, and prompt-injection defense. The event classification taxonomy (`event_type`) has **24 values** (expanded from the original 21 of B4 by B4.1 — added `reato_ambientale` under CRONACA plus new groups AMBIENTE with `evento_ambientale` and SANITÀ with `sanita_locale` to cover Ambiente & Mare and Salute coverage). When Claude returns skip:true for rejected articles, `dnap_gpt_rewrite` honors the decision via early-return in the retry loop, avoiding wasted retries on null title/content (expected for skip responses); the downstream skip check is also placed before the title/content guard so the skip propagates to the caller.
- [includes/media.php](wp-content/plugins/domizionews-ai-publisher/includes/media.php) — **471 lines**. Featured-image pipeline with six prioritized strategies (passed `$image_url` → RSS enclosure → `media:thumbnail` → `<img>` in feed HTML → page `og:image` → first `<img>` in body → Unsplash fallback by category); sideloads to the Media Library and converts to WebP, except Unsplash which is stored as the `_dnap_external_image` post-meta.
- [includes/admin.php](wp-content/plugins/domizionews-ai-publisher/includes/admin.php) — **106 lines**. Registers DNAP settings and the VIP-tags UI (add/remove tags that promote matching posts to sticky).
- [admin/dashboard.php](wp-content/plugins/domizionews-ai-publisher/admin/dashboard.php) — **334 lines**. Registers the top-level `Domizio News` admin menu and renders the dashboard page (API-key form, last-import status, next cron time, log viewer with clear/force-run actions).
- [admin/feeds.php](wp-content/plugins/domizionews-ai-publisher/admin/feeds.php) — **275 lines**. CRUD UI for `dnap_feeds`: add/edit/delete/toggle active — the only supported way to manage the feed registry.
- [includes/scopri.php](wp-content/plugins/domizionews-ai-publisher/includes/scopri.php) — **110 lines**. Registers the `scopri` CPT (local activities) and the `scopri_categoria` taxonomy, plus default-terms bootstrap.
- [includes/rest.php](wp-content/plugins/domizionews-ai-publisher/includes/rest.php) — **383 lines**. Three REST routes under `domizio/v1`: `/posts` (filtered by `city`/`category`), `/scopri` (attività locali), `/sticky-news` (one sticky per city, VIP-tagged posts preferred within a 7-day window).

## Feed Registry

- Stored in the WP option `dnap_feeds`, an **indexed array** of `{ url, city_slug, cat_id, active }` entries.
- Managed **exclusively** through the admin UI at [admin/feeds.php](wp-content/plugins/domizionews-ai-publisher/admin/feeds.php) (menu: *Domizio News → Feed RSS*). Never hardcode feeds in plugin code.
- Read at import time via `get_option('dnap_feeds', [])` — no caching layer.
- `dnap_direct_feed_hosts()` in [includes/core.php:230](wp-content/plugins/domizionews-ai-publisher/includes/core.php:230) re-reads the option on every call (commit `fce3a50`), so feeds added/removed via the UI automatically affect Google News dedup without any restart or cache flush.

## Deploy Flow

- Defined in [.github/workflows/deploy.yml](.github/workflows/deploy.yml): on `push` to `main`, GitHub Actions opens an SSH session to the VPS (secrets: `SERVER_IP`, `SERVER_USER`, `SSH_PRIVATE_KEY`) and executes:
  ```
  cd /var/www/html
  sudo git fetch origin
  sudo git reset --hard origin/main
  sudo chown -R www-data:www-data /var/www/html/wp-content
  ```
- There is **no build step** — `app.js` is a pre-written UMD bundle, not compiled by Vite at deploy time.
- **Activation hooks do NOT re-run on deploy** (it's a `git reset`, not a plugin install). One-shot migrations must therefore be implemented on an `admin_init` hook guarded by an option flag — see `dnap_migrate_secret_autoload` in [wp-content/plugins/domizionews-ai-publisher/domizionews-ai-publisher.php:87](wp-content/plugins/domizionews-ai-publisher/domizionews-ai-publisher.php:87) for the canonical pattern.
- `.gitignore` excludes WordPress core (`/wp-admin/`, `/wp-includes/`, root `wp-*.php`), `wp-config.php`, `/wp-content/uploads/`, and every third-party plugin/theme — only `domizionews-ai-publisher` and `domizionews-theme` are versioned.
- Secrets (Anthropic API key, Telegram token) live in WP options (`dnap_anthropic_key`, `dnap_telegram_token`) with `autoload=no`, not in code or env files.

## Changelog

- [Bug #4] Cross-publisher dedup improved:
  - Claude JSON now includes event_keywords (3-5 noun keywords)
  - Persisted to post_meta _dnap_event_keywords
  - New Layer 1.5: similar_text on Claude-rewritten titles (75% threshold, 12h window)
  - Layer 2 relaxed: matches on city/entity + >=2 keyword overlap, no longer requires identical event_type
  - Legacy Layer 2 logic preserved as fallback for transition
- [Bug #4 addendum] _dnap_event_city now persisted whenever a city is known (previously only when entity was empty), so Layer 2 keyword dedup candidate pool covers all articles.
- [Bug #49] Source truncation raised from 1200 to 8000 chars in gpt.php:146 — articles now include full source material for Claude rewrite, fixing systematic information-poor output.
- [Bug #33] Token usage tracking added:
  - `dnap_call_claude()` now logs input/output tokens per call
  - Daily cumulative counters in wp_options `dnap_token_usage` (30-day rolling window, autoload=false)
  - Dashboard widget shows today + last 7 days
  - Helper: `dnap_get_token_usage_summary($days)`
- [Bug A] Telegram apostrophe escape fixed (core.php:1020):
  - Replaced `htmlspecialchars(ENT_QUOTES|ENT_HTML5)` with targeted `str_replace` for `< > &` only. Telegram HTML parse_mode does not decode `&apos;`, producing visible `l&apos;` in channel messages.
  - Added `html_entity_decode` in gpt.php social_caption sanitization to normalize pre-encoded RSS entities (`&#039;` / `&#8217;`) before storage.
- [Bug B] Multi-layer entity dedup for long-running stories:
  - Layer 2a: same `event_entity` + ≥2 keyword overlap within 30 days
  - Layer 2b: same `event_entity` (any keywords) within 72 hours
  - Layer 2c preserved: keyword overlap within 6h (from Bug #4 fix)
  - Example: Iannitti 4-day murder arc (IDs 2230/2262/2302/2416) now caught by 2a/2b; separate Zannini inchieste over months stay distinct because keyword overlap differs.
- [Bug #26] Prompt caching implemented:
  - User prompt split into `$static_instructions` (cacheable) + `$dynamic_article` (per-call) in [includes/gpt.php](wp-content/plugins/domizionews-ai-publisher/includes/gpt.php)
  - `cache_control` type=ephemeral, ttl=1h (requires `anthropic-beta: extended-cache-ttl-2025-04-11` header, set unconditionally by `dnap_call_claude`)
  - 1h TTL covers the 30-min cron cycle — first call of each cache window pays `cache_creation` ($1.25/Mtok), subsequent calls read at $0.10/Mtok (90% savings on cached portion)
  - `dnap_call_claude` now accepts either a string (legacy, no caching) or an array of content blocks (new, cacheable)
  - Static block interpolates `$cat_list`, `$city_list`, `$symbol_list` — cache invalidates when a taxonomy term is added/removed, acceptable because rare
  - Token tracking extended (Bug #33 → #26) to capture `cache_creation_input_tokens` + `cache_read_input_tokens` from the `usage` object; daily bucket gains `cache_w` + `cache_r` keys
  - Dashboard widget shows cache hit rate % for today and last 7 days
  - Expected ~70% overall input-token cost reduction at steady state
- [Density tuning 24/4] Prompt STILE DI SCRITTURA refactored for AdSense compliance:
  - DENSITÀ INFORMATIVA as priority 1 rule with explicit list of facts to preserve (names, roles, ages, dates, locations, institutions, figures, specific charges, quoted statements, administrative outcomes)
  - Numerical length targets per source density (e.g. source 200-400 words → output 280-400 words, 4-6 paragraphs)
  - 60% word-ratio minimum explicitly stated
  - Paragraph guidance updated (3-8 range vs previous 2-6)
  - Removed over-applied rule "Se la notizia è breve, l'articolo deve essere breve — non gonfiare"
  - Added REGOLA ANTI-COMPRESSIONE self-verification step
  - Added ESEMPIO 3 — cronaca densa (~280 words, fact-preserving)
  - DIVIETI ASSOLUTI (anti-cliché list) preserved
  - Context: AdSense rejected site 24/4 for "contenuti di scarso valore"; production audit showed 60-75% compression on sources (ecaserta.com 2458 -61%, pupia.tv 2457 -74%)
  - Cache impact: changes are inside static_instructions heredoc (Bug #26 caching); cache invalidates once on deploy, resumes normally after first import cycle — trivial cost
- [Density tuning v2 — 24/4 mattina] Density-v1 insufficiente (caso 2470: fonte 465 parole, output 140 parole = -72% compression ancora). Introdotte 3 modifiche forti:
  - LUNGHEZZA OBBLIGATORIA con minimi numerici (fonte 200-400 → min 300 parole output) al posto di "almeno 60%"
  - ESEMPIO 1 e 2 riscritti densi (5 paragrafi ~280 parole ciascuno) per evitare che Claude li usi come target implicito corto
  - CHECKPOINT obbligatorio pre-output: conta fatti fonte vs output, regola 80%, verifica lunghezza
  - Cache impact: tutto inside static_instructions, 1 cache invalidation post-deploy poi cache normale (~$0.005 una tantum)
  - Costo atteso: +$5/mese (output medio 600→900 token)
- [Fix Pack C — part 1: AI disclosure crawlability] Le 6 legal page (ID 697-702: `chi-siamo`, `contatti`, `privacy-policy`, `cookie-policy`, `note-legali`, `disclaimer`) ora sono propriamente crawlabili da Googlebot:
  - SPA ([app.js](wp-content/themes/domizionews-theme/assets/js/app.js)): `buildFooter()` cambia da `href="#"` a `/<slug>/`; il click handler fa `history.pushState({legalPage: slug}, '', '/<slug>/')` prima del re-render, così il link è condivisibile/bookmarkabile. Nuovo `LEGAL_SLUGS` (dichiarato subito dopo il blocco CONFIG) è la source-of-truth condivisa fra routing, popstate e rilevamento iniziale. `boot()` controlla `window.location.pathname` all'avvio e, se matcha una legal slug, imposta `state.selectedLegalPage` prima del primo `render()` — copre Googlebot, share link, bookmark. Il lookup articolo in `boot()` salta i legal slug per evitare fetch inutili a `/dnapp/v1/feed?slug=<legal>`. `window.onpopstate` gestisce back/forward del browser fra home ↔ legal. Back button interno (`data-action="back-legal"`) fa `history.pushState(null, '', '/')` per riallineare l'URL.
  - SSR ([index.php](wp-content/themes/domizionews-theme/index.php)): nuovo ramo `elseif ($page_obj)` fra `is_single()` e home. `$page_obj = is_page() ? get_queried_object() : null` raccoglie la queried page una sola volta e alimenta sia il SEO block (`$seo_title`, `$seo_desc` trim 25 parole, `$seo_canonical` = permalink della pagina) sia il body SSR (h1 + `apply_filters('the_content', …)` con `wp_kses_post`). `og:type` passa a `article` anche per le pagine. `<link rel=canonical>` esplicito viene emesso solo sulle viste NON singular (home/archive) per evitare duplicati con `rel_canonical` di WP core, che gestisce già single+page. Aggiunti `og:site_name` e `og:locale` sempre presenti.
  - Footer SSR esteso da 3 a 6 link (aggiunti `contatti`, `note-legali`, `disclaimer`), spostato fuori dall'if/else così compare identico su home, single e page SSR. `<nav>` → `<footer>` con border-top; copyright `© <year>`.
  - Nessun `page.php` creato: il template resolver di WP cade naturalmente su `index.php` per `is_page()`, e il ramo `elseif` lo gestisce.
  - Contenuto AI disclosure già presente nel body delle pagine `disclaimer` e `chi-siamo` (inserito con [create-legal-pages.php](.claude/worktrees/elated-pike-91cd1c/create-legal-pages.php)); questa PR non tocca i contenuti, rende solo le pagine raggiungibili da Googlebot — prerequisito per re-submit AdSense.
- [Fix Pack C — part 2: CLS ad slots (Bug #39)] Quattro fix coordinati per eliminare il Cumulative Layout Shift causato dagli slot AdSense, che contribuiva alla violazione "annunci su schermate senza contenuti del publisher":
  1. Aggiunto `min-height` di riserva su `.dn-ad-card` (280px base) in [app.js](wp-content/themes/domizionews-theme/assets/js/app.js) con classi slot-specifiche: `.dn-ad-banner` 120px, `.dn-ad-infeed` 300px, `.dn-ad-article` 280px. `.dn-ad-card .adsbygoogle { min-height: inherit }` propaga la riserva anche all'`<ins>` prima del fill AdSense → zero CLS.
  2. `renderAd()` ora tagga ogni slot con la classe del suo tipo tramite `slotClassMap` (`banner-nav`→`dn-ad-banner`, `home-feed`→`dn-ad-infeed`, `article-bottom`→`dn-ad-article`), così la riserva corretta si applica a ciascuno slot.
  3. Rimossa la funzione `dnap_insert_ad_slots()` da [core.php](wp-content/plugins/domizionews-ai-publisher/includes/core.php) e la sua call site: iniettava `<div class="dn-ad-slot dn-ad-inline" aria-label="Pubblicità">` vuoti nel `post_content` (posizioni [1] e [3] dei paragrafi), mai riempiti da CSS/JS reale. Googlebot poteva interpretarli come "annunci senza contenuto" — violazione diretta AdSense. Article HTML ora pulito; se servono in-article ads in futuro, wiring via SPA `renderAd()` che ha già la riserva spazio.
  4. `banner-nav` non è più gated da `!state.loading` nella riga root (`root.innerHTML = ...`): sempre renderizzato, con 120px riservati dal primo paint → nessun shift al passaggio loading→loaded.
  5. Rimossa regola CSS dead `.dn-ad-card img { aspect-ratio: 16/9 }`: nessun `<img>` è mai renderizzato dentro `.dn-ad-card` (solo `<ins class="adsbygoogle">`).
  - Opzioni orfane (`dnap_ad_pos_2`, `dnap_ad_pos_4`) non sono mai state esposte nell'admin UI — nessun cleanup necessario.
  - Obiettivo: risolvere la violazione AdSense 1 ("annunci su schermate senza contenuti del publisher") prima del re-submit.
- [Fix Pack C — part 3: Taxonomy archives + SEO hardening] Passaggio SEO Google-compliant sugli archivi tassonomici (city + category) e rafforzamento generale dei segnali di indicizzazione:
  1. **SSR archivi** in [index.php](wp-content/themes/domizionews-theme/index.php): nuovi rami `elseif ($is_city_archive && $archive_term)` e `elseif ($is_category_arch && $archive_term)` prima del fallback home. Ognuno emette `$seo_title` ("Nome Città | Domizio News" o "Pagina N"), `$seo_desc` dedicata ("Ultime notizie da Mondragone sul Litorale Domizio."), `$seo_canonical = get_term_link($term)` con suffix `page/N/` se paginato. `<link rel=prev/next>` e `BreadcrumbList` JSON-LD iniettati via `wp_head` priority 5. Body: H1 "Notizie da X" (città) o nome categoria, lista `have_posts()`, pulsante "Vedi altri articoli" come `<a href="/citta/<slug>/page/N/">` (vedi #7).
  2. **SPA URL sync** in [app.js](wp-content/themes/domizionews-theme/assets/js/app.js):
     - Click `[data-home-cat]` → `history.pushState(state, '', '/category/<slug>/')` (o `/` se "Tutte"). Click `[data-goto-city]` e `[data-city]` → pushState a `/citta/<slug>/` (o `/` su deselezione, perché non esiste un archivio `/citta/` root).
     - `window.onpopstate` esteso: regex `^citta\/([^/]+?)(?:\/page\/(\d+))?$` e `^category\/([^/]+?)(?:\/page\/(\d+))?$` ripristinano rispettivamente `tab=cities/selectedCity` e `tab=home/activeHomeCat`, con legal slugs in priorità.
     - `boot()` ha precedenza: legal → città → categoria → lookup articolo. I primi tre rami impostano state PRIMA del primo `render()` così utente/Googlebot atterrano sulla vista corretta senza flash di home; poi `loadData().then` dispatcha `loadCityFeed(slug)` o `loadCategoryFeed(slug)`.
  3. **Header label dinamico** (app.js:746): sub-header tab Città mostra `CITY_SLUG_LABELS[selectedCity]` quando c'è una città attiva, altrimenti "Città". Nuovo `syncBrowsingTitle()` (chiamato da `setState` e `boot`) aggiorna `document.title` — "<Città> | Domizio News", "<Categoria> | Domizio News" o `HOME_DEFAULT_TITLE` — saltando i rami già gestiti (`selectedPost` → `updateArticleHead`, `selectedLegalPage` → titolo SSR). `capitalizeFirst()` come fallback per slug non mappati.
  4. **Termini aggregati** in [core.php](wp-content/plugins/domizionews-ai-publisher/includes/core.php): `dnap_ensure_aggregate_city_terms()` su `init` priority 10 crea `cellole-baia-domizia` e `falciano-carinola` se assenti (`term_exists` check). I termini esistono nel DB subito dopo il deploy → archivi `/citta/cellole-baia-domizia/` e `/citta/falciano-carinola/` crawlabili da Google anche prima della riassegnazione articoli via WP-CLI (passo separato post-deploy).
  5. **noindex su 404 force-home**: già presente in [functions.php](wp-content/themes/domizionews-theme/functions.php) (filter `wp_robots` setta `noindex` quando `$GLOBALS['dnapp_was_404']` è true, alimentato dal `template_redirect` che normalizza 404 in 200 home). Nessuna modifica necessaria — verificato durante Phase 2.4.
  6. **Sitemap XML**: filter `wp_sitemaps_taxonomies` in functions.php aggiunge esplicitamente la tassonomia `city` al provider core. Googlebot ora scopre tutti gli URL `/citta/<slug>/` direttamente da `/wp-sitemap.xml`.
  7. **"Vedi altri articoli" progressive enhancement**: SSR emette un `<a class="dn-load-more" href="/citta/<slug>/page/N/" data-next-page data-archive-type data-archive-slug>` — crawlabile nativamente da Googlebot (link che porta a una pagina SSR-renderizzata paginata). Handler delegato su root click in app.js: `fetch('/wp-json/domizio/v1/posts?city=<slug>&page=N&per_page=20')`, inserisce le card prima del bottone, aggiorna `dataset.nextPage`, fa `pushState` del nuovo URL. Rispetta `Ctrl/Cmd/Shift+click` per apertura nativa in nuova tab. Se `posts.length<20` o `nextPage>=total_pages`, rimuove il bottone. Il REST `/domizio/v1/posts` supporta già il parametro `page` (verificato in [rest.php:103](wp-content/plugins/domizionews-ai-publisher/includes/rest.php:103)).
  8. **BreadcrumbList JSON-LD** su articoli singoli (`Home › Categoria › Città`) e su archivi (`Home › Città/Categorie › Termine`), emesso da `wp_head` priority 5. Su articoli con navigazione breadcrumb visibile (`<nav aria-label="Breadcrumb">`) in testa al body SSR, prima dell'h1 — link a `get_category_link()` e `get_term_link($city)` → internal linking aggiuntivo che aiuta il discovery di archivi e correlate da parte di Googlebot.
  - Risolve gli ultimi gap SEO segnalati dal rigetto AdSense: ogni URL ha canonical unica e corretta, full SSR content visibile, internal linking rich — prerequisito al re-submit.
- [Fix Pack C — part 3 addendum 1] `wp_robots` filter corretto in [functions.php](wp-content/themes/domizionews-theme/functions.php): rimossa la condizione `is_paged()` dal trigger noindex. Google dal 2019 tratta ogni URL paginato come contenuto indipendente; con il SSR archive ora in place (rel=prev/next + canonical paged-aware), `noindex` su `/citta/<slug>/page/N/` nascondeva indebitamente l'80% degli articoli dall'indicizzazione. Il filtro noindex ora scatta SOLO per `$GLOBALS['dnapp_was_404']` (force-home fallback) e per search/author/date archives, tutti privi di SSR dedicato.
- [Fix Pack C — part 3 addendum 2] **SPA hydration mode** per landing su archivi SSR:
  - SSR marker: `<main class="dn-archive-ssr dn-archive-city">` (o `dn-archive-category`) emesso da [index.php](wp-content/themes/domizionews-theme/index.php) nei rami archive, così l'SPA riconosce se il SSR è già a schermo.
  - `boot()` in [app.js](wp-content/themes/domizionews-theme/assets/js/app.js) rileva `canHydrate = hasSsrArchive && (cityBoot || catBoot)`. In tal caso setta `state.hydrated=true`, popola `state.tab/selectedCity/cityPage` (o `activeHomeCat/catPage`) direttamente — **senza chiamare `render()`** — installa `setupGlobalDelegation()` e chiama `loadData()`, poi esce. Il SSR resta a schermo: nessun flash, nessuna UI vuota, nessun sostituzione automatica.
  - `loadData()` rivista: in hydration mode scrive i dati in `state` via `Object.assign` invece di `setState`, evitando `render()`. Al primo click user su un tab/chip/logo, `setState` (che azzera `state.hydrated`) triggera un `render()` con dati già caricati — transizione user-initiated, non flash.
  - `setState()` azzera `state.hydrated = false` su ogni chiamata: qualsiasi azione utente esce dalla hydration mode e riporta al normale flusso SPA.
  - `"Vedi altro"` handler già hydration-safe: fa solo DOM manipulation (`insertBefore`, `remove`, `setAttribute`) + `history.pushState`, nessun `setState`. Durante la hydration l'utente può sfogliare pagine di archivio senza uscire dal SSR.
  - Trade-off: sulla pagina SSR archive non vengono mostrati AdSense (il SSR non include `<ins class="adsbygoogle">`). Accettabile: gli archivi sono pagine di discovery per Google, la monetizzazione principale avviene su articolo singolo e home — dove il flusso SPA standard resta invariato.
- [Fix Pack C — part 3 addendum 3] Post-deploy consolidated fixes (5 in un commit):
  1. **SSR archives: pagination effettiva**. I rami `is_tax('city')` e `is_category()` in [index.php](wp-content/themes/domizionews-theme/index.php) ora usano un `WP_Query` esplicito (`posts_per_page=20 + paged`) costruito una sola volta a inizio file e riusato sia dal `wp_head` (rel=prev/next calcolato da `$archive_total_pages`) sia dal body loop (`$archive_query->have_posts()`). Prima il global `$wp_query` ignorava il per_page forzato e serviva tutti gli articoli del termine in un singolo `<ul>` (in produzione: 100+ `<li>`). Ora 20 per pagina + "Vedi altro" gating corretto su `max_num_pages`.
  2. **Aggregate labels: niente slash**. `dnap_ensure_aggregate_city_terms()` in [core.php](wp-content/plugins/domizionews-ai-publisher/includes/core.php) ora nomina i termini `Cellole e Baia Domizia` e `Falciano e Carinola` (sostituendo `Cellole / Baia Domizia` e `Falciano / Carinola`). URL slug invariati (`cellole-baia-domizia`, `falciano-carinola` — già indicizzati). La funzione fa `wp_update_term` quando trova un nome diverso dalla lista canonica, così il rename avviene automaticamente al prossimo `init` post-deploy senza WP-CLI.
  3. **Menu SPA: cities individuali nascoste**. `dnapp_rest_config()` in [functions.php](wp-content/themes/domizionews-theme/functions.php) filtra dal payload `cities` i 4 slug `cellole`, `baia-domizia`, `falciano`, `carinola` — coperti dai 2 termini aggregati. Il menu a chip nell'SPA ora mostra solo Castel Volturno, Mondragone, Sessa Aurunca, Cellole e Baia Domizia, Falciano e Carinola (+ "Tutte"). Gli URL diretti `/citta/cellole/` continuano a risolvere via SSR (il ramo `is_tax('city')` legge il termine dall'URL, non dal config endpoint) — niente 404, compatibilità backlink preservata.
  4. **Button rename**: "Vedi altri articoli" → "Vedi altro" su SSR (link `.dn-load-more`) e su JS handler (label ripristinata dopo il fetch successivo in app.js).
  5. **SSR pages brand-consistent con la SPA**. Refactor completo di [index.php](wp-content/themes/domizionews-theme/index.php): **zero inline styles** (rimossi su articolo singolo, page legale, archivio città/categoria, home fallback, footer, breadcrumb). Tutto lo styling è ora in [base.css](wp-content/themes/domizionews-theme/assets/css/base.css) sotto il blocco "SSR pages (server-rendered)". Nuove classi: `.dn-ssr-main`, `.dn-ssr-h1`, `.dn-ssr-date`, `.dn-ssr-lead`, `.dn-ssr-hero`, `.dn-ssr-content`, `.dn-back-link`, `.dn-breadcrumb` + `.dn-breadcrumb-sep`, `.dn-archive-list`, `.dn-archive-item`, `.dn-archive-item-link`, `.dn-archive-item-date`, `.dn-archive-page-label`, `.dn-archive-empty`, `.dn-btn-primary` (per "Vedi altro"), `.dn-ssr-footer` + `.dn-ssr-footer-nav` + `.dn-ssr-footer-copy`. Palette Material Design 3 (violet #6750A4 primario, blue #1A73E8 link, grayscale 202124/5F6368/9AA0A6), Roboto, max-width 430px mobile-first, card con border-bottom e padding 16px. Single source of truth — il JS load-more handler in app.js ora inserisce dinamicamente articoli con le stesse classi, niente duplicazione.
- [Fix Pack C — part 3 addendum 4] Two post-verify fixes:
  * base.css and app.js enqueue switched to `filemtime()` for automatic cache-busting. Prior `ver=1.0.0` was cached browser-side, making the new SSR classes (`.dn-ssr-main`, `.dn-archive-item`, `.dn-btn-primary`, …) invisible to returning visitors. Every future CSS/JS edit now auto-busts without manual version bumps.
  * `$hidden_from_menu` filter in `dnapp_rest_config()` used the slug `falciano` but the real DB slug is `falciano-del-massico` (verified via `/wp-json/wp/v2/city` → `{id:6, name:"Falciano", slug:"falciano-del-massico"}`). Corrected so the SPA menu shows only the aggregates + Castel Volturno, Mondragone, Sessa Aurunca.
- [Cities menu asymmetric — 26/4]
  Owner spec: tab "Città" must show 7 individual cities, home must keep 5 entries with aggregates.
  * functions.php: removed server-side `$hidden_from_menu` filter in `dnapp_rest_config`. REST `/wp-json/dnapp/v1/config` now returns all 9 cities (7 individual + 2 aggregates).
  * app.js: added `AGGREGATE_CITY_SLUGS` constant (`['cellole-baia-domizia', 'falciano-carinola']`).
  * app.js: extended `CITY_SLUG_LABELS` with explicit `'cellole'` and `'baia-domizia'` entries (avoids `capitalizeFirst` fallback producing "Baia domizia" with lowercase d).
  * app.js: `buildCities()` chip iteration now applies `.filter(c => !AGGREGATE_CITY_SLUGS.includes(c.slug))` so the city tab shows only the 7 individuals.
  * Home unchanged: already iterates hardcoded `CITY_SLUGS` (5-entry constant with aggregates) — independent of REST.
  * Direct URLs `/citta/<slug>/` for all 9 cities continue to resolve via SSR (no change to taxonomy archive branches).
- [Aggregate city post union — 26/4]
  Server-side post union for virtual aggregate city terms (cellole-baia-domizia, falciano-carinola). Previously aggregates had zero posts directly assigned, requiring client-side double-fetch + JS merge in two locations of app.js (boot loadData + buildHome category path).
  * core.php: new helper `dnap_get_aggregate_city_subterms($slug)` — single source of truth, returns `['cellole','baia-domizia']` for `cellole-baia-domizia`, `['falciano-del-massico','carinola']` for `falciano-carinola`, `[]` otherwise. Lives next to `dnap_ensure_aggregate_city_terms()` to keep the aggregate definition co-located with its sub-term map.
  * rest.php: `/domizio/v1/posts?city=<slug>` expands aggregate slugs into multi-slug `tax_query` with `operator=IN`. WP_Query handles dedup (DISTINCT join) + global date sort.
  * index.php: SSR `is_tax('city')` branch detects aggregate via `$archive_term->slug` (with `function_exists()` guard for plugin-deactivated edge case), swaps `tax_query` from `term_id` to slug-array. Breadcrumb / canonical / h1 / `get_term_link($archive_term)` continue to use the aggregate term itself — only the posts query swaps.
  * app.js cleanup A: removed double-fetch + JS merge in `loadData` `fetchCityPosts` — single fetch path covers individual + aggregate uniformly.
  * app.js cleanup B: removed aggregate-aware merge branches in `buildHome` activeCat block — `state.homeCatPosts[slug]` lookup works for all CITY_SLUGS entries.
  * app.js loadCategoryFeed restructured: switched from one category-wide fetch + group-by-`p.cities[0].slug` to parallel per-CITY_SLUGS fetches (with both `city` + `category` REST params). This was required to make cleanup B safe — old grouping keyed `homeCatPosts` by physical sub-slugs (`cellole`, `baia-domizia`), so dropping the aggregate merge would have left the category-filter aggregate sections empty. Now `homeCatPosts` is keyed by CITY_SLUGS (matching `homeCityPosts`), and the new REST aggregate union populates aggregate buckets server-side.
  * Behavior:
    - `/citta/cellole-baia-domizia/` and `/citta/falciano-carinola/` now return merged posts (was empty)
    - REST honors aggregate slugs natively → SPA `loadCityFeed` works without special-casing
    - Correctness improvement: server merge sees ALL posts (not just first per_page from each sub-feed); the JS merge could miss recent posts in long-tail sub-cities
  * Out of scope (separate work):
    - Sitemap inclusion for aggregate URLs (term `count=0` excludes them from `wp-sitemap-taxonomies-city`; needs custom provider/filter)
    - Aggregate sticky-news (not a use case today; `dnap_get_sticky_per_city()` iterates only individual slugs by design)
- [Aggregate "Vedi altro" fix — 26/4] Removed obsolete `CITY_GOTO_TARGET` map from app.js (was redirecting `cellole-baia-domizia` → `cellole` and `falciano-carinola` → `falciano-del-massico` because aggregate URLs used to be empty). After the aggregate-post-union deploy aggregate URLs return real merged content, so `data-goto-city` now uses the slug directly — home "Vedi altro" buttons correctly link to `/citta/cellole-baia-domizia/` and `/citta/falciano-carinola/`.
- [Fase 2 hydration — 26/4] SPA pagination "Vedi altro" sulla tab Città: l'utente carica pagine successive senza lasciare la SPA, riusando il delegate handler `.dn-load-more` già esistente per gli archivi SSR.
  * `state` esteso: nuovi campi `cityPage` (default 1) e `cityFeedTotalPages` (default 1) tra `cityFeed` e `scopriStep` (app.js:96).
  * `loadCityFeed(slug, page=1, append=false)` — la firma diventa a 3 parametri:
    - URL costruito con `&page=N&per_page=20`.
    - `total_pages` letto dal body della response REST (`/domizio/v1/posts` ritorna `{posts, total, total_pages}` — verificato in [includes/rest.php:191-195](wp-content/plugins/domizionews-ai-publisher/includes/rest.php:191)).
    - `append=true` → `cityFeed = state.cityFeed.concat(newPosts)`; `append=false` → replace puro (comportamento attuale).
    - In entrambi i casi setState aggiorna `cityPage` e `cityFeedTotalPages`.
    - Slug vuoto resetta tutto a default.
  * `buildCities()` (app.js:751) appende un `<a class="dn-load-more dn-btn-primary">` dopo le card del feed città quando `state.cityFeedTotalPages > state.cityPage`. Stesse `data-archive-type="city"`, `data-archive-slug="<slug>"`, `data-next-page="<N+1>"` del bottone SSR archive — mandatorio perché il delegate handler `.dn-load-more` riconosca il bottone. `href="/citta/<slug>/page/<N+1>/"` per progressive enhancement (Googlebot/condivisione/Ctrl+click).
  * `.dn-load-more` handler (app.js:1380) esteso con `isSpaContext = !!loadMore.closest('.dn-feed')`:
    - SPA context: nuove card costruite via `buildArticleCard(p)` per coerenza visiva con il feed città (le card SSR archive `dn-archive-item` hanno layout diverso). Stato sincronizzato via mutation diretta su `state.cityFeed` / `state.cityPage` / `state.cityFeedTotalPages` — niente setState quindi niente render destructive (preserva scroll).
    - SSR context (non-`.dn-feed`): path esistente `dn-archive-item` invariato — zero regressione sugli archivi server-rendered.
  * Reset di `cityPage`/`cityFeedTotalPages` ai valori di default (1, 1) nei tre setState che cambiano città: `[data-goto-city]` (app.js:1595), `[data-city]` chip (app.js:1614), popstate `cityMatch` (app.js:1765).
  * Out of scope: pagination simmetrica per le category chips (richiede mirror loadCategoryFeed → al momento `loadCategoryFeed` fa parallel fetch per CITY_SLUGS che è una struttura diversa).
