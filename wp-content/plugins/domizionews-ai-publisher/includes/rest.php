<?php
if (!defined('ABSPATH')) exit;

/* ============================================================
   STICKY NEWS PER CITTÀ
   Un post per città: prima il più recente con tag VIP nel
   titolo/contenuto, poi qualsiasi post recente (fallback).
   Restituisce array keyed by city-slug:
     [ 'mondragone' => ['post' => WP_Post, 'is_vip' => bool], … ]
   ============================================================ */
function dnap_get_sticky_per_city(): array {
    $city_slugs = [
        'mondragone',
        'castel-volturno',
        'baia-domizia',
        'cellole',
        'falciano-del-massico',
        'carinola',
        'sessa-aurunca',
    ];

    $vip_tags = get_option('dnap_vip_tags', ['zannini', 'giovanni zannini']);
    $result   = [];

    foreach ($city_slugs as $slug) {
        $posts = get_posts([
            'post_type'      => 'post',
            'post_status'    => 'publish',
            'posts_per_page' => 20,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'tax_query'      => [[
                'taxonomy' => 'city',
                'field'    => 'slug',
                'terms'    => $slug,
            ]],
        ]);

        if (empty($posts)) continue;

        // Priorità 1: post più recente con almeno un tag VIP nel titolo o contenuto
        $vip_post = null;
        if (!empty($vip_tags)) {
            foreach ($posts as $post) {
                $haystack = mb_strtolower($post->post_title . ' ' . $post->post_content);
                foreach ($vip_tags as $tag) {
                    if ($tag !== '' && strpos($haystack, mb_strtolower($tag)) !== false) {
                        $vip_post = $post;
                        break 2;
                    }
                }
            }
        }

        if ($vip_post) {
            $result[$slug] = ['post' => $vip_post, 'is_vip' => true];
        } else {
            // Priorità 2: post più recente (fallback)
            $result[$slug] = ['post' => $posts[0], 'is_vip' => false];
        }
    }

    return $result;
}

/* ============================================================
   REST ENDPOINT
   GET /wp-json/domizio/v1/posts?city=SLUG&per_page=N&page=N
   Post filtrati per taxonomy 'city'. Stesso formato di
   /wp-json/dnapp/v1/feed, ma nel namespace domizio/v1.
   ============================================================ */
add_action('rest_api_init', 'dnap_register_posts_endpoint');
function dnap_register_posts_endpoint(): void {
    register_rest_route('domizio/v1', '/posts', [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => 'dnap_rest_posts_handler',
        'permission_callback' => '__return_true',
        'args'                => [
            'city'     => ['default' => '', 'sanitize_callback' => 'sanitize_text_field'],
            'category' => ['default' => '', 'sanitize_callback' => 'sanitize_text_field'],
            'per_page' => ['default' => 10, 'sanitize_callback' => 'absint'],
            'page'     => ['default' => 1,  'sanitize_callback' => 'absint'],
        ],
    ]);
}

function dnap_rest_posts_handler(WP_REST_Request $request): WP_REST_Response {
    $args = [
        'post_type'      => 'post',
        'post_status'    => 'publish',
        'posts_per_page' => min((int) $request['per_page'], 50),
        'paged'          => (int) $request['page'],
        'orderby'        => 'date',
        'order'          => 'DESC',
    ];

    $tax_query    = [];
    $city_slug    = sanitize_text_field($request['city']);
    $cat_slug     = sanitize_text_field($request['category']);

    if ($city_slug) {
        $tax_query[] = [
            'taxonomy' => 'city',
            'field'    => 'slug',
            'terms'    => $city_slug,
        ];
    }
    if ($cat_slug) {
        $tax_query[] = [
            'taxonomy' => 'category',
            'field'    => 'slug',
            'terms'    => $cat_slug,
        ];
    }
    if (!empty($tax_query)) {
        $args['tax_query'] = array_merge(['relation' => 'AND'], $tax_query);
    }

    $query = new WP_Query($args);
    $posts = [];

    foreach ($query->posts as $p) {
        $thumb_id  = get_post_thumbnail_id($p->ID);
        $thumb_url = $thumb_id ? wp_get_attachment_image_url($thumb_id, 'domizio-card') : '';
        if (!$thumb_url) {
            $thumb_url = (string) get_post_meta($p->ID, '_dnap_external_image', true);
        }

        $cats   = wp_get_post_categories($p->ID, ['fields' => 'all']);
        $cities = wp_get_post_terms($p->ID, 'city');

        // Tempo fa
        $diff  = time() - (int) get_post_time('U', false, $p);
        $mins  = (int) floor($diff / 60);
        if ($mins < 2)        $time_ago = 'Ora';
        elseif ($mins < 60)   $time_ago = $mins . ' min fa';
        elseif ($mins < 1440) { $h = (int) floor($mins / 60); $time_ago = $h === 1 ? '1 ora fa' : $h . ' ore fa'; }
        else                  { $d = (int) floor($mins / 1440); $time_ago = $d === 1 ? '1 giorno fa' : $d . ' giorni fa'; }

        $cats_arr   = array_map(fn($c) => ['id' => $c->term_id, 'name' => $c->name, 'slug' => $c->slug], $cats);
        $cities_arr = !is_wp_error($cities) ? array_map(fn($c) => ['id' => $c->term_id, 'name' => $c->name, 'slug' => $c->slug], $cities) : [];

        $posts[] = [
            'id'      => $p->ID,
            'slug'    => $p->post_name,
            'date'    => $p->post_date,
            'title'   => wp_strip_all_tags($p->post_title),
            'excerpt' => wp_trim_words(wp_strip_all_tags($p->post_excerpt ?: $p->post_content), 28),
            'content' => wp_kses_post($p->post_content),
            'image'   => $thumb_url,
            'permalink'        => get_permalink($p->ID),
            'time_ago'         => $time_ago,
            'category'         => !empty($cats_arr)   ? $cats_arr[0]['name']   : '',
            'category_slug'    => !empty($cats_arr)   ? $cats_arr[0]['slug']   : '',
            'city'             => !empty($cities_arr) ? $cities_arr[0]['name'] : '',
            'city_slug'        => !empty($cities_arr) ? $cities_arr[0]['slug'] : '',
            'meta_description' => get_post_meta($p->ID, '_meta_description', true),
            'source_url'       => get_post_meta($p->ID, '_source_url', true),
            'permalink'  => 'https://domizionews.it/?post=' . $p->ID,
            'unsplash_credit'  => get_post_meta($p->ID, '_dnap_unsplash_credit', true) ?: '',
            'categories'       => $cats_arr,
            'cities'           => $cities_arr,
        ];
    }

    return new WP_REST_Response([
        'posts'       => $posts,
        'total'       => (int) $query->found_posts,
        'total_pages' => (int) $query->max_num_pages,
    ], 200);
}

/* ============================================================
   REST ENDPOINT
   GET /wp-json/domizio/v1/scopri?categoria=SLUG&city=SLUG
   Risultati misti per la sezione Scopri:
   - type=attivita  (CPT 'attivita', se registrato)
   - type=articolo  (post normali filtrati per categoria)
   ============================================================ */
add_action('rest_api_init', 'dnap_register_scopri_endpoint');
function dnap_register_scopri_endpoint(): void {
    register_rest_route('domizio/v1', '/scopri', [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => 'dnap_rest_scopri_handler',
        'permission_callback' => '__return_true',
        'args'                => [
            'categoria' => ['default' => '', 'sanitize_callback' => 'sanitize_text_field'],
            'city'      => ['default' => '', 'sanitize_callback' => 'sanitize_text_field'],
            'per_page'  => ['default' => 20, 'sanitize_callback' => 'absint'],
        ],
    ]);
}

function dnap_rest_scopri_handler(WP_REST_Request $request): WP_REST_Response {
    $categoria = sanitize_text_field($request['categoria']);
    $city_slug = sanitize_text_field($request['city']);
    $per_page  = min((int) $request['per_page'], 50);

    // Unica query: solo CPT 'scopri' — mai post_type 'post'
    $args = [
        'post_type'      => 'scopri',
        'post_status'    => 'publish',
        'posts_per_page' => $per_page,
        'orderby'        => 'date',
        'order'          => 'DESC',
    ];

    $tax_query = [];

    if ($categoria) {
        $tax_query[] = [
            'taxonomy' => 'scopri_categoria',
            'field'    => 'slug',
            'terms'    => $categoria,
        ];
    }

    if ($city_slug && $city_slug !== 'tutte') {
        $tax_query[] = [
            'taxonomy' => 'city',
            'field'    => 'slug',
            'terms'    => $city_slug,
        ];
    }

    if (!empty($tax_query)) {
        $args['tax_query'] = count($tax_query) > 1
            ? array_merge(['relation' => 'AND'], $tax_query)
            : $tax_query;
    }

    $query   = new WP_Query($args);
    $results = [];

    foreach ($query->posts as $p) {
        $thumb_id  = get_post_thumbnail_id($p->ID);
        $thumb_url = $thumb_id ? wp_get_attachment_image_url($thumb_id, 'domizio-thumb') : '';
        if (!$thumb_url) {
            $thumb_url = (string) get_post_meta($p->ID, '_dnap_external_image', true);
        }
        $cities     = wp_get_post_terms($p->ID, 'city');
        $categorie  = wp_get_post_terms($p->ID, 'scopri_categoria');

        $cities_arr    = !is_wp_error($cities)    ? array_map(fn($t) => ['id' => $t->term_id, 'name' => $t->name, 'slug' => $t->slug], $cities)    : [];
        $categorie_arr = !is_wp_error($categorie) ? array_map(fn($t) => ['id' => $t->term_id, 'name' => $t->name, 'slug' => $t->slug], $categorie) : [];

        $results[] = [
            'type'        => 'scopri',
            'id'          => $p->ID,
            'slug'        => $p->post_name,
            'title'       => wp_strip_all_tags($p->post_title),
            'excerpt'     => wp_trim_words(wp_strip_all_tags($p->post_excerpt ?: $p->post_content), 28),
            'image'       => $thumb_url,
            'permalink'   => get_permalink($p->ID),
            'city'        => !empty($cities_arr)    ? $cities_arr[0]['name']    : '',
            'city_slug'   => !empty($cities_arr)    ? $cities_arr[0]['slug']    : '',
            'categoria'   => !empty($categorie_arr) ? $categorie_arr[0]['name'] : '',
            'cat_slug'    => !empty($categorie_arr) ? $categorie_arr[0]['slug'] : '',
            'address'     => get_post_meta($p->ID, '_address',     true),
            'phone'       => get_post_meta($p->ID, '_phone',       true),
            'whatsapp'    => get_post_meta($p->ID, '_whatsapp',    true),
            'website'     => get_post_meta($p->ID, '_website',     true),
            'price_range' => get_post_meta($p->ID, '_price_range', true),
            'categories'  => $categorie_arr,
            'cities'      => $cities_arr,
        ];
    }

    return new WP_REST_Response([
        'results'     => $results,
        'total'       => (int) $query->found_posts,
        'total_pages' => (int) $query->max_num_pages,
    ], 200);
}

/* ============================================================
   REST ENDPOINT
   GET /wp-json/domizio/v1/sticky-news
   Pubblico — nessuna autenticazione richiesta.
   ============================================================ */
add_action('rest_api_init', 'dnap_register_sticky_news_endpoint');
function dnap_register_sticky_news_endpoint(): void {
    register_rest_route('domizio/v1', '/sticky-news', [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => 'dnap_rest_sticky_news_handler',
        'permission_callback' => '__return_true',
    ]);
}

function dnap_rest_sticky_news_handler(WP_REST_Request $request): WP_REST_Response {
    $city_names = [
        'mondragone'           => 'Mondragone',
        'castel-volturno'      => 'Castel Volturno',
        'baia-domizia'         => 'Baia Domizia',
        'cellole'              => 'Cellole',
        'falciano-del-massico' => 'Falciano del Massico',
        'carinola'             => 'Carinola',
        'sessa-aurunca'        => 'Sessa Aurunca',
    ];

    $sticky_data = dnap_get_sticky_per_city();
    $result      = [];

    foreach ($city_names as $slug => $name) {
        if (!isset($sticky_data[$slug])) continue;

        $post   = $sticky_data[$slug]['post'];
        $is_vip = $sticky_data[$slug]['is_vip'];

        // Categoria principale
        $cats     = get_the_category($post->ID);
        $category = !empty($cats) ? $cats[0]->name : '';

        // Immagine in evidenza: thumbnail → meta _dnap_external_image
        $image    = '';
        $thumb_id = get_post_thumbnail_id($post->ID);
        if ($thumb_id) {
            $img_src = wp_get_attachment_image_src($thumb_id, 'domizio-card');
            $image   = $img_src ? $img_src[0] : '';
        }
        if (!$image) {
            $image = (string) get_post_meta($post->ID, '_dnap_external_image', true);
        }

        // Excerpt
        $excerpt = trim($post->post_excerpt);
        if (!$excerpt) {
            $excerpt = wp_trim_words(wp_strip_all_tags($post->post_content), 20, '…');
        }

        // Tempo fa
        $diff = time() - (int) get_post_time('U', false, $post);
        $mins = (int) floor($diff / 60);
        if ($mins < 2) {
            $time_ago = 'Ora';
        } elseif ($mins < 60) {
            $time_ago = $mins . ' min fa';
        } elseif ($mins < 1440) {
            $hrs      = (int) floor($mins / 60);
            $time_ago = $hrs === 1 ? '1 ora fa' : $hrs . ' ore fa';
        } else {
            $days     = (int) floor($mins / 1440);
            $time_ago = $days === 1 ? '1 giorno fa' : $days . ' giorni fa';
        }

        $result[] = [
            'city'      => $name,
            'city_slug' => $slug,
            'post_id'   => $post->ID,
            'title'     => get_the_title($post),
            'excerpt'   => sanitize_text_field($excerpt),
            'image'          => esc_url_raw($image),
            'category'       => $category,
            'time_ago'       => $time_ago,
            'permalink'      => get_permalink($post->ID),
            'is_vip'         => $is_vip,
            'unsplash_credit' => get_post_meta($post->ID, '_dnap_unsplash_credit', true) ?: '',
        ];
    }

    return new WP_REST_Response($result, 200);
}
