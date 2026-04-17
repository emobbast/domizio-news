<?php
/**
 * Domizio News App Theme — index.php
 * SSR fallback for crawlers + pretty permalink support.
 * - is_single() → render that specific article
 * - is_home()/is_front_page() → render latest posts list
 */

$logo_url    = get_theme_file_uri('assets/images/logo.png');
$single_post = is_single() ? get_queried_object() : null;

// ── SEO META ─────────────────────────────────────────────────────────────────
if ($single_post) {
  $seo_title     = wp_strip_all_tags($single_post->post_title) . ' | Domizio News';
  $raw_desc      = $single_post->post_excerpt ?: $single_post->post_content;
  $seo_desc      = wp_trim_words(wp_strip_all_tags($raw_desc), 30);
  $seo_image     = get_the_post_thumbnail_url($single_post->ID, 'large')
                ?: (string) get_post_meta($single_post->ID, '_dnap_external_image', true);
  $seo_canonical = get_permalink($single_post->ID);
} else {
  $seo_title     = get_bloginfo('name') . ' — Notizie dal Litorale Domizio';
  $seo_desc = get_bloginfo('description') ?: 'Notizie in tempo reale dal Litorale Domizio. Cronaca, sport, politica ed eventi da Mondragone, Castel Volturno, Baia Domizia e dintorni.';
  $seo_image     = '';
  $seo_canonical = 'https://domizionews.it/';
  // Use latest post for meta
  $seo_q = new WP_Query(['post_type'=>'post','post_status'=>'publish','posts_per_page'=>1,'orderby'=>'date','order'=>'DESC']);
  if ($seo_q->have_posts()) {
    $seo_q->the_post();
    $seo_title = wp_strip_all_tags(get_the_title()) . ' | Domizio News';
    $raw_desc  = get_the_excerpt() ?: get_the_content();
    $seo_image = get_the_post_thumbnail_url(null, 'large') ?: (string) get_post_meta(get_the_ID(), '_dnap_external_image', true);
    wp_reset_postdata();
  }
}

add_filter('pre_get_document_title', fn() => $seo_title, 10);

add_action('wp_head', function() use ($seo_title, $seo_desc, $seo_image, $seo_canonical) { ?>
  <meta name="description" content="<?php echo esc_attr($seo_desc); ?>">
  <meta name="robots" content="index, follow">
  <link rel="canonical" href="<?php echo esc_url($seo_canonical); ?>">
  <meta property="og:type" content="article">
  <meta property="og:title" content="<?php echo esc_attr($seo_title); ?>">
  <meta property="og:description" content="<?php echo esc_attr($seo_desc); ?>">
  <meta property="og:url" content="<?php echo esc_url($seo_canonical); ?>">
  <?php if ($seo_image): ?>
  <meta property="og:image" content="<?php echo esc_url($seo_image); ?>">
  <?php endif; ?>
<?php }, 2);

add_action('wp_head', function() use ($logo_url) {
  $schema_q = new WP_Query(['post_type'=>'post','post_status'=>'publish','posts_per_page'=>10,'orderby'=>'date','order'=>'DESC']);
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

get_header();

$latest = new WP_Query(['post_type'=>'post','post_status'=>'publish','posts_per_page'=>10,'orderby'=>'date','order'=>'DESC']);
?>

<div id="domizionews-root">
  <main style="font-family:sans-serif;max-width:430px;margin:0 auto;padding:16px;">

    <?php if ($single_post): ?>
      <!-- Single article SSR for crawlers -->
      <a href="https://domizionews.it/" style="color:#1A73E8;font-size:14px;display:block;margin-bottom:16px;">← Domizio News</a>
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
        <?php while ($latest->have_posts()): $latest->the_post(); ?>
        <li style="border-bottom:1px solid #E0E0E0;padding:16px 0;">
          <a href="<?php the_permalink(); ?>" style="text-decoration:none;color:#202124;">
            <h2 style="font-size:16px;font-weight:500;margin:0 0 8px;"><?php the_title(); ?></h2>
            <p style="font-size:13px;color:#5F6368;margin:0;"><?php echo wp_trim_words(get_the_excerpt(), 20); ?></p>
            <span style="font-size:12px;color:#9AA0A6;display:block;margin-top:6px;"><?php echo get_the_date('d/m/Y'); ?></span>
          </a>
        </li>
        <?php endwhile; wp_reset_postdata(); ?>
      </ul>
    <?php endif; ?>

    <nav style="margin-top:24px;font-size:13px;">
      <a href="/privacy-policy" style="color:#1A73E8;margin-right:16px;">Privacy Policy</a>
      <a href="/cookie-policy" style="color:#1A73E8;margin-right:16px;">Cookie Policy</a>
      <a href="/chi-siamo" style="color:#1A73E8;">Chi Siamo</a>
    </nav>
  </main>
</div>

<?php get_footer(); ?>
