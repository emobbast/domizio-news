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
