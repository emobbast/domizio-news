<?php
/**
 * Domizio News App Theme — index.php
 * Renders a static SSR fallback for crawlers (AdSense, Google bot).
 * The SPA takes over on DOMContentLoaded as normal.
 */
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
