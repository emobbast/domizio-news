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
// abbiamo SSR dedicato (search / author / date / tag).
//
// is_tag() è incluso perché gli archivi tag (1490 URL in produzione)
// sono thin content: WP genera /tag/<slug>/ per ogni tag estratto da
// Claude, ma quegli archivi non hanno SSR dedicato né valore SEO
// — sono duplicati delle viste città/categoria, e Google li ha
// segnalati in massa come "rilevate ma non indicizzate". Vedi anche
// wp_sitemaps_taxonomies che li rimuove dalla sitemap.
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
        || is_date()
        || is_tag();
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

// ─── PER-PAGE SU ARCHIVI TASSONOMICI ─────────────────────────────────────────
// Allinea il `posts_per_page` della global query agli archivi (city/category)
// con quello della SSR archive query (index.php usa 20 esplicitamente).
//
// Default Reading Settings di questo sito è ~100; con quel valore città
// con <100 post hanno max_num_pages=1 sulla global query → /citta/<slug>/page/2/
// triggera handle_404() → template_redirect assorbe in home con noindex →
// Googlebot deindexizza tutti gli URL paginati. Con 20 post/pagina la global
// query e $archive_query in [index.php](index.php) producono lo stesso
// max_num_pages → "Vedi altro" punta a URL realmente esistenti.
add_action( 'pre_get_posts', function ( $query ) {
    if ( is_admin() || ! $query->is_main_query() ) {
        return;
    }
    if ( $query->is_tax( 'city' ) || $query->is_category() ) {
        $query->set( 'posts_per_page', 20 );
    }
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
    // Use filemtime() so browsers re-download after every edit
    // instead of serving a stale cached copy under a fixed version.
    $base_css_path = DNAPP_DIR . '/assets/css/base.css';
    wp_enqueue_style(
        'dnapp-base',
        DNAPP_URL . '/assets/css/base.css',
        [],
        file_exists( $base_css_path ) ? filemtime( $base_css_path ) : DNAPP_VERSION
    );

    // React bundle (compilato con Vite)
    // Se il file non esiste ancora, carichiamo lo script inline di sviluppo
    $bundle = DNAPP_DIR . '/assets/js/app.js';
    if ( file_exists( $bundle ) ) {
        wp_enqueue_script(
            'dnapp-react',
            DNAPP_URL . '/assets/js/app.js',
            [],
            filemtime( $bundle ),
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

    // REST returns ALL cities (individuals + aggregates).
    // Client-side filtering in buildCities() / buildHome() decides
    // what to show per UI surface.
    $cities_payload = [];
    if ( ! is_wp_error( $cities ) ) {
        foreach ( $cities as $c ) {
            $cities_payload[] = [ 'id' => $c->term_id, 'name' => $c->name, 'slug' => $c->slug ];
        }
    }

    return new WP_REST_Response( [
        'categories' => array_map( fn( $c ) => [ 'id' => $c->term_id, 'name' => $c->name, 'slug' => $c->slug ], $cats ),
        'cities'     => $cities_payload,
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

// ─── SITEMAP: tassonomie ─────────────────────────────────────────────────────
// Due operazioni complementari sullo stesso filtro:
//   1) Includi 'city' — WP core (5.5+) espone il sitemap XML su
//      /wp-sitemap.xml ma include solo le tassonomie registrate con
//      public=true di default. 'city' lo è, ma il provider core non
//      la vede se show_in_rest è impostato dopo il filtro iniziale
//      — questo filtro la riaggiunge in modo esplicito così Googlebot
//      trova tutti gli URL /citta/<slug>/ direttamente dalla sitemap.
//   2) Escludi 'post_tag' — 1490 archivi tag in produzione, nessuno
//      indicizzato da Google ("rilevate ma non indicizzate" in GSC),
//      thin content, duplicati delle viste città/categoria. Rimossi
//      dalla sitemap per non sprecare crawl budget; il noindex sul
//      meta robots è gestito dal filtro wp_robots più in alto.
add_filter( 'wp_sitemaps_taxonomies', function ( $taxonomies ) {
    if ( ! isset( $taxonomies['city'] ) && taxonomy_exists( 'city' ) ) {
        $taxonomies['city'] = get_taxonomy( 'city' );
    }
    unset( $taxonomies['post_tag'] );
    return $taxonomies;
} );

// ─── SITEMAP: rimuovi il provider 'users' ────────────────────────────────────
// Solo l'admin pubblica (account Redazione), quindi /wp-sitemap-users-1.xml
// contiene un singolo URL /author/<admin>/ con count tutti i post. È già
// noindex via il filtro wp_robots (is_author()), ma resta nella sitemap →
// Google lo crawla, scopre il noindex, lo registra come "rilevata ma non
// indicizzata". Rimuoverlo dalla sitemap evita lo spreco di crawl budget.
add_filter( 'wp_sitemaps_add_provider', function ( $provider, $name ) {
    if ( 'users' === $name ) {
        return false;
    }
    return $provider;
}, 10, 2 );

// ─── SITEMAP: escludi 'uncategorized' dalla sitemap delle category ───────────
// term_id=1 ('Uncategorized') ha count=0 dopo il fix manuale post-2475,
// ma il provider sitemap include comunque tutti i termini con count>=1
// e — più importante — il count può scendere a 0 mentre il termine resta
// nella query. Escludiamo esplicitamente term_id=1 per evitare che un
// futuro post miscategorized lo riporti nella sitemap.
add_filter( 'wp_sitemaps_taxonomies_query_args', function ( $args, $taxonomy ) {
    if ( 'category' === $taxonomy ) {
        $args['exclude'] = isset( $args['exclude'] )
            ? array_merge( (array) $args['exclude'], [ 1 ] )
            : [ 1 ];
    }
    return $args;
}, 10, 2 );

// ─── SITE ICON 512x512 (Google SERP brand circle) ────────────────────────────
// WordPress core wp_site_icon() emits only 32x32 and 192x192 declarations.
// Google's site icon thumbnail in SERP requires explicit sizes>=48x48
// (recommended 512x512). Without this declaration Google falls back to the
// 192x192 auto-scaled version, which looks blurry inside the SERP circle.
//
// The original 512x512 PNG is already uploaded as the WP Site Icon
// (option 'site_icon'); we only add the missing <link rel="icon"
// sizes="512x512"> declaration alongside core's output.
//
// Priority 99 ensures this runs AFTER core's wp_site_icon() (default 10),
// so the 512x512 declaration appears last in <head>.
//
// Reference: https://developers.google.com/search/docs/appearance/favicon-in-search
add_action( 'wp_head', function () {
    $site_icon_id = (int) get_option( 'site_icon' );
    if ( ! $site_icon_id ) {
        return;
    }
    $url_512 = wp_get_attachment_image_url( $site_icon_id, 'full' );
    if ( ! $url_512 ) {
        return;
    }
    echo '<link rel="icon" sizes="512x512" href="' . esc_url( $url_512 ) . '" />' . "\n";
}, 99 );

// ─── REDIRECT SPA: tutte le URL → index.php ──────────────────────────────────
// Così i link interni della React app non danno 404
add_action( 'template_redirect', function () {
    if ( is_404() && ! is_admin() ) {
        // Bail per archivi tassonomici/categoria paginati: quegli URL
        // devono mantenere il loro 404 naturale (o rendere un archivio
        // vuoto) invece di essere assorbiti silenziosamente nella home
        // con noindex. Senza questa guardia, /citta/<slug>/page/N/ con
        // paged > max_num_pages della global query veniva ricondotto a
        // home (S5) e Googlebot deindexizzava tutti i paginati.
        $is_paged_archive = is_paged() && ( is_tax( 'city' ) || is_category() );
        if ( $is_paged_archive ) {
            return;
        }

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
