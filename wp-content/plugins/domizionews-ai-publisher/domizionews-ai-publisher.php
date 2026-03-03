<?php
/*
Plugin Name: Domizio News AI Publisher
Description: v7.1 — Sicurezza, scraping intelligente, AI avanzata, anti-duplicato, SEO automation
Version: 7.0
Author: Domizio News
*/

if (!defined('ABSPATH')) exit;

define('DNAP_DIR',        plugin_dir_path(__FILE__));
define('DNAP_VERSION',    '7.1');
define('DNAP_LOG_KEY',    'dnap_import_log');
define('DNAP_LOG_MAX',    200);
define('DNAP_MAX_PER_RUN', 5);

require_once DNAP_DIR . 'includes/log.php';

register_activation_hook(__FILE__, 'dnap_on_activate');
register_deactivation_hook(__FILE__, 'dnap_on_deactivate');

function dnap_on_activate() {
    // Registra subito la taxonomy così wp_insert_term funziona
    if ( ! taxonomy_exists( 'city' ) ) {
        require_once DNAP_DIR . 'includes/core.php';
        dnap_register_city_taxonomy();
    }

    // Cron
    if ( ! wp_next_scheduled( 'dnap_cron_import' ) ) {
        wp_schedule_event( time(), 'hourly', 'dnap_cron_import' );
    }

    // Crea le città di default se non esistono già
    dnap_create_default_cities();

    // Flush rewrite rules per la nuova taxonomy
    flush_rewrite_rules();

    dnap_log( '✅ Plugin attivato v' . DNAP_VERSION . '. Cron: ogni ora, max ' . DNAP_MAX_PER_RUN . ' articoli/run.' );
}

/* ============================================================
   CREA CITTÀ DI DEFAULT AL PRIMO AVVIO
   ============================================================ */
function dnap_create_default_cities() {
    $cities = [
        [ 'name' => 'Mondragone',           'slug' => 'mondragone',           'description' => 'Notizie locali da Mondragone, comune costiero della provincia di Caserta.' ],
        [ 'name' => 'Castel Volturno',      'slug' => 'castel-volturno',      'description' => 'Notizie locali da Castel Volturno, sul Litorale Domizio in provincia di Caserta.' ],
        [ 'name' => 'Baia Domizia',         'slug' => 'baia-domizia',         'description' => 'Notizie locali da Baia Domizia, rinomata località balneare del Litorale Domizio.' ],
        [ 'name' => 'Sessa Aurunca',        'slug' => 'sessa-aurunca',        'description' => 'Notizie locali da Sessa Aurunca, comune in provincia di Caserta.' ],
        [ 'name' => 'Cellole',              'slug' => 'cellole',              'description' => 'Notizie locali da Cellole, comune del Litorale Domizio in provincia di Caserta.' ],
        [ 'name' => 'Falciano del Massico', 'slug' => 'falciano-del-massico', 'description' => 'Notizie locali da Falciano del Massico, comune in provincia di Caserta.' ],
        [ 'name' => 'Carinola',             'slug' => 'carinola',             'description' => 'Notizie locali da Carinola, comune in provincia di Caserta.' ],
    ];

    $created = 0;
    foreach ( $cities as $city ) {
        if ( ! get_term_by( 'slug', $city['slug'], 'city' ) ) {
            $result = wp_insert_term( $city['name'], 'city', [
                'slug'        => $city['slug'],
                'description' => $city['description'],
            ] );
            if ( ! is_wp_error( $result ) ) $created++;
        }
    }

    if ( $created > 0 ) {
        dnap_log( "🏙️ Create {$created} città di default (Litorale Domizio)." );
    }
}

function dnap_on_deactivate() {
    wp_clear_scheduled_hook('dnap_cron_import');
    delete_transient('dnap_running');
    dnap_log('⛔ Plugin disattivato.');
}

add_action('dnap_cron_import', 'dnap_import_now');

add_action('plugins_loaded', 'dnap_load_modules');
function dnap_load_modules() {
    require_once DNAP_DIR . 'includes/core.php';
    require_once DNAP_DIR . 'includes/gpt.php';
    require_once DNAP_DIR . 'includes/media.php';
    require_once DNAP_DIR . 'admin/dashboard.php';
    require_once DNAP_DIR . 'admin/feeds.php';
}
