<?php
if (!defined('ABSPATH')) exit;
if (!defined('WP_CLI') || !WP_CLI) return;

/* ============================================================
   WP-CLI COMMAND: wp domizio reimport-images
   Re-imports featured images for all posts that have none,
   scraping the original source URL stored in _source_url meta.
   ============================================================ */

class DNAP_CLI_Command {

    /**
     * Re-import featured images for posts that have no thumbnail.
     *
     * Queries every published post with no featured image, reads the
     * _source_url meta, scrapes the article page for an image, and
     * sideloads it as a WebP attachment.
     *
     * ## EXAMPLES
     *
     *     wp domizio reimport-images
     *
     * @when after_wp_load
     */
    public function reimport_images( $args, $assoc_args ) {

        $query = new WP_Query( [
            'post_type'      => 'post',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_query'     => [
                'relation' => 'OR',
                [
                    'key'     => '_thumbnail_id',
                    'compare' => 'NOT EXISTS',
                ],
                [
                    'key'     => '_thumbnail_id',
                    'value'   => '0',
                    'compare' => '=',
                ],
            ],
        ] );

        $post_ids = $query->posts;
        $total    = count( $post_ids );

        if ( $total === 0 ) {
            WP_CLI::success( 'Nessun post senza immagine trovato.' );
            return;
        }

        WP_CLI::log( "Trovati {$total} post senza immagine in evidenza." );
        WP_CLI::log( '' );

        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $progress = WP_CLI\Utils\make_progress_bar( 'Reimport immagini', $total );

        $count_success  = 0;
        $count_external = 0;
        $count_skipped  = 0;
        $count_failed   = 0;

        foreach ( $post_ids as $post_id ) {
            $title      = get_the_title( $post_id );
            $source_url = (string) get_post_meta( $post_id, '_source_url', true );

            if ( empty( $source_url ) ) {
                WP_CLI::log( "  [#{$post_id}] skipped  — nessun _source_url: {$title}" );
                $count_skipped++;
                $progress->tick();
                continue;
            }

            $result = dnap_cli_reimport_image( $post_id, $source_url, $title );

            if ( $result === true ) {
                WP_CLI::log( "  [#{$post_id}] success  — {$title}" );
                $count_success++;
            } elseif ( $result === 'external' ) {
                WP_CLI::log( "  [#{$post_id}] external — immagine salvata come meta esterno: {$title}" );
                $count_external++;
                $count_success++;
            } else {
                WP_CLI::log( "  [#{$post_id}] failed   — {$title}" );
                $count_failed++;
            }

            $progress->tick();
        }

        $progress->finish();

        WP_CLI::log( '' );
        WP_CLI::log( '─────────────────────────────────' );
        WP_CLI::log( "Totale processati : {$total}" );
        WP_CLI::log( "Successo          : {$count_success} (di cui esterni: {$count_external})" );
        WP_CLI::log( "Saltati           : {$count_skipped}" );
        WP_CLI::log( "Falliti           : {$count_failed}" );
        WP_CLI::log( '─────────────────────────────────' );

        if ( $count_failed === 0 ) {
            WP_CLI::success( 'Reimport completato senza errori.' );
        } else {
            WP_CLI::warning( "Reimport completato con {$count_failed} errori." );
        }
    }
}

/**
 * Fetches and sets a featured image for a post using only its source URL.
 * Used by the WP-CLI reimport command; does not require a SimplePie item object.
 *
 * @param int    $post_id    Post ID.
 * @param string $source_url Original article URL stored in _source_url meta.
 * @param string $title      Post title (used as attachment description).
 *
 * @return true       Image sideloaded and set as post thumbnail.
 * @return 'external' Unsplash URL saved as _dnap_external_image meta.
 * @return false      No image found or sideload error.
 */
function dnap_cli_reimport_image( $post_id, $source_url, $title ) {

    $img_url = dnap_fetch_article_image( $source_url );

    if ( empty( $img_url ) ) {
        WP_CLI::debug( "[#{$post_id}] Nessuna immagine trovata via scraping — provo Unsplash API", 'dnap' );
        if ( dnap_unsplash_api_fallback( $post_id ) ) {
            WP_CLI::debug( "[#{$post_id}] Unsplash API: immagine salvata come meta esterno", 'dnap' );
            return 'external';
        }
        WP_CLI::debug( "[#{$post_id}] Nessuna immagine disponibile (scraping + Unsplash API falliti)", 'dnap' );
        return false;
    }

    // Unsplash: never download — store as external meta.
    $host = parse_url( $img_url, PHP_URL_HOST );
    if ( $host && strpos( $host, 'unsplash.com' ) !== false ) {
        WP_CLI::debug( "[#{$post_id}] URL Unsplash rilevato, salvato come meta: {$img_url}", 'dnap' );
        update_post_meta( $post_id, '_dnap_external_image', esc_url_raw( $img_url ) );
        return 'external';
    }

    WP_CLI::debug( "[#{$post_id}] Sideload da: {$img_url}", 'dnap' );
    $attach_id = media_sideload_image( $img_url, $post_id, $title, 'id' );

    if ( is_wp_error( $attach_id ) ) {
        WP_CLI::debug( "[#{$post_id}] Errore sideload: " . $attach_id->get_error_message(), 'dnap' );
        return false;
    }

    dnap_convert_attachment_to_webp( $attach_id );
    set_post_thumbnail( $post_id, $attach_id );

    WP_CLI::debug( "[#{$post_id}] Immagine impostata [attachment {$attach_id}]", 'dnap' );
    return true;
}

WP_CLI::add_command( 'domizio reimport-images', [ 'DNAP_CLI_Command', 'reimport_images' ] );
