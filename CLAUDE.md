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
- [includes/core.php](wp-content/plugins/domizionews-ai-publisher/includes/core.php) — **874 lines**. The import loop (`dnap_import_now`): the `city` taxonomy, anti-duplicate logic (URL + hash + 70%-similar title over 30 days), Google News URL resolver (base64 → enclosure → links → href in content → HTTP redirect), local-content keyword filter, city-slug extractor, VIP/sticky detection, ad-slot injection, and the async Telegram dispatcher.
- [includes/gpt.php](wp-content/plugins/domizionews-ai-publisher/includes/gpt.php) — **442 lines**. Anthropic API wrapper (`dnap_call_claude`, model `claude-haiku-4-5`) plus the editorial rewrite function `dnap_gpt_rewrite` that returns the JSON envelope (title, slug, excerpt, meta_description, content, category, cities, tags, image_prompt, image_symbol, social_caption, skip). Includes retry with backoff on 429/5xx, a max_tokens retry, and prompt-injection defense.
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
