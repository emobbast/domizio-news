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
  add_action('wp_head', function() use ($archive_term, $archive_paged, $is_city_archive) {
    global $wp_query;
    $total = isset($wp_query->max_num_pages) ? (int) $wp_query->max_num_pages : 1;
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
  $parts  = ['<a href="' . esc_url(home_url('/')) . '" style="color:#1A73E8;text-decoration:none;">Home</a>'];
  if ($cat) {
    $parts[] = '<a href="' . esc_url(get_category_link($cat->term_id)) . '" style="color:#1A73E8;text-decoration:none;">' . esc_html($cat->name) . '</a>';
  }
  if ($city) {
    $city_link = get_term_link($city);
    if (!is_wp_error($city_link)) {
      $parts[] = '<a href="' . esc_url($city_link) . '" style="color:#1A73E8;text-decoration:none;">' . esc_html($city->name) . '</a>';
    }
  }
  $single_breadcrumb_html = '<nav aria-label="Breadcrumb" style="font-size:13px;color:#5F6368;margin:0 0 12px;line-height:1.5;">'
    . implode(' <span style="color:#9AA0A6;">›</span> ', $parts)
    . '</nav>';
}
?>

<div id="domizionews-root">
  <main style="font-family:sans-serif;max-width:430px;margin:0 auto;padding:16px;">

    <?php if ($single_post): ?>
      <!-- Single article SSR for crawlers -->
      <a href="<?php echo esc_url(home_url('/')); ?>" style="color:#1A73E8;font-size:14px;display:block;margin-bottom:12px;">← Domizio News</a>
      <?php echo $single_breadcrumb_html; ?>
      <h1 style="font-size:22px;font-weight:700;margin:0 0 12px;color:#202124;">
        <?php echo wp_strip_all_tags($single_post->post_title); ?>
      </h1>
      <p style="font-size:12px;color:#9AA0A6;margin-bottom:16px;">
        <?php echo get_the_date('d/m/Y', $single_post); ?>
      </p>
      <?php
        $img = get_the_post_thumbnail_url($single_post->ID, 'large') ?: get_post_meta($single_post->ID, '_dnap_external_image', true);
        if ($img): ?>
      <img src="<?php echo esc_url($img); ?>" style="width:100%;border-radius:8px;margin-bottom:16px;" alt="">
      <?php endif; ?>
      <div style="font-size:16px;line-height:1.7;color:#202124;">
        <?php echo wp_kses_post(apply_filters('the_content', $single_post->post_content)); ?>
      </div>

    <?php elseif ($page_obj): ?>
      <!-- WordPress Page SSR -->
      <a href="<?php echo esc_url(home_url('/')); ?>" style="color:#1A73E8;font-size:14px;display:block;margin-bottom:16px;">← Domizio News</a>
      <h1 style="font-size:22px;font-weight:700;margin:0 0 12px;color:#202124;">
        <?php echo esc_html(get_the_title($page_obj)); ?>
      </h1>
      <div style="font-size:16px;line-height:1.7;color:#202124;">
        <?php echo wp_kses_post(apply_filters('the_content', $page_obj->post_content)); ?>
      </div>

    <?php elseif (($is_city_archive || $is_category_arch) && $archive_term): ?>
      <!-- Taxonomy archive SSR (city / category) -->
      <?php
        global $wp_query;
        $archive_total = isset($wp_query->max_num_pages) ? (int) $wp_query->max_num_pages : 1;
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
      <a href="<?php echo esc_url(home_url('/')); ?>" style="color:#1A73E8;font-size:14px;display:block;margin-bottom:12px;">← Domizio News</a>
      <nav aria-label="Breadcrumb" style="font-size:13px;color:#5F6368;margin:0 0 12px;line-height:1.5;">
        <a href="<?php echo esc_url(home_url('/')); ?>" style="color:#1A73E8;text-decoration:none;">Home</a>
        <span style="color:#9AA0A6;"> › </span>
        <a href="<?php echo esc_url($archive_lvl2_url); ?>" style="color:#1A73E8;text-decoration:none;"><?php echo esc_html($archive_lvl2_name); ?></a>
        <span style="color:#9AA0A6;"> › </span>
        <span><?php echo esc_html($archive_term->name); ?></span>
      </nav>
      <h1 style="font-size:24px;font-weight:700;margin:0 0 16px;color:#202124;"><?php echo $archive_h1; ?></h1>
      <?php if ($archive_paged > 1): ?>
      <p style="font-size:13px;color:#5F6368;margin:0 0 16px;">Pagina <?php echo (int) $archive_paged; ?></p>
      <?php endif; ?>

      <?php if (have_posts()): ?>
        <ul style="list-style:none;padding:0;margin:0;">
          <?php while (have_posts()): the_post(); ?>
          <li style="border-bottom:1px solid #E8EAED;padding:16px 0;">
            <a href="<?php the_permalink(); ?>" style="text-decoration:none;color:#202124;">
              <h2 style="font-size:16px;font-weight:500;margin:0 0 8px;"><?php the_title(); ?></h2>
              <p style="font-size:13px;color:#5F6368;margin:0;"><?php echo wp_trim_words(get_the_excerpt(), 28); ?></p>
              <span style="font-size:12px;color:#9AA0A6;display:block;margin-top:6px;"><?php echo get_the_date('d/m/Y'); ?></span>
            </a>
          </li>
          <?php endwhile; wp_reset_postdata(); ?>
        </ul>

        <?php if ($archive_paged < $archive_total): ?>
        <a href="<?php echo esc_url($archive_next_url); ?>"
           class="dn-load-more"
           data-next-page="<?php echo (int) ($archive_paged + 1); ?>"
           data-archive-type="<?php echo esc_attr($archive_kind_attr); ?>"
           data-archive-slug="<?php echo esc_attr($archive_term->slug); ?>"
           style="display:block;text-align:center;padding:12px 24px;background:#6750A4;color:#fff;text-decoration:none;border-radius:8px;margin:24px 0;font-weight:500;">
          Vedi altri articoli
        </a>
        <?php endif; ?>
      <?php else: ?>
        <p style="color:#5F6368;padding:24px 0;">
          <?php echo $is_city_archive
            ? 'Nessun articolo disponibile per questa città.'
            : 'Nessun articolo disponibile per questa categoria.'; ?>
        </p>
      <?php endif; ?>

    <?php else: ?>
      <!-- Home SSR: latest posts list -->
      <h1 style="font-size:22px;font-weight:700;margin-bottom:16px;">
        Domizio News — Notizie dal Litorale Domizio
      </h1>
      <p style="font-size:14px;color:#5F6368;margin-bottom:24px;">
        Notizie locali da Mondragone, Castel Volturno, Baia Domizia,
        Cellole, Falciano del Massico, Carinola e Sessa Aurunca.
      </p>
      <ul style="list-style:none;padding:0;margin:0;">
        <?php while ($latest && $latest->have_posts()): $latest->the_post(); ?>
        <li style="border-bottom:1px solid #E0E0E0;padding:16px 0;">
          <a href="<?php the_permalink(); ?>" style="text-decoration:none;color:#202124;">
            <h2 style="font-size:16px;font-weight:500;margin:0 0 8px;"><?php the_title(); ?></h2>
            <p style="font-size:13px;color:#5F6368;margin:0;"><?php echo wp_trim_words(get_the_excerpt(), 20); ?></p>
            <span style="font-size:12px;color:#9AA0A6;display:block;margin-top:6px;"><?php echo get_the_date('d/m/Y'); ?></span>
          </a>
        </li>
        <?php endwhile; if ($latest) wp_reset_postdata(); ?>
      </ul>
    <?php endif; ?>

    <footer style="margin-top:32px;padding-top:16px;border-top:1px solid #E8EAED;">
      <nav style="display:flex;flex-wrap:wrap;gap:8px 16px;font-size:13px;">
        <a href="/chi-siamo/" style="color:#1A73E8;">Chi Siamo</a>
        <a href="/contatti/" style="color:#1A73E8;">Contatti</a>
        <a href="/privacy-policy/" style="color:#1A73E8;">Privacy Policy</a>
        <a href="/cookie-policy/" style="color:#1A73E8;">Cookie Policy</a>
        <a href="/note-legali/" style="color:#1A73E8;">Note Legali</a>
        <a href="/disclaimer/" style="color:#1A73E8;">Disclaimer</a>
      </nav>
      <p style="margin:12px 0 0;color:#9AA0A6;font-size:12px;">© <?php echo esc_html(date('Y')); ?> Domizio News</p>
    </footer>
  </main>
</div>

<?php get_footer(); ?>
