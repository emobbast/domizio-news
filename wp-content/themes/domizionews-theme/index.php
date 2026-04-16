<?php
/**
 * Domizio News App Theme — index.php
 * Renders a static SSR fallback for crawlers (AdSense, Google bot).
 * The SPA takes over on DOMContentLoaded as normal.
 */

// ─── SEO: recupera dati per meta tag dinamici (prima di get_header) ───────────
$seo_q = new WP_Query([
    'post_type'      => 'post',
    'post_status'    => 'publish',
    'posts_per_page' => 1,
    'orderby'        => 'date',
    'order'          => 'DESC',
]);

$seo_title     = get_bloginfo( 'name' ) . ' — Notizie dal Litorale Domizio';
$seo_desc      = 'Notizie locali da Mondragone, Castel Volturno, Baia Domizia, Cellole, Falciano del Massico, Carinola e Sessa Aurunca.';
$seo_image     = '';
$seo_canonical = 'https://domizionews.it/';

if ( $seo_q->have_posts() ) {
    $seo_q->the_post();
    $seo_title = wp_strip_all_tags( get_the_title() ) . ' | Domizio News';
    $raw_desc  = get_the_excerpt() ?: get_the_content();
    $seo_desc  = wp_trim_words( wp_strip_all_tags( $raw_desc ), 30 );
    $seo_image = get_the_post_thumbnail_url( null, 'large' )
              ?: (string) get_post_meta( get_the_ID(), '_dnap_external_image', true );
    wp_reset_postdata();
}

$logo_url = get_theme_file_uri( 'assets/images/logo.png' );

// Override document title tag (rispetta add_theme_support('title-tag'))
add_filter( 'pre_get_document_title', fn() => $seo_title, 10 );

// ─── META TAG NEL <head> ──────────────────────────────────────────────────────
add_action( 'wp_head', function () use ( $seo_title, $seo_desc, $seo_image, $seo_canonical ) {
    ?>
    <!-- SEO Meta Tags -->
    <meta name="description" content="<?php echo esc_attr( $seo_desc ); ?>">
    <meta name="robots" content="index, follow">
    <link rel="canonical" href="<?php echo esc_url( $seo_canonical ); ?>">

    <!-- Open Graph -->
    <meta property="og:type" content="website">
    <meta property="og:title" content="<?php echo esc_attr( $seo_title ); ?>">
    <meta property="og:description" content="<?php echo esc_attr( $seo_desc ); ?>">
    <meta property="og:url" content="<?php echo esc_url( $seo_canonical ); ?>">
    <?php if ( $seo_image ) : ?>
    <meta property="og:image" content="<?php echo esc_url( $seo_image ); ?>">
    <?php endif; ?>
    <?php
}, 2 );

// ─── JSON-LD SCHEMA ───────────────────────────────────────────────────────────
add_action( 'wp_head', function () use ( $logo_url ) {
    $schema_q = new WP_Query([
        'post_type'      => 'post',
        'post_status'    => 'publish',
        'posts_per_page' => 10,
        'orderby'        => 'date',
        'order'          => 'DESC',
    ]);

    $items = [];
    $pos   = 1;
    while ( $schema_q->have_posts() ) {
        $schema_q->the_post();
        $img = get_the_post_thumbnail_url( null, 'large' )
            ?: (string) get_post_meta( get_the_ID(), '_dnap_external_image', true );
        $item_data = [
            '@type'         => 'NewsArticle',
            'headline'      => wp_strip_all_tags( get_the_title() ),
            'url'           => get_permalink(),
            'datePublished' => get_the_date( 'c' ),
            'author'        => [ '@type' => 'Organization', 'name' => 'Redazione' ],
            'publisher'     => [
                '@type' => 'NewsMediaOrganization',
                'name'  => 'Domizio News',
                'logo'  => [ '@type' => 'ImageObject', 'url' => $logo_url ],
            ],
        ];
        if ( $img ) {
            $item_data['image'] = $img;
        }
        $items[] = [
            '@type'    => 'ListItem',
            'position' => $pos++,
            'item'     => $item_data,
        ];
    }
    wp_reset_postdata();

    $schema = [
        '@context' => 'https://schema.org',
        '@graph'   => [
            [
                '@type' => 'NewsMediaOrganization',
                'name'  => 'Domizio News',
                'url'   => 'https://domizionews.it/',
                'logo'  => [ '@type' => 'ImageObject', 'url' => $logo_url ],
            ],
            [
                '@type'           => 'ItemList',
                'itemListElement' => $items,
            ],
        ],
    ];
    echo '<script type="application/ld+json">'
        . wp_json_encode( $schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
        . '</script>' . "\n";
}, 5 );

get_header();

$latest = new WP_Query([
  'post_type'      => 'post',
  'post_status'    => 'publish',
  'posts_per_page' => 10,
  'orderby'        => 'date',
  'order'          => 'DESC',
]);
?>

<div id="domizionews-root">
  <?php if ($latest->have_posts()): ?>
  <main style="font-family:sans-serif;max-width:430px;margin:0 auto;padding:16px;">
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
        <a href="<?php the_permalink(); ?>"
           style="text-decoration:none;color:#202124;">
          <h2 style="font-size:16px;font-weight:500;margin:0 0 8px;">
            <?php the_title(); ?>
          </h2>
          <p style="font-size:13px;color:#5F6368;margin:0;">
            <?php echo wp_trim_words(get_the_excerpt(), 20); ?>
          </p>
          <span style="font-size:12px;color:#9AA0A6;display:block;margin-top:6px;">
            <?php echo get_the_date('d/m/Y'); ?>
          </span>
        </a>
      </li>
      <?php endwhile; wp_reset_postdata(); ?>
    </ul>
    <nav style="margin-top:24px;font-size:13px;">
      <a href="/privacy-policy" style="color:#1A73E8;margin-right:16px;">Privacy Policy</a>
      <a href="/cookie-policy" style="color:#1A73E8;margin-right:16px;">Cookie Policy</a>
      <a href="/chi-siamo" style="color:#1A73E8;">Chi Siamo</a>
    </nav>
  </main>
  <?php endif; ?>
</div>

<?php get_footer(); ?>
