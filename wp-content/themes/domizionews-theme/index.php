<?php
/**
 * Domizio News App Theme — index.php
 * SSR fallback per crawler + pretty permalink. Dopo Fase 3 (hydration parity)
 * il body emette gli STESSI nomi di classe del SPA — single source of truth
 * in base.css.
 *
 * - is_single()      → articolo singolo
 * - is_page()        → legal page / WP page
 * - is_tax('city')   → archivio città
 * - is_category()    → archivio categoria
 * - fallback         → home (sezioni per città, mirror buildHome SPA)
 */

$logo_url         = get_theme_file_uri('assets/images/logo.png');
$single_post      = is_single() ? get_queried_object() : null;
$page_obj         = is_page()   ? get_queried_object() : null;
$is_city_archive  = is_tax('city');
$is_category_arch = is_category();
$archive_term     = ($is_city_archive || $is_category_arch) ? get_queried_object() : null;
$archive_paged    = max(1, (int) get_query_var('paged'));

// Build the archive WP_Query eagerly (reused by wp_head rel=prev/next and
// by the body loop). Forces posts_per_page=20 regardless of admin Reading
// Settings, and uses term_id/cat for a clean tax filter.
$archive_query = null;
$archive_total_pages = 1;
if (($is_city_archive || $is_category_arch) && $archive_term && !is_wp_error($archive_term)) {
  $_archive_args = [
    'post_type'      => 'post',
    'post_status'    => 'publish',
    'posts_per_page' => 20,
    'paged'          => $archive_paged,
    'orderby'        => 'date',
    'order'          => 'DESC',
    'ignore_sticky_posts' => true,
  ];
  if ($is_city_archive) {
    // Expand aggregate slug to sub-terms; otherwise use the term_id directly.
    $aggregate_subs = function_exists('dnap_get_aggregate_city_subterms')
      ? dnap_get_aggregate_city_subterms($archive_term->slug)
      : [];
    if (!empty($aggregate_subs)) {
      $_archive_args['tax_query'] = [[
        'taxonomy' => 'city',
        'field'    => 'slug',
        'terms'    => $aggregate_subs,
        'operator' => 'IN',
      ]];
    } else {
      $_archive_args['tax_query'] = [[
        'taxonomy' => 'city',
        'field'    => 'term_id',
        'terms'    => $archive_term->term_id,
      ]];
    }
  } else {
    $_archive_args['cat'] = $archive_term->term_id;
  }
  $archive_query = new WP_Query($_archive_args);
  $archive_total_pages = (int) $archive_query->max_num_pages;
}

// ── SEO META ─────────────────────────────────────────────────────────────────
if ($single_post) {
  $seo_title     = wp_strip_all_tags($single_post->post_title) . ' | Domizio News';
  $raw_desc      = $single_post->post_excerpt ?: $single_post->post_content;
  $seo_desc      = wp_trim_words(wp_strip_all_tags($raw_desc), 30);
  $seo_image     = get_the_post_thumbnail_url($single_post->ID, 'large')
                ?: (string) get_post_meta($single_post->ID, '_dnap_external_image', true);
  $seo_canonical = get_permalink($single_post->ID);
} elseif ($page_obj) {
  $seo_title     = wp_strip_all_tags($page_obj->post_title) . ' | Domizio News';
  $raw_desc      = $page_obj->post_excerpt ?: $page_obj->post_content;
  $seo_desc      = wp_trim_words(wp_strip_all_tags($raw_desc), 25, '...');
  $seo_image     = '';
  $seo_canonical = get_permalink($page_obj->ID);
} elseif ($is_city_archive && $archive_term && !is_wp_error($archive_term)) {
  $term_base     = get_term_link($archive_term);
  if (is_wp_error($term_base)) $term_base = home_url('/citta/' . $archive_term->slug . '/');
  $seo_title     = esc_html($archive_term->name) . ' | Domizio News'
                 . ($archive_paged > 1 ? ' — Pagina ' . $archive_paged : '');
  $seo_desc      = 'Ultime notizie da ' . $archive_term->name
                 . ' sul Litorale Domizio. Cronaca, sport, politica ed eventi.';
  $seo_image     = '';
  $seo_canonical = $archive_paged > 1 ? $term_base . 'page/' . $archive_paged . '/' : $term_base;
} elseif ($is_category_arch && $archive_term && !is_wp_error($archive_term)) {
  $term_base     = get_term_link($archive_term);
  if (is_wp_error($term_base)) $term_base = home_url('/category/' . $archive_term->slug . '/');
  $seo_title     = esc_html($archive_term->name) . ' | Domizio News'
                 . ($archive_paged > 1 ? ' — Pagina ' . $archive_paged : '');
  $seo_desc      = 'Notizie di ' . $archive_term->name
                 . ' dal Litorale Domizio: Mondragone, Castel Volturno, Baia Domizia e dintorni.';
  $seo_image     = '';
  $seo_canonical = $archive_paged > 1 ? $term_base . 'page/' . $archive_paged . '/' : $term_base;
} else {
  $seo_title     = get_bloginfo('name');
  $seo_desc      = get_bloginfo('description') ?: 'Notizie in tempo reale dal Litorale Domizio. Cronaca, sport, politica ed eventi da Mondragone, Castel Volturno, Baia Domizia e dintorni.';
  $seo_image     = '';
  $seo_canonical = 'https://domizionews.it/';
  // Use latest post for meta
  $seo_q = new WP_Query(['post_type'=>'post','post_status'=>'publish','posts_per_page'=>1,'orderby'=>'date','order'=>'DESC','ignore_sticky_posts'=>true]);
  if ($seo_q->have_posts()) {
    $seo_q->the_post();
    $raw_desc  = get_the_excerpt() ?: get_the_content();
    $seo_image = get_the_post_thumbnail_url(null, 'large') ?: (string) get_post_meta(get_the_ID(), '_dnap_external_image', true);
    wp_reset_postdata();
  }
}

add_filter('pre_get_document_title', fn() => $seo_title, 999);
add_filter('document_title_parts', function($parts) use ($seo_title) {
  $parts['title'] = $seo_title;
  unset($parts['tagline']);
  unset($parts['site']);
  return $parts;
}, 999);

add_action('wp_head', function() use ($seo_title, $seo_desc, $seo_image, $seo_canonical, $single_post, $page_obj, $is_city_archive, $is_category_arch) {
  $is_article_like = $single_post || $page_obj;
  $og_type = $is_article_like ? 'article' : 'website';
  ?>
  <meta name="description" content="<?php echo esc_attr($seo_desc); ?>">
  <?php
  // WP core (rel_canonical) emette già <link rel=canonical> su viste singular
  // (single + page). Per home/archive tassonomici/categoria lo emettiamo qui
  // — con pagination suffix quando paged>1 — per evitare canonical duplicati.
  if (!$single_post && !$page_obj): ?>
  <link rel="canonical" href="<?php echo esc_url($seo_canonical); ?>">
  <?php endif; ?>
  <meta property="og:type" content="<?php echo esc_attr($og_type); ?>">
  <meta property="og:title" content="<?php echo esc_attr($seo_title); ?>">
  <meta property="og:description" content="<?php echo esc_attr($seo_desc); ?>">
  <meta property="og:url" content="<?php echo esc_url($seo_canonical); ?>">
  <meta property="og:site_name" content="Domizio News">
  <meta property="og:locale" content="it_IT">
  <?php if ($seo_image): ?>
  <meta property="og:image" content="<?php echo esc_url($seo_image); ?>">
  <?php endif; ?>
<?php }, 2);

// ── rel=prev/next + BreadcrumbList per archivi città/categoria ───────────────
if (($is_city_archive || $is_category_arch) && $archive_term && !is_wp_error($archive_term)) {
  add_action('wp_head', function() use ($archive_term, $archive_paged, $is_city_archive, $archive_total_pages) {
    $total = $archive_total_pages;
    $base  = get_term_link($archive_term);
    if (is_wp_error($base)) return;

    if ($archive_paged > 1) {
      $prev = $archive_paged === 2 ? $base : $base . 'page/' . ($archive_paged - 1) . '/';
      echo '<link rel="prev" href="' . esc_url($prev) . '">' . "\n";
    }
    if ($archive_paged < $total) {
      echo '<link rel="next" href="' . esc_url($base . 'page/' . ($archive_paged + 1) . '/') . '">' . "\n";
    }

    $lvl2_name = $is_city_archive ? 'Città' : 'Categorie';
    $lvl2_url  = $is_city_archive ? home_url('/citta/') : home_url('/category/');
    $schema = [
      '@context' => 'https://schema.org',
      '@type'    => 'BreadcrumbList',
      'itemListElement' => [
        ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home',       'item' => home_url('/')],
        ['@type' => 'ListItem', 'position' => 2, 'name' => $lvl2_name,   'item' => $lvl2_url],
        ['@type' => 'ListItem', 'position' => 3, 'name' => $archive_term->name, 'item' => $base],
      ],
    ];
    echo '<script type="application/ld+json">' . wp_json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</script>' . "\n";
  }, 5);
}

// ── BreadcrumbList su articoli singoli (internal linking + rich results) ────
if ($single_post) {
  add_action('wp_head', function() use ($single_post) {
    $cats   = get_the_category($single_post->ID);
    $cat    = !empty($cats) ? $cats[0] : null;
    $cities = wp_get_post_terms($single_post->ID, 'city');
    $city   = (!is_wp_error($cities) && !empty($cities)) ? $cities[0] : null;

    $items = [['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => home_url('/')]];
    $pos = 2;
    if ($cat) {
      $items[] = ['@type' => 'ListItem', 'position' => $pos++, 'name' => $cat->name, 'item' => get_category_link($cat->term_id)];
    }
    if ($city) {
      $city_link = get_term_link($city);
      if (!is_wp_error($city_link)) {
        $items[] = ['@type' => 'ListItem', 'position' => $pos++, 'name' => $city->name, 'item' => $city_link];
      }
    }
    $schema = ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => $items];
    echo '<script type="application/ld+json">' . wp_json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</script>' . "\n";
  }, 5);
}

// ── ItemList JSON-LD (home) ─────────────────────────────────────────────────
if (!$single_post && !$page_obj && !$is_city_archive && !$is_category_arch) {
  add_action('wp_head', function() use ($logo_url) {
    $schema_q = new WP_Query(['post_type'=>'post','post_status'=>'publish','posts_per_page'=>10,'orderby'=>'date','order'=>'DESC','ignore_sticky_posts'=>true]);
    $items = []; $pos = 1;
    while ($schema_q->have_posts()) {
      $schema_q->the_post();
      $img = get_the_post_thumbnail_url(null, 'large') ?: (string) get_post_meta(get_the_ID(), '_dnap_external_image', true);
      $item = [
        '@type' => 'NewsArticle',
        'headline' => wp_strip_all_tags(get_the_title()),
        'url' => get_permalink(),
        'datePublished' => get_the_date('c'),
        'author' => ['@type' => 'Organization', 'name' => 'Redazione'],
        'publisher' => ['@type' => 'NewsMediaOrganization', 'name' => 'Domizio News', 'logo' => ['@type' => 'ImageObject', 'url' => $logo_url]],
      ];
      if ($img) $item['image'] = $img;
      $items[] = ['@type' => 'ListItem', 'position' => $pos++, 'item' => $item];
    }
    wp_reset_postdata();
    $schema = ['@context' => 'https://schema.org', '@graph' => [
      ['@type' => 'NewsMediaOrganization', 'name' => 'Domizio News', 'url' => 'https://domizionews.it/', 'logo' => ['@type' => 'ImageObject', 'url' => $logo_url]],
      ['@type' => 'ItemList', 'itemListElement' => $items],
    ]];
    echo '<script type="application/ld+json">' . wp_json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</script>' . "\n";
  }, 5);
}

// ─────────────────────────────────────────────────────────────────────────────
// SSR HELPERS
// Mirror dei builder JS in app.js (buildHeader, buildNav, buildFooter,
// buildArticleCard, buildHeroCard, buildCardBadges, chip bar di buildCities/
// buildCategoryChipsBar). Stesse classi del SPA — single CSS source of truth
// in base.css. Su SSR le interazioni JS sono assenti: i bottoni che il SPA
// gestisce via delegate handler diventano ancore con href reali, così la
// navigazione funziona anche con JavaScript disattivato.
// ─────────────────────────────────────────────────────────────────────────────

// Slug aggregati: usati per filtrare le città fisiche dai badge card e dalla
// chip bar Città. Single source of truth in dnap_get_aggregate_city_subterms()
// (core.php:94) — qui solo la lista delle CHIAVI aggregate.
const DNAPP_AGGREGATE_CITY_SLUGS = ['cellole-baia-domizia', 'falciano-carinola'];

// Map slug → label visualizzata. Mirror di CITY_SLUG_LABELS in app.js.
function dnapp_ssr_city_label($slug) {
  $labels = [
    'mondragone'           => 'Mondragone',
    'castel-volturno'      => 'Castel Volturno',
    'cellole'              => 'Cellole',
    'baia-domizia'         => 'Baia Domizia',
    'cellole-baia-domizia' => 'Cellole e Baia Domizia',
    'falciano-del-massico' => 'Falciano del Massico',
    'carinola'             => 'Carinola',
    'falciano-carinola'    => 'Falciano e Carinola',
    'sessa-aurunca'        => 'Sessa Aurunca',
  ];
  return $labels[$slug] ?? ucfirst(str_replace('-', ' ', $slug));
}

function dnapp_ssr_top_header() {
  $home = esc_url(home_url('/'));
  ?>
  <header class="dn-top-header">
    <button class="dn-header-btn" id="dn-header-search" type="button" aria-label="Cerca">
      <span class="material-symbols-outlined" style="font-size:24px;color:#6750A4;">search</span>
    </button>
    <a class="dn-logo" id="dn-logo-home" href="<?php echo $home; ?>" aria-label="Domizio News — Home" style="text-decoration:none;">
      <span class="dn-logo-domizio">Domizio</span>
      <span class="dn-logo-news">news</span>
    </a>
    <div class="dn-header-avatar" aria-hidden="true">D</div>
  </header>
  <?php
}

function dnapp_ssr_bottom_nav($active_tab = null) {
  // SSR: usa <a href> così la nav funziona con JavaScript disattivato.
  // Il SPA sostituisce l'intero #domizionews-root.innerHTML al boot, quindi
  // dopo l'hydration questi <a> spariscono e tornano i <button> SPA.
  $tabs = [
    ['id' => 'home',       'label' => 'Home',   'icon' => 'home',           'href' => home_url('/')],
    ['id' => 'cities',     'label' => 'Città',  'icon' => 'location_city',  'href' => home_url('/citta/')],
    ['id' => 'categories', 'label' => 'Scopri', 'icon' => 'explore',        'href' => home_url('/')],
  ];
  echo '<nav class="dn-bottom-nav">';
  foreach ($tabs as $t) {
    $is_active  = ($active_tab === $t['id']);
    $tab_cls    = 'dn-nav-tab'      . ($is_active ? ' active' : '');
    $wrap_cls   = 'dn-nav-icon-wrap' . ($is_active ? ' active' : '');
    printf(
      '<a class="%s" href="%s" data-tab="%s"><div class="%s"><span class="material-symbols-outlined">%s</span></div><span class="dn-nav-label">%s</span></a>',
      esc_attr($tab_cls),
      esc_url($t['href']),
      esc_attr($t['id']),
      esc_attr($wrap_cls),
      esc_html($t['icon']),
      esc_html($t['label'])
    );
  }
  echo '</nav>';
}

function dnapp_ssr_footer() {
  $links = [
    ['Chi Siamo',      'chi-siamo'],
    ['Contatti',       'contatti'],
    ['Privacy Policy', 'privacy-policy'],
    ['Cookie Policy',  'cookie-policy'],
    ['Note Legali',    'note-legali'],
    ['Disclaimer',     'disclaimer'],
  ];
  echo '<footer class="dn-footer"><div class="dn-footer-links">';
  foreach ($links as $row) {
    [$label, $slug] = $row;
    printf(
      '<a href="%s" data-legal="%s">%s</a>',
      esc_url(home_url('/' . $slug . '/')),
      esc_attr($slug),
      esc_html($label)
    );
  }
  printf('</div><p class="dn-footer-copy">© %s Domizio News</p></footer>', esc_html(date('Y')));
}

function dnapp_ssr_city_chips($active_slug = '') {
  // Mirror della chip bar in buildCities (app.js:780-786): 7 città individuali,
  // aggregati esclusi. Order: per ordine slug nei termini DB (alfabetico per
  // name). data-city resta presente per compatibilità con eventuali handler
  // delegati post-hydration; href è il primary path JS-off.
  $terms = get_terms([
    'taxonomy'   => 'city',
    'hide_empty' => false,
    'orderby'    => 'name',
    'order'      => 'ASC',
  ]);
  if (is_wp_error($terms) || empty($terms)) return;

  echo '<div class="dn-chips-scroll">';
  foreach ($terms as $term) {
    if (in_array($term->slug, DNAPP_AGGREGATE_CITY_SLUGS, true)) continue;
    $term_link = get_term_link($term);
    if (is_wp_error($term_link)) continue;
    $is_active = ($term->slug === $active_slug);
    printf(
      '<a class="dn-chip%s" href="%s" data-city="%s">%s</a>',
      $is_active ? ' active' : '',
      esc_url($term_link),
      esc_attr($term->slug),
      esc_html($term->name)
    );
  }
  echo '</div>';
}

function dnapp_ssr_category_chips($active_cat_slug = '') {
  // Mirror di HOME_CATEGORIES in app.js:552-563. Tenere ordine sincronizzato.
  $cats = [
    ['slug' => '',         'name' => 'Tutte'],
    ['slug' => 'cronaca',  'name' => 'Cronaca'],
    ['slug' => 'sport',    'name' => 'Sport'],
    ['slug' => 'politica', 'name' => 'Politica'],
    ['slug' => 'economia', 'name' => 'Economia'],
    ['slug' => 'ambiente', 'name' => 'Ambiente'],
    ['slug' => 'eventi',   'name' => 'Eventi'],
    ['slug' => 'salute',   'name' => 'Salute'],
  ];
  echo '<div class="dn-home-chips">';
  foreach ($cats as $c) {
    $is_active = ($active_cat_slug === $c['slug']);
    $href = ($c['slug'] === '') ? home_url('/') : home_url('/category/' . $c['slug'] . '/');
    printf(
      '<a class="dn-home-chip%s" href="%s" data-home-cat="%s">%s</a>',
      $is_active ? ' active' : '',
      esc_url($href),
      esc_attr($c['slug']),
      esc_html($c['name'])
    );
  }
  echo '</div>';
}

// Restituisce la prima città non-aggregata associata a un post, o null.
function dnapp_ssr_pick_city($post_id) {
  $cities = wp_get_post_terms($post_id, 'city');
  if (is_wp_error($cities) || empty($cities)) return null;
  foreach ($cities as $c) {
    if (!in_array($c->slug, DNAPP_AGGREGATE_CITY_SLUGS, true)) return $c;
  }
  return null;
}

// Risolve l'URL di un'immagine in evidenza con fallback _dnap_external_image
// (Unsplash). Mirror della logica usata da $seo_image (index.php:67, :103, :200).
function dnapp_ssr_post_image($post_id, $size = 'medium') {
  $url = get_the_post_thumbnail_url($post_id, $size);
  if ($url) return $url;
  $ext = (string) get_post_meta($post_id, '_dnap_external_image', true);
  return $ext ?: '';
}

// Card list (mirror buildArticleCard in app.js:491-504). Outer è <a> per
// navigation JS-off; SPA emette <article> + delegate click handler. CSS
// .dn-card-list si applica a entrambe.
function dnapp_ssr_article_card($post_id) {
  $cats     = get_the_category($post_id);
  $cat_pick = !empty($cats) ? $cats[0] : null;
  $city     = dnapp_ssr_pick_city($post_id);
  $img_url  = dnapp_ssr_post_image($post_id, 'medium');
  $title    = get_the_title($post_id);
  $perm     = get_permalink($post_id);
  $iso_date = get_the_date('c', $post_id);
  $abs_date = get_the_date('j M Y', $post_id);
  ?>
  <a class="dn-card-list" href="<?php echo esc_url($perm); ?>" data-post-id="<?php echo (int) $post_id; ?>">
    <div class="dn-card-body">
      <?php if ($cat_pick || $city): ?>
      <div class="dn-card-badges">
        <?php if ($cat_pick): ?><span class="dn-cat-label"><?php echo esc_html($cat_pick->name); ?></span><?php endif; ?>
        <?php if ($city):     ?><span class="dn-city-label"><?php echo esc_html($city->name); ?></span><?php endif; ?>
      </div>
      <?php endif; ?>
      <h3><?php echo esc_html($title); ?></h3>
      <span class="dn-time" data-timestamp="<?php echo esc_attr($iso_date); ?>"><?php echo esc_html($abs_date); ?></span>
    </div>
    <?php if ($img_url): ?>
      <img src="<?php echo esc_url($img_url); ?>" alt="" loading="lazy">
    <?php else: ?>
      <div style="background:#EADDFF;width:80px;height:80px;border-radius:8px;flex-shrink:0;display:flex;align-items:center;justify-content:center;">
        <span class="material-symbols-outlined" style="font-size:32px;color:#6750A4;">article</span>
      </div>
    <?php endif; ?>
  </a>
  <?php
}

// Hero card (mirror buildHeroCard in app.js:475-487). Usata come prima card
// di ogni sezione città in home.
function dnapp_ssr_hero_card($post_id) {
  $cats     = get_the_category($post_id);
  $cat_pick = !empty($cats) ? $cats[0] : null;
  $city     = dnapp_ssr_pick_city($post_id);
  $img_url  = dnapp_ssr_post_image($post_id, 'large');
  $title    = get_the_title($post_id);
  $perm     = get_permalink($post_id);
  $iso_date = get_the_date('c', $post_id);
  $abs_date = get_the_date('j M Y', $post_id);
  ?>
  <a class="dn-card-hero" href="<?php echo esc_url($perm); ?>" data-post-id="<?php echo (int) $post_id; ?>">
    <div class="dn-card-hero-img">
      <?php if ($img_url): ?>
        <img src="<?php echo esc_url($img_url); ?>" alt="" loading="eager">
      <?php else: ?>
        <div style="background:#EADDFF;aspect-ratio:16/9;display:flex;align-items:center;justify-content:center;">
          <span class="material-symbols-outlined" style="font-size:48px;color:#6750A4;">article</span>
        </div>
      <?php endif; ?>
    </div>
    <div class="dn-card-hero-body">
      <?php if ($cat_pick || $city): ?>
      <div class="dn-card-badges">
        <?php if ($cat_pick): ?><span class="dn-cat-label"><?php echo esc_html($cat_pick->name); ?></span><?php endif; ?>
        <?php if ($city):     ?><span class="dn-city-label"><?php echo esc_html($city->name); ?></span><?php endif; ?>
      </div>
      <?php endif; ?>
      <h3 class="dn-card-hero-title"><?php echo esc_html($title); ?></h3>
      <span class="dn-time" data-timestamp="<?php echo esc_attr($iso_date); ?>"><?php echo esc_html($abs_date); ?></span>
    </div>
  </a>
  <?php
}

// Bottone "Vedi altro" stile pillola (mirror della .dn-city-more della home,
// app.js:721-723). Single source of truth per S3 (city archive) e S4
// (category archive), e — combinato con il SPA emit in buildCities — riusa
// la regola CSS .dn-city-more in base.css. La classe .dn-load-more resta
// invariata: il delegate handler in app.js la intercetta sia su SSR sia su SPA.
function dnapp_ssr_vedi_altro_button($next_url, $archive_type, $archive_slug, $next_page) {
  ?>
  <div class="dn-city-more-wrap">
    <a class="dn-load-more dn-city-more"
       href="<?php echo esc_url($next_url); ?>"
       data-archive-type="<?php echo esc_attr($archive_type); ?>"
       data-archive-slug="<?php echo esc_attr($archive_slug); ?>"
       data-next-page="<?php echo (int) $next_page; ?>">
      <span class="material-symbols-outlined" style="font-size:18px;">newspaper</span>Vedi altro
    </a>
  </div>
  <?php
}

function dnapp_ssr_detail_header($title) {
  printf(
    '<div class="dn-detail-header"><div style="width:32px;"></div><span style="font-size:16px;font-weight:500;color:var(--color-text);">%s</span><div style="width:32px;"></div></div>',
    esc_html($title)
  );
}

// Sezione città in home: 3 articoli (1 hero + 2 list cards), label cliccabile,
// "Vedi altro" link all'archivio. Mirror di buildHome SPA (app.js:692-705).
// Aggregati gestiti con multi-slug tax_query via dnap_get_aggregate_city_subterms.
function dnapp_ssr_home_city_section($city_slug) {
  $aggregate_subs = function_exists('dnap_get_aggregate_city_subterms')
    ? dnap_get_aggregate_city_subterms($city_slug)
    : [];

  $tax_query = !empty($aggregate_subs)
    ? [['taxonomy' => 'city', 'field' => 'slug', 'terms' => $aggregate_subs, 'operator' => 'IN']]
    : [['taxonomy' => 'city', 'field' => 'slug', 'terms' => [$city_slug]]];

  $q = new WP_Query([
    'post_type'           => 'post',
    'post_status'         => 'publish',
    'posts_per_page'      => 3,
    'orderby'             => 'date',
    'order'               => 'DESC',
    'ignore_sticky_posts' => true,
    'tax_query'           => $tax_query,
    'no_found_rows'       => true,
  ]);
  if (!$q->have_posts()) { wp_reset_postdata(); return; }

  $label = dnapp_ssr_city_label($city_slug);
  $term  = get_term_by('slug', $city_slug, 'city');
  if ($term && !is_wp_error($term)) {
    $href = get_term_link($term);
    if (is_wp_error($href)) $href = home_url('/citta/' . $city_slug . '/');
  } else {
    $href = home_url('/citta/' . $city_slug . '/');
  }
  ?>
  <section class="dn-city-section">
    <a class="dn-section-label" href="<?php echo esc_url($href); ?>" data-goto-city="<?php echo esc_attr($city_slug); ?>"><?php echo esc_html($label); ?></a>
    <div class="dn-feed">
      <?php
      $i = 0;
      while ($q->have_posts()) { $q->the_post();
        if ($i === 0) { dnapp_ssr_hero_card(get_the_ID()); }
        else          { dnapp_ssr_article_card(get_the_ID()); }
        $i++;
      }
      wp_reset_postdata();
      ?>
    </div>
    <div class="dn-city-more-wrap">
      <a class="dn-city-more" href="<?php echo esc_url($href); ?>" data-goto-city="<?php echo esc_attr($city_slug); ?>">
        <span class="material-symbols-outlined" style="font-size:18px;">newspaper</span>Vedi altro
      </a>
    </div>
  </section>
  <?php
}

get_header();

// Marker SSR per la "hydration mode" SPA: quando il browser atterra su
// /citta/<slug>/ o /category/<slug>/ il SSR è già a schermo. Il SPA legge
// l'attributo data-ssr-archive sul wrapper .dn-screen e setta state senza
// chiamare render() — il SSR resta visibile fino alla prima interazione.
$ssr_archive_attr = '';
if ($is_city_archive)      $ssr_archive_attr = ' data-ssr-archive="city"';
elseif ($is_category_arch) $ssr_archive_attr = ' data-ssr-archive="category"';
?>
<div id="domizionews-root">

<?php if ($single_post): ?>
  <!-- ── S1: SINGLE ARTICLE ───────────────────────────────────────────── -->
  <div class="dn-app" style="padding-bottom:0;">
    <main class="dn-detail">
      <div class="dn-detail-header">
        <div class="dn-detail-header-left">
          <button class="dn-back-btn" type="button" onclick="history.back()" aria-label="Indietro">
            <span class="material-symbols-outlined" style="font-size:20px;color:#6750A4;">arrow_back</span>
          </button>
        </div>
        <a class="dn-logo" id="dn-article-logo" href="<?php echo esc_url(home_url('/')); ?>" aria-label="Domizio News — Home" style="text-decoration:none;">
          <span class="dn-logo-domizio">Domizio</span>
          <span class="dn-logo-news">news</span>
        </a>
        <button class="dn-share-btn" id="dn-share" type="button">Condividi</button>
      </div>
      <div class="dn-detail-img-wrap">
        <?php
        $hero_img = get_the_post_thumbnail_url($single_post->ID, 'large')
                 ?: (string) get_post_meta($single_post->ID, '_dnap_external_image', true);
        if ($hero_img): ?>
          <img src="<?php echo esc_url($hero_img); ?>" alt="<?php echo esc_attr(get_the_title($single_post)); ?>">
        <?php endif; ?>
      </div>
      <article class="dn-detail-body">
        <?php
          $sa_cats   = get_the_category($single_post->ID);
          $sa_cat    = !empty($sa_cats) ? $sa_cats[0] : null;
          $sa_city   = dnapp_ssr_pick_city($single_post->ID);
        ?>
        <?php if ($sa_cat || $sa_city): ?>
        <div class="dn-badges">
          <?php if ($sa_cat):  ?><span class="dn-badge-cat"><?php  echo esc_html($sa_cat->name);  ?></span><?php endif; ?>
          <?php if ($sa_city): ?><span class="dn-badge-city"><?php echo esc_html($sa_city->name); ?></span><?php endif; ?>
        </div>
        <?php endif; ?>
        <h1 class="dn-detail-title"><?php echo esc_html(get_the_title($single_post)); ?></h1>
        <div class="dn-byline">
          <div class="dn-avatar">R</div>
          <div>
            <div class="dn-byline-name">Redazione</div>
            <span class="dn-time dn-byline-date" data-timestamp="<?php echo esc_attr(get_the_date('c', $single_post)); ?>"><?php echo esc_html(get_the_date('j F Y', $single_post)); ?></span>
          </div>
        </div>
        <div class="dn-detail-content">
          <?php echo wp_kses_post(apply_filters('the_content', $single_post->post_content)); ?>
        </div>
      </article>
    </main>
    <?php dnapp_ssr_footer(); ?>
    <?php dnapp_ssr_bottom_nav(null); ?>
  </div>

<?php elseif ($page_obj): ?>
  <!-- ── S2: WORDPRESS PAGE (legal) ───────────────────────────────────── -->
  <div class="dn-app">
    <?php dnapp_ssr_top_header(); ?>
    <div class="dn-legal-page">
      <?php dnapp_ssr_detail_header(get_the_title($page_obj)); ?>
      <div class="dn-legal-content">
        <?php echo wp_kses_post(apply_filters('the_content', $page_obj->post_content)); ?>
      </div>
    </div>
    <?php dnapp_ssr_footer(); ?>
    <?php dnapp_ssr_bottom_nav(null); ?>
  </div>

<?php elseif (($is_city_archive || $is_category_arch) && $archive_term && $archive_query): ?>
  <!-- ── S3/S4: TAXONOMY ARCHIVE (city / category) ────────────────────── -->
  <?php
    $archive_total     = $archive_total_pages;
    $archive_base      = get_term_link($archive_term);
    if (is_wp_error($archive_base)) $archive_base = home_url('/');
    $archive_next_url  = $archive_base . 'page/' . ($archive_paged + 1) . '/';
    $archive_kind_attr = $is_city_archive ? 'city' : 'category';
    $archive_label     = $is_city_archive ? dnapp_ssr_city_label($archive_term->slug) : $archive_term->name;
  ?>
  <div class="dn-app">
    <?php dnapp_ssr_top_header(); ?>
    <div class="dn-screen"<?php echo $ssr_archive_attr; ?>>
      <?php dnapp_ssr_detail_header($archive_label); ?>
      <main>
        <?php if ($is_city_archive): ?>
          <?php dnapp_ssr_city_chips($archive_term->slug); ?>
        <?php else: ?>
          <?php dnapp_ssr_category_chips($archive_term->slug); ?>
        <?php endif; ?>
        <div class="dn-feed">
          <?php if ($archive_query->have_posts()): ?>
            <?php while ($archive_query->have_posts()): $archive_query->the_post(); ?>
              <?php dnapp_ssr_article_card(get_the_ID()); ?>
            <?php endwhile; wp_reset_postdata(); ?>

            <?php if ($archive_paged < $archive_total): ?>
              <?php dnapp_ssr_vedi_altro_button(
                $archive_next_url,
                $archive_kind_attr,
                $archive_term->slug,
                $archive_paged + 1
              ); ?>
            <?php endif; ?>
          <?php else: ?>
            <p class="dn-empty" style="padding:40px 16px">
              <?php echo $is_city_archive
                ? 'Nessun articolo disponibile per questa città.'
                : 'Nessun articolo disponibile per questa categoria.'; ?>
            </p>
          <?php endif; ?>
        </div>
      </main>
    </div>
    <?php dnapp_ssr_footer(); ?>
    <?php dnapp_ssr_bottom_nav($is_city_archive ? 'cities' : 'home'); ?>
  </div>

<?php else: ?>
  <!-- ── S5: HOME (mirror buildHome SPA, no slider) ───────────────────── -->
  <div class="dn-app">
    <?php dnapp_ssr_top_header(); ?>
    <div class="dn-screen">
      <main>
        <?php dnapp_ssr_category_chips(''); // home senza filtro = "Tutte" attivo ?>
        <?php
          // 5 sezioni × 3 articoli ciascuna = 5 WP_Query (no_found_rows ON).
          // Stesso ordine e contenuto di CITY_SLUGS in app.js:184-190.
          dnapp_ssr_home_city_section('mondragone');
          dnapp_ssr_home_city_section('castel-volturno');
          dnapp_ssr_home_city_section('cellole-baia-domizia');
          dnapp_ssr_home_city_section('falciano-carinola');
          dnapp_ssr_home_city_section('sessa-aurunca');
        ?>
      </main>
    </div>
    <?php dnapp_ssr_footer(); ?>
    <?php dnapp_ssr_bottom_nav('home'); ?>
  </div>

<?php endif; ?>

</div>

<?php get_footer(); ?>
