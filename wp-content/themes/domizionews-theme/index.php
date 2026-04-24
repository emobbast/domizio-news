<?php
/**
 * Domizio News App Theme — index.php
 * SSR fallback per crawler + pretty permalink.
 * - is_single()      → articolo singolo
 * - is_page()        → legal page / WP page
 * - is_tax('city')   → archivio città (Notizie da X)
 * - is_category()    → archivio categoria
 * - fallback         → home (lista ultime notizie)
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
    $_archive_args['tax_query'] = [[
      'taxonomy' => 'city',
      'field'    => 'term_id',
      'terms'    => $archive_term->term_id,
    ]];
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

get_header();

// Home latest (usato solo nel ramo home)
$latest = (!$single_post && !$page_obj && !$is_city_archive && !$is_category_arch)
  ? new WP_Query(['post_type'=>'post','post_status'=>'publish','posts_per_page'=>10,'orderby'=>'date','order'=>'DESC','ignore_sticky_posts'=>true])
  : null;

// Breadcrumb visibile su articoli singoli (Home › Categoria › Città)
$single_breadcrumb_html = '';
if ($single_post) {
  $cats   = get_the_category($single_post->ID);
  $cat    = !empty($cats) ? $cats[0] : null;
  $cities = wp_get_post_terms($single_post->ID, 'city');
  $city   = (!is_wp_error($cities) && !empty($cities)) ? $cities[0] : null;
  $parts  = ['<a href="' . esc_url(home_url('/')) . '">Home</a>'];
  if ($cat) {
    $parts[] = '<a href="' . esc_url(get_category_link($cat->term_id)) . '">' . esc_html($cat->name) . '</a>';
  }
  if ($city) {
    $city_link = get_term_link($city);
    if (!is_wp_error($city_link)) {
      $parts[] = '<a href="' . esc_url($city_link) . '">' . esc_html($city->name) . '</a>';
    }
  }
  $single_breadcrumb_html = '<nav aria-label="Breadcrumb" class="dn-breadcrumb">'
    . implode(' <span class="dn-breadcrumb-sep">›</span> ', $parts)
    . '</nav>';
}
?>

<?php
// Marker class letto dall'SPA in boot() per attivare la "hydration mode":
// quando il browser atterra su /citta/<slug>/ o /category/<slug>/, il SSR
// è già a schermo — l'SPA riconosce la classe, setta state senza chiamare
// render() e lascia il contenuto SSR visibile fino alla prima interazione.
$ssr_body_class = '';
if ($is_city_archive)      $ssr_body_class = 'dn-archive-ssr dn-archive-city';
elseif ($is_category_arch) $ssr_body_class = 'dn-archive-ssr dn-archive-category';
?>
<div id="domizionews-root">
  <main class="dn-ssr-main<?php echo $ssr_body_class ? ' ' . esc_attr($ssr_body_class) : ''; ?>">

    <?php if ($single_post): ?>
      <!-- Single article SSR for crawlers -->
      <a href="<?php echo esc_url(home_url('/')); ?>" class="dn-back-link">← Domizio News</a>
      <?php echo $single_breadcrumb_html; ?>
      <h1 class="dn-ssr-h1">
        <?php echo wp_strip_all_tags($single_post->post_title); ?>
      </h1>
      <p class="dn-ssr-date">
        <?php echo get_the_date('d/m/Y', $single_post); ?>
      </p>
      <?php
        $img = get_the_post_thumbnail_url($single_post->ID, 'large') ?: get_post_meta($single_post->ID, '_dnap_external_image', true);
        if ($img): ?>
      <img src="<?php echo esc_url($img); ?>" class="dn-ssr-hero" alt="">
      <?php endif; ?>
      <div class="dn-ssr-content">
        <?php echo wp_kses_post(apply_filters('the_content', $single_post->post_content)); ?>
      </div>

    <?php elseif ($page_obj): ?>
      <!-- WordPress Page SSR -->
      <a href="<?php echo esc_url(home_url('/')); ?>" class="dn-back-link">← Domizio News</a>
      <h1 class="dn-ssr-h1">
        <?php echo esc_html(get_the_title($page_obj)); ?>
      </h1>
      <div class="dn-ssr-content">
        <?php echo wp_kses_post(apply_filters('the_content', $page_obj->post_content)); ?>
      </div>

    <?php elseif (($is_city_archive || $is_category_arch) && $archive_term && $archive_query): ?>
      <!-- Taxonomy archive SSR (city / category) -->
      <?php
        $archive_total = $archive_total_pages;
        $archive_base  = get_term_link($archive_term);
        if (is_wp_error($archive_base)) $archive_base = home_url('/');
        $archive_h1    = $is_city_archive
          ? 'Notizie da ' . esc_html($archive_term->name)
          : esc_html($archive_term->name);
        $archive_lvl2_name = $is_city_archive ? 'Città' : 'Categorie';
        $archive_lvl2_url  = $is_city_archive ? home_url('/citta/') : home_url('/category/');
        $archive_next_url  = $archive_base . 'page/' . ($archive_paged + 1) . '/';
        $archive_kind_attr = $is_city_archive ? 'city' : 'category';
      ?>
      <a href="<?php echo esc_url(home_url('/')); ?>" class="dn-back-link">← Domizio News</a>
      <nav aria-label="Breadcrumb" class="dn-breadcrumb">
        <a href="<?php echo esc_url(home_url('/')); ?>">Home</a>
        <span class="dn-breadcrumb-sep"> › </span>
        <a href="<?php echo esc_url($archive_lvl2_url); ?>"><?php echo esc_html($archive_lvl2_name); ?></a>
        <span class="dn-breadcrumb-sep"> › </span>
        <span><?php echo esc_html($archive_term->name); ?></span>
      </nav>
      <h1 class="dn-ssr-h1"><?php echo $archive_h1; ?></h1>
      <?php if ($archive_paged > 1): ?>
      <p class="dn-archive-page-label">Pagina <?php echo (int) $archive_paged; ?></p>
      <?php endif; ?>

      <?php if ($archive_query->have_posts()): ?>
        <ul class="dn-archive-list">
          <?php while ($archive_query->have_posts()): $archive_query->the_post(); ?>
          <li class="dn-archive-item">
            <a href="<?php the_permalink(); ?>" class="dn-archive-item-link">
              <h2><?php the_title(); ?></h2>
              <p><?php echo wp_trim_words(get_the_excerpt(), 28); ?></p>
              <span class="dn-archive-item-date"><?php echo get_the_date('d/m/Y'); ?></span>
            </a>
          </li>
          <?php endwhile; wp_reset_postdata(); ?>
        </ul>

        <?php if ($archive_paged < $archive_total): ?>
        <a href="<?php echo esc_url($archive_next_url); ?>"
           class="dn-load-more dn-btn-primary"
           data-next-page="<?php echo (int) ($archive_paged + 1); ?>"
           data-archive-type="<?php echo esc_attr($archive_kind_attr); ?>"
           data-archive-slug="<?php echo esc_attr($archive_term->slug); ?>">
          Vedi altro
        </a>
        <?php endif; ?>
      <?php else: ?>
        <p class="dn-archive-empty">
          <?php echo $is_city_archive
            ? 'Nessun articolo disponibile per questa città.'
            : 'Nessun articolo disponibile per questa categoria.'; ?>
        </p>
      <?php endif; ?>

    <?php else: ?>
      <!-- Home SSR: latest posts list -->
      <h1 class="dn-ssr-h1">
        Domizio News — Notizie dal Litorale Domizio
      </h1>
      <p class="dn-ssr-lead">
        Notizie locali da Mondragone, Castel Volturno, Baia Domizia,
        Cellole, Falciano del Massico, Carinola e Sessa Aurunca.
      </p>
      <ul class="dn-archive-list">
        <?php while ($latest && $latest->have_posts()): $latest->the_post(); ?>
        <li class="dn-archive-item">
          <a href="<?php the_permalink(); ?>" class="dn-archive-item-link">
            <h2><?php the_title(); ?></h2>
            <p><?php echo wp_trim_words(get_the_excerpt(), 20); ?></p>
            <span class="dn-archive-item-date"><?php echo get_the_date('d/m/Y'); ?></span>
          </a>
        </li>
        <?php endwhile; if ($latest) wp_reset_postdata(); ?>
      </ul>
    <?php endif; ?>

    <footer class="dn-ssr-footer">
      <nav class="dn-ssr-footer-nav">
        <a href="/chi-siamo/">Chi Siamo</a>
        <a href="/contatti/">Contatti</a>
        <a href="/privacy-policy/">Privacy Policy</a>
        <a href="/cookie-policy/">Cookie Policy</a>
        <a href="/note-legali/">Note Legali</a>
        <a href="/disclaimer/">Disclaimer</a>
      </nav>
      <p class="dn-ssr-footer-copy">© <?php echo esc_html(date('Y')); ?> Domizio News</p>
    </footer>
  </main>
</div>

<?php get_footer(); ?>
