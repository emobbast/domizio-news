<?php
/**
 * Scopri CPT & Taxonomy
 *
 * Registra il Custom Post Type 'scopri' (attività locali)
 * e la tassonomia 'scopri_categoria' nell'hook 'init'.
 * I termini di default vengono inseriti sempre in 'init',
 * dopo che la tassonomia è già registrata, per garantire
 * che wp_insert_term() funzioni correttamente a ogni caricamento.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'init', 'dnap_register_scopri_cpt', 5 );
add_action( 'init', 'dnap_register_scopri_categoria_taxonomy', 6 );
add_action( 'init', 'dnap_create_default_scopri_categorie', 20 );

/* ============================================================
   CPT: scopri
   ============================================================ */
function dnap_register_scopri_cpt(): void {
    $labels = [
        'name'               => 'Scopri',
        'singular_name'      => 'Attività',
        'menu_name'          => 'Scopri',
        'add_new'            => 'Aggiungi attività',
        'add_new_item'       => 'Aggiungi nuova attività',
        'edit_item'          => 'Modifica attività',
        'new_item'           => 'Nuova attività',
        'view_item'          => 'Visualizza attività',
        'search_items'       => 'Cerca attività',
        'not_found'          => 'Nessuna attività trovata',
        'not_found_in_trash' => 'Nessuna attività nel cestino',
    ];

    register_post_type( 'scopri', [
        'labels'              => $labels,
        'public'              => true,
        'publicly_queryable'  => true,
        'show_ui'             => true,
        'show_in_menu'        => true,
        'show_in_rest'        => true,
        'menu_icon'           => 'dashicons-location-alt',
        'menu_position'       => 6,
        'supports'            => [ 'title', 'editor', 'thumbnail', 'custom-fields' ],
        'taxonomies'          => [ 'scopri_categoria', 'city' ],
        'has_archive'         => false,
        'rewrite'             => [ 'slug' => 'scopri' ],
        'capability_type'     => 'post',
    ] );
}

/* ============================================================
   TAXONOMY: scopri_categoria
   ============================================================ */
function dnap_register_scopri_categoria_taxonomy(): void {
    $labels = [
        'name'              => 'Categorie Scopri',
        'singular_name'     => 'Categoria Scopri',
        'search_items'      => 'Cerca categoria',
        'all_items'         => 'Tutte le categorie',
        'edit_item'         => 'Modifica categoria',
        'update_item'       => 'Aggiorna categoria',
        'add_new_item'      => 'Aggiungi categoria',
        'new_item_name'     => 'Nuova categoria',
        'menu_name'         => 'Categorie',
    ];

    register_taxonomy( 'scopri_categoria', [ 'scopri' ], [
        'labels'            => $labels,
        'hierarchical'      => true,
        'public'            => true,
        'show_ui'           => true,
        'show_in_rest'      => true,
        'show_admin_column' => true,
        'rewrite'           => [ 'slug' => 'scopri-categoria' ],
    ] );
}

/* ============================================================
   TERMINI DI DEFAULT
   Eseguito in 'init' con priorità 20 — dopo che sia il CPT
   che la tassonomia sono già stati registrati (priorità 5 e 6).
   Usa wp_insert_term() solo se il termine non esiste.
   ============================================================ */
function dnap_create_default_scopri_categorie(): void {
    if ( ! taxonomy_exists( 'scopri_categoria' ) ) {
        return;
    }

    $categorie = [
        [ 'name' => 'Ristoranti & Locali',    'slug' => 'ristoranti-locali'    ],
        [ 'name' => 'Eventi & Concerti',      'slug' => 'eventi-concerti'      ],
        [ 'name' => 'Spiagge & Stabilimenti', 'slug' => 'spiagge-stabilimenti' ],
        [ 'name' => 'Immobiliare',            'slug' => 'immobiliare'          ],
        [ 'name' => 'Negozi',                 'slug' => 'negozi'               ],
        [ 'name' => 'Food & Gusto',           'slug' => 'food-gusto'           ],
        [ 'name' => 'Turismo & Vacanze',      'slug' => 'turismo-vacanze'      ],
        [ 'name' => 'Shopping',               'slug' => 'shopping'             ],
        [ 'name' => 'Benessere',              'slug' => 'benessere'            ],
    ];

    foreach ( $categorie as $cat ) {
        if ( ! get_term_by( 'slug', $cat['slug'], 'scopri_categoria' ) ) {
            wp_insert_term( $cat['name'], 'scopri_categoria', [
                'slug' => $cat['slug'],
            ] );
        }
    }
}
