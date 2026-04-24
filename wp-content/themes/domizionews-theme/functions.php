<?php
/**
 * Domizio News App Theme — functions.php
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ─── COSTANTI ────────────────────────────────────────────────────────────────
define( 'DNAPP_VERSION', '1.0.0' );
define( 'DNAPP_DIR', get_template_directory() );
define( 'DNAPP_URL', get_template_directory_uri() );

// ─── RIMUOVI TUTTO IL SUPERFLUO ──────────────────────────────────────────────
add_action( 'init', function () {
    remove_action( 'wp_head', 'wp_generator' );
    remove_action( 'wp_head', 'wlwmanifest_link' );
    remove_action( 'wp_head', 'rsd_link' );
    remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
    remove_action( 'wp_print_styles', 'print_emoji_styles' );
} );

// ─── ROBOTS META UNIFICATO ───────────────────────────────────────────────────
// Merge our index/noindex logic into WP core's wp_robots output so a single
// <meta name="robots"> tag is emitted (max-image-preview:large preserved).
// The manual echo previously in index.php has been removed.
//
// noindex viene emesso SOLO per il force-home 404 fallback (SPA URL
// risolte client-side che atterrano su index.php via template_redirect)
// e per le viste che WP considera archivio di secondo livello ma non
// abbiamo SSR dedicato (search / author / date).
//
// is_paged() è stato RIMOSSO deliberatamente: Google dal 2019 tratta
// ogni URL paginato come contenuto indipendente, e mettere noindex su
// /citta/<slug>/page/N/ nascondeva ~80% degli articoli dell'archivio
// all'indicizzazione. Con il SSR archive ora in place (rel=prev/next +
// canonical paged-aware) le pagine N>=2 sono legittime e indicizzabili.
add_filter( 'wp_robots', function ( $robots ) {
    $is_non_canonical = ! empty( $GLOBALS['dnapp_was_404'] )
        || is_search()
        || is_author()
        || is_date();
    if ( $is_non_canonical ) {
        $robots['noindex'] = true;
        unset( $robots['index'] );
    } else {
        unset( $robots['noindex'] );
        $robots['index'] = true;
    }
    $robots['follow'] = true;
    return $robots;
} );

// ─── SUPPORTO TEMA ───────────────────────────────────────────────────────────
add_action( 'after_setup_theme', function () {
    add_theme_support( 'title-tag' );
    add_theme_support( 'post-thumbnails' );
    add_image_size( 'domizio-card',  600, 338, true ); // 16:9 hero/slider
    add_image_size( 'domizio-thumb',  80,  80, true ); // list card thumbnail
} );

// ─── ABILITA REST API TASSONOMIA CITY ────────────────────────────────────────
// Il plugin DNAP registra la taxonomy 'city' ma potrebbe non averla
// esposta via REST. Questo la forza.
add_action( 'init', function () {
    if ( taxonomy_exists( 'city' ) ) {
        global $wp_taxonomies;
        $wp_taxonomies['city']->show_in_rest = true;
        $wp_taxonomies['city']->rest_base    = 'city';
    }
}, 20 );

// ─── ENQUEUE REACT APP ───────────────────────────────────────────────────────
add_action( 'wp_enqueue_scripts', function () {

    // CSS reset + base
    wp_enqueue_style(
        'dnapp-base',
        DNAPP_URL . '/assets/css/base.css',
        [],
        DNAPP_VERSION
    );

    // React bundle (compilato con Vite)
    // Se il file non esiste ancora, carichiamo lo script inline di sviluppo
    $bundle = DNAPP_DIR . '/assets/js/app.js';
    if ( file_exists( $bundle ) ) {
        wp_enqueue_script(
            'dnapp-react',
            DNAPP_URL . '/assets/js/app.js',
            [],
            DNAPP_VERSION,
            true
        );
    }

    // Passa dati WordPress alla React app via window.DNAPP_CONFIG
    wp_add_inline_script(
        'dnapp-react',
        'window.DNAPP_CONFIG = ' . wp_json_encode( [
            'wpBase'    => esc_url( rest_url( 'wp/v2' ) ),
            'siteUrl'   => esc_url( home_url() ),
            'siteName'  => get_bloginfo( 'name' ),
            'nonce'     => wp_create_nonce( 'wp_rest' ),
            'hasCities' => taxonomy_exists( 'city' ),
        ] ) . ';',
        'before'
    );

} );

// ─── CORS REST API (utile in sviluppo locale) ────────────────────────────────
// WP core's rest_send_cors_headers() reflects the request Origin back and sends
// Access-Control-Allow-Credentials: true on every REST response. That's unsafe
// for a same-origin SPA on production. Strip core's CORS handler on production
// hosts; keep it on local dev hosts so DevTools / cross-origin testing works.
// Priority 15 runs after core registers rest_send_cors_headers at priority 10.
add_action( 'rest_api_init', function () {
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $is_local = (
        str_ends_with( $host, '.local' ) ||
        str_ends_with( $host, '.test' ) ||
        $host === 'localhost' ||
        str_starts_with( $host, '127.' ) ||
        str_starts_with( $host, '192.168.' )
    );

    if ( ! $is_local ) {
        remove_filter( 'rest_pre_serve_request', 'rest_send_cors_headers' );
    }
}, 15 );

// ─── ENDPOINT AGGIUNTIVO: /wp-json/dnapp/v1/feed ─────────────────────────────
// Restituisce post + città + categorie in una singola chiamata (performance)
add_action( 'rest_api_init', function () {

    register_rest_route( 'dnapp/v1', '/feed', [
        'methods'             => 'GET',
        'callback'            => 'dnapp_rest_feed',
        'permission_callback' => '__return_true',
        'args'                => [
            'per_page' => [ 'default' => 20, 'sanitize_callback' => 'absint' ],
            'page'     => [ 'default' => 1,  'sanitize_callback' => 'absint' ],
            'city'     => [ 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ],
            'category' => [ 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ],
            'search'   => [ 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ],
            'slug' => [ 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ],
        ],
    ] );

    register_rest_route( 'dnapp/v1', '/config', [
        'methods'             => 'GET',
        'callback'            => 'dnapp_rest_config',
        'permission_callback' => '__return_true',
    ] );

} );

function dnapp_rest_feed( WP_REST_Request $req ): WP_REST_Response {

    $args = [
        'post_type'      => 'post',
        'post_status'    => 'publish',
        'posts_per_page' => $req['per_page'],
        'paged'          => $req['page'],
        'orderby'        => 'date',
        'order'          => 'DESC',
        'ignore_sticky_posts' => true,
    ];

    if ( $req['search'] ) {
        $args['s'] = $req['search'];
    }

    if ( $req['slug'] ) {
      $args['name'] = $req['slug'];
    }

    if ( $req['city'] ) {
        $args['tax_query'][] = [
            'taxonomy' => 'city',
            'field'    => 'slug',
            'terms'    => explode( ',', $req['city'] ),
        ];
    }

    if ( $req['category'] ) {
        $args['category_name'] = $req['category'];
    }

    $query = new WP_Query( $args );
    $posts = [];

    foreach ( $query->posts as $p ) {
        $thumb     = get_post_thumbnail_id( $p->ID );
        $thumb_url = $thumb ? wp_get_attachment_image_url( $thumb, 'domizio-card' ) : '';
        if ( ! $thumb_url ) {
            $thumb_url = (string) get_post_meta( $p->ID, '_dnap_external_image', true );
        }

        $cats   = wp_get_post_categories( $p->ID, [ 'fields' => 'all' ] );
        $cities = wp_get_post_terms( $p->ID, 'city' );

        $posts[] = [
            'id'      => $p->ID,
            'slug'    => $p->post_name,
            'date'    => $p->post_date,
            'title'   => wp_strip_all_tags( $p->post_title ),
            'excerpt' => wp_trim_words( wp_strip_all_tags( $p->post_excerpt ?: $p->post_content ), 28 ),
            'content' => wp_kses_post( $p->post_content ),
            'image'   => $thumb_url,
            'meta_description' => get_post_meta( $p->ID, '_meta_description', true ),
            'source_url'       => get_post_meta( $p->ID, '_source_url', true ),
            'permalink'  => get_permalink($p->ID),
            'unsplash_credit'  => get_post_meta( $p->ID, '_dnap_unsplash_credit', true ) ?: '',
            'categories' => array_map( fn( $c ) => [ 'id' => $c->term_id, 'name' => $c->name, 'slug' => $c->slug ], $cats ),
            'cities'     => ! is_wp_error( $cities ) ? array_map( fn( $c ) => [ 'id' => $c->term_id, 'name' => $c->name, 'slug' => $c->slug ], $cities ) : [],
        ];
    }

    return new WP_REST_Response( [
        'posts'       => $posts,
        'total'       => (int) $query->found_posts,
        'total_pages' => (int) $query->max_num_pages,
    ], 200 );
}

function dnapp_rest_config(): WP_REST_Response {

    // Fetch only categories actually used by standard 'post' posts.
    // get_categories() with hide_empty returns terms used by ANY post type,
    // including 'scopri' CPT, which causes scopri_categoria-equivalent terms
    // to bleed into the home chip menu. A raw JOIN ensures we only surface
    // editorial news categories.
    global $wpdb;
    $post_cat_ids = $wpdb->get_col( "
        SELECT DISTINCT tt.term_id
        FROM {$wpdb->term_taxonomy} tt
        INNER JOIN {$wpdb->term_relationships} tr ON tt.term_taxonomy_id = tr.term_taxonomy_id
        INNER JOIN {$wpdb->posts} p ON tr.object_id = p.ID
        WHERE tt.taxonomy = 'category'
          AND p.post_type  = 'post'
          AND p.post_status = 'publish'
    " );
    $cats = ! empty( $post_cat_ids )
        ? get_terms( [ 'taxonomy' => 'category', 'include' => $post_cat_ids, 'hide_empty' => true ] )
        : [];

    $cities = get_terms( [ 'taxonomy' => 'city', 'hide_empty' => false ] );

    return new WP_REST_Response( [
        'categories' => array_map( fn( $c ) => [ 'id' => $c->term_id, 'name' => $c->name, 'slug' => $c->slug ], $cats ),
        'cities'     => ! is_wp_error( $cities ) ? array_map( fn( $c ) => [ 'id' => $c->term_id, 'name' => $c->name, 'slug' => $c->slug ], $cities ) : [],
        'site_name'  => get_bloginfo( 'name' ),
    ], 200 );
}

// ─── ROBOTS.TXT ──────────────────────────────────────────────────────────────
add_filter( 'robots_txt', function ( $output ) {
    $output  = "User-agent: *\n";
    $output .= "Allow: /\n";
    $output .= "Sitemap: https://domizionews.it/wp-sitemap.xml\n";
    $output .= "Disallow: /wp-admin/\n";
    $output .= "Disallow: /wp-login.php\n";
    return $output;
}, 10, 2 );

// ─── SITEMAP: includi la tassonomia 'city' ───────────────────────────────────
// WP core (5.5+) espone il sitemap XML su /wp-sitemap.xml ma include solo
// le tassonomie registrate con public=true di default. 'city' lo è, ma il
// provider core non la vede se show_in_rest è impostato dopo il filtro
// iniziale — questo filtro la riaggiunge in modo esplicito così Googlebot
// trova tutti gli URL /citta/<slug>/ direttamente dalla sitemap.
add_filter( 'wp_sitemaps_taxonomies', function ( $taxonomies ) {
    if ( ! isset( $taxonomies['city'] ) && taxonomy_exists( 'city' ) ) {
        $taxonomies['city'] = get_taxonomy( 'city' );
    }
    return $taxonomies;
} );

// ─── REDIRECT SPA: tutte le URL → index.php ──────────────────────────────────
// Così i link interni della React app non danno 404
add_action( 'template_redirect', function () {
    if ( is_404() && ! is_admin() ) {
        // Normalize the request to a 200 "home" response so Googlebot
        // indexes SPA URLs that resolve client-side and index.php renders
        // the home SSR branch (with the correct canonical to the homepage).
        $GLOBALS['dnapp_was_404'] = true;
        status_header( 200 );
        if ( isset( $GLOBALS['wp_query'] ) ) {
            $GLOBALS['wp_query']->is_404 = false;
            $GLOBALS['wp_query']->is_home = true;
        }
        include get_template_directory() . '/index.php';
        exit;
    }
} );
