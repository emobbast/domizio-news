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
add_filter( 'rest_pre_serve_request', function ( $value ) {
    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
        header( 'Access-Control-Allow-Origin: *' );
        header( 'Access-Control-Allow-Methods: GET, POST, OPTIONS' );
        header( 'Access-Control-Allow-Headers: Authorization, Content-Type, X-WP-Nonce' );
    }
    return $value;
} );

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

// ─── REDIRECT SPA: tutte le URL → index.php ──────────────────────────────────
// Così i link interni della React app non danno 404
add_action( 'template_redirect', function () {
    if ( is_404() && ! is_admin() ) {
        include get_template_directory() . '/index.php';
        exit;
    }
} );
