# Handoff v1.7 — SSR ↔ SPA HTML Parity (Fase 3 hydration)

**Date:** 2026-04-26
**Branch:** `claude/ssr-spa-html-parity` (pushed; not auto-merged — user reviews + merges to develop manually)
**Touched files:**
- `wp-content/themes/domizionews-theme/index.php` (+445 / -147)
- `wp-content/themes/domizionews-theme/assets/css/base.css` (+245 / -145)
- `wp-content/themes/domizionews-theme/assets/js/app.js` (+79 / -303)
- `CLAUDE.md` (changelog appended)
- `docs/handoff/HANDOFF-v1.7-20260426.md` (this file)

## Goal

Eliminate the visual + structural divergence between SSR (PHP, `index.php`) and the SPA (`app.js`). After this PR:

- One CSS source (`base.css`) covers both surfaces.
- Hydration takeover is invisible — the DOM is already identical pre/post SPA boot.
- The dead `.dn-ssr-*` and `.dn-archive-*` class system is gone.
- Material Symbols (already loaded by `header.php`) finally used by SSR chrome.
- SEO fully preserved (all emissions live in `wp_head`; body markup only changed).

## Decisions made

1. **`timeAgo` decision A** — SSR emits the absolute date as the initial label inside each `.dn-time` span and the ISO timestamp in `data-timestamp`. JS function `hydrateTimestamps(root)` (`app.js:37`) walks the DOM after every `render()` and after boot in hydration mode, rewriting each label to the `timeAgo` relative form. Data is never stale, even with page caches.
2. **Excerpts dropped from SSR cards** — SPA cards have no excerpt; SSR now matches.
3. **`.dn-load-more` handler converged** — removed the `isSpaContext` branch. SSR + SPA emit identical `.dn-feed > .dn-card-list` shape, so a single code path handles every "Vedi altro" click.
4. **Bottom nav, chip bars, cards, logo, footer links emitted as `<a>` on SSR** — JS-off users navigate via real hrefs; SPA handlers `e.preventDefault()` to stay client-side in hydration mode.
5. **Hydration marker rewritten** — class `.dn-archive-city`/`.dn-archive-category` on `<main>` replaced by `data-ssr-archive="city|category"` on the `.dn-screen` wrapper.
6. **Footer + bottom nav kept on SSR single-article view** (delta vs SPA which omits both). Useful for crawlers and JS-off users; SPA replaces innerHTML on takeover so no conflict.

## Architecture after the PR

```
┌────────────────────── BROWSER ─────────────────────┐
│                                                    │
│  /citta/carinola/  →  Apache → WordPress → SSR    │
│                       index.php emits             │
│                       <div class="dn-screen"      │
│                            data-ssr-archive=     │
│                            "city">              │
│                            ...                  │
│                                                    │
│  ↓ HTML is byte-identical (modulo data-attrs)    │
│  ↓ to what SPA would render after rendering tab.  │
│                                                    │
│  ┌─── browser parses + paints ────────────────┐  │
│  │  Material Symbols loaded (header.php:11)   │  │
│  │  base.css loaded with all SPA styles       │  │
│  │  → user sees the EXACT final UI            │  │
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
│  │  hydrateTimestamps(rootEl)  ← rewrites    │  │
│  │       absolute dates → "2h fa" etc.        │  │
│  │  loadData()  ← populates state, no render │  │
│  │  return — NO innerHTML replacement         │  │
│  └────────────────────────────────────────────┘  │
│                                                    │
│  ↓ user clicks any tab/chip/card/logo            │
│                                                    │
│  ┌─── setState() ─────────────────────────────┐  │
│  │  state.hydrated = false                    │  │
│  │  render()                                  │  │
│  │  → root.innerHTML = ...SPA HTML...         │  │
│  │  → hydrateTimestamps(root)                 │  │
│  │  (DOM identical to what was there before;  │  │
│  │   no flash because both versions paint     │  │
│  │   the same.)                               │  │
│  └────────────────────────────────────────────┘  │
│                                                    │
└────────────────────────────────────────────────────┘
```

## Helper inventory (`index.php`)

| Helper | Mirrors | Lines |
|---|---|---|
| `dnapp_ssr_city_label($slug)` | `CITY_SLUG_LABELS` | 9 |
| `dnapp_ssr_top_header()` | `buildHeader()` (app.js:579) | ~12 |
| `dnapp_ssr_bottom_nav($active_tab)` | `buildNav()` (app.js:1010) | ~22 |
| `dnapp_ssr_footer()` | `buildFooter()` (app.js:723) | ~18 |
| `dnapp_ssr_city_chips($active_slug)` | chip bar of `buildCities()` (app.js:780) | ~18 |
| `dnapp_ssr_category_chips($active_cat)` | `buildCategoryChipsBar()` (app.js:592) | ~24 |
| `dnapp_ssr_pick_city($post_id)` | aggregate-filtered city pick | 8 |
| `dnapp_ssr_post_image($post_id, $size)` | featured + Unsplash fallback | 6 |
| `dnapp_ssr_article_card($post_id)` | `buildArticleCard()` (app.js:491) | ~30 |
| `dnapp_ssr_hero_card($post_id)` | `buildHeroCard()` (app.js:475) | ~32 |
| `dnapp_ssr_detail_header($title)` | sticky title bar | 5 |
| `dnapp_ssr_home_city_section($slug)` | section of `buildHome()` (app.js:692) | ~50 |

All use `esc_html()` / `esc_url()` / `esc_attr()` rigorously.

## Render branches (`index.php`)

| ID | When | Body markup |
|---|---|---|
| **S1** | `is_single()` | `.dn-detail-header` + `.dn-detail-img-wrap` + `.dn-detail-body` (badges + h1 + `.dn-byline` with `.dn-time[data-timestamp]` + `.dn-detail-content`) + footer + bottom nav |
| **S2** | `is_page()` | top header + `.dn-legal-page` (detail header + `.dn-legal-content`) + footer + bottom nav |
| **S3+S4** | `is_tax('city')` or `is_category()` | top header + `.dn-screen[data-ssr-archive]` (detail header with term name + chip bar + `.dn-feed` with cards + "Vedi altro") + footer + bottom nav (`cities` for city, `home` for category) |
| **S5** | home fallback | top header + `.dn-screen` (category chip bar with "Tutte" active + 5 city sections) + footer + bottom nav (`home`) |
| **S6** | 404 force-home | identical to S5; `wp_robots` adds `noindex` |

Slider (`buildSlider`) intentionally omitted on SSR — UX flair, not SEO-critical.

## CSS migration (`base.css`)

- `@import` Roboto upgraded `400;500;700` → `300;400;500;700` (300 needed by `.dn-logo-news`).
- New `:root` MD3 variables block.
- ~245 lines of component CSS migrated from `app.js STYLES` (the const literal at app.js:1045-1293).
- Anchor variants added (`a.dn-chip`, `a.dn-home-chip`, `a.dn-card-list`, `a.dn-card-hero`, `a.dn-section-label`, `a.dn-nav-tab`) with `text-decoration:none; color:inherit` for SSR JS-off navigation.
- Dead rules deleted: `.dn-ssr-main`, `.dn-ssr-h1`, `.dn-ssr-date`, `.dn-ssr-lead`, `.dn-ssr-hero`, `.dn-ssr-content` (+ children), `.dn-back-link`, `.dn-breadcrumb` (+ `.dn-breadcrumb-sep`, `a`, `:hover`), `.dn-archive-list`, `.dn-archive-item` (+ `:last-child`, `-link`, `-link h2`, `-link p`, `-date`), `.dn-archive-page-label`, `.dn-archive-empty`, `.dn-ssr-footer` (+ `-nav`, `-nav a`, `-nav a:hover`, `-copy`).
- Kept: `.dn-btn-primary` + `:hover` (still used by `.dn-load-more` on both surfaces).

## JS changes (`app.js`)

1. `STYLES` const emptied to `''` (line 1065). Four `<style>${STYLES}</style>` interpolation sites in `render()` left untouched (empty `<style>` tag is harmless).
2. `hydrateTimestamps(root)` added at line 37; called from:
   - `render()` end (line 1114) — every SPA render.
   - `boot()` hydration mode (line 1618) — after SSR archive detected.
   - `.dn-load-more` handler (line 1170) — for newly-inserted nodes.
3. SPA `buildArticleCard` and `buildHeroCard` updated to emit `data-timestamp="${escHtml(post.date)}"` next to `timeAgo(post.date)` so re-renders maintain freshness.
4. `.dn-load-more` handler converged (line 1116-1218) — removed the SSR-context branch (`art.className = 'dn-archive-item'`). Single code path: fetch → `buildArticleCard` per post → `insertBefore` → `hydrateTimestamps` → state mutation.
5. `boot()` hydration detection (line 1583) switched from class selector `.dn-archive-city, .dn-archive-category` to attribute selector `[data-ssr-archive]`.
6. `e.preventDefault()` added to handlers for `#dn-logo-home`, `#dn-article-logo`, `[data-home-cat]`, `[data-goto-city]`, `[data-city]`, `[data-tab]`. Conditional preventDefault on `[data-post-id]` (only when post is in state — otherwise let anchor href navigate naturally).

## SEO verification

`functions.php` is **untouched** (`git diff HEAD -- functions.php` is empty). All `wp_head` callbacks, filters, and JSON-LD emissions live in `index.php` lines 111-219 (setup block, before `get_header()`). The body markup region (line 540+) emits zero `<meta>` / `<link rel>` / JSON-LD scripts.

Validated:
- `pre_get_document_title` filter (line 111) — unchanged.
- `document_title_parts` filter (112) — unchanged.
- `<meta name="description">` + `og:*` (119, priority 2) — unchanged.
- `<link rel="canonical">` (126) — unchanged (only emitted on non-singular).
- `<link rel="prev/next">` (147-152, priority 5) — unchanged.
- `BreadcrumbList JSON-LD` archives (144) + articles (174, both priority 5) — unchanged.
- `ItemList + NewsMediaOrganization JSON-LD` home (198) — unchanged.
- `wp_robots` filter (functions.php:37) — unchanged.
- `template_redirect` 404→home logic (functions.php:295) — unchanged.

## Performance check

Home SSR (S5) issues exactly **5 `WP_Query` calls** (one per city section, all with `no_found_rows=true`). Plus the pre-existing 1 query for `ItemList JSON-LD` and 1 for SEO meta = **7 queries total per home request**. Within the documented 6-query *per-city-loop* budget.

Aggregate cities (`cellole-baia-domizia`, `falciano-carinola`) use multi-slug `tax_query` via `dnap_get_aggregate_city_subterms()` (single source of truth in `core.php:94`).

## What was NOT touched

- `functions.php` — entirely (asserted by empty `git diff`).
- `header.php` / `footer.php` / plugin code.
- `base.css` lines 1-53 (page reset, root container, scrollbar hiding, safe-area).
- `.dn-btn-primary` rules in `base.css`.
- All SEO/JSON-LD emissions in `wp_head`.
- Plugin DNAP pipeline (`core.php`, `gpt.php`, `media.php`, `rest.php`).
- The `STYLES` const reference in `render()` (kept as `''` for backward compatibility).

## Manual QA checklist for reviewer

- [ ] `/` (home) renders with 5 city sections, each with 1 hero + 2 list cards. Top header + category chips + bottom nav present.
- [ ] `/citta/carinola/` shows top header → "Carinola" detail header → city chip bar (Carinola active) → article cards → "Vedi altro" if >20 posts.
- [ ] `/citta/cellole-baia-domizia/` shows merged Cellole + Baia Domizia posts; chip bar shows 7 individual cities (no aggregate chip active since aggregate isn't in chip list).
- [ ] `/category/cronaca/` mirrors above with "Cronaca" header + category chip bar (Cronaca active).
- [ ] `/qualche-articolo-slug/` (single article) shows `.dn-detail-header` (back arrow + logo + share placeholder) + image + badges + h1 + byline + content + footer + bottom nav.
- [ ] `/chi-siamo/` (legal page) shows top header + page title in detail header + content + footer + bottom nav.
- [ ] With JavaScript disabled in browser: every link in chip bars, bottom nav, footer, cards, logo, "Vedi altro" works. No 404s except the spec'd `/citta/` (force-home noindex via wp_robots).
- [ ] With JavaScript enabled landing on `/citta/carinola/`: SSR is visible, SPA boots in hydration mode, no flash, dates rewrite from "26 apr 2026" to "2h fa" (or similar). Click "Vedi altro" → SPA fetches page 2 and appends cards in same .dn-feed without scroll loss.
- [ ] After SPA boot, click logo → home view appears (no full reload). Click bottom nav tabs → SPA switches view.
- [ ] Page title `<title>` and meta description match expected values per route.
- [ ] BreadcrumbList JSON-LD validates in https://search.google.com/test/rich-results.

## Known follow-ups (out of scope)

- Footer copy in SPA still hardcoded "© 2026" (`app.js:734`); SSR is dynamic. Update SPA at year rollover.
- Sticky-news in slider not in SSR — by design (SPA-only flair).
- Aggregate sticky-news — `dnap_get_sticky_per_city()` iterates only individual slugs (intentional).
- Pagination symmetry for category chips on SPA tab Città not yet aligned (`loadCategoryFeed` does parallel fetches, different shape from `loadCityFeed`). Not changed in this PR.
- The four `<style>${STYLES}</style>` interpolation sites in `render()` could be removed in a follow-up; left as-is to keep the surface area of this PR limited.
