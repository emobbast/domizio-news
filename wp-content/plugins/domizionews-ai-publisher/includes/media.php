<?php
if (!defined('ABSPATH')) exit;

/**
 * Cerca e imposta l'immagine in evidenza.
 * Strategia (in ordine di priorità):
 *   0. $image_url passato direttamente (priorità massima, salta tutte le altre)
 *   1. Enclosure nel feed RSS
 *   2. media:thumbnail nel feed
 *   3. Immagine nel contenuto HTML del feed
 *   4. og:image della pagina sorgente (fondamentale per Google News)
 *   5. Prima <img> nel body della pagina sorgente
 *   6. Immagine di fallback per categoria (Unsplash, ultimo tentativo)
 *
 * Per URL Unsplash: salva come meta _dnap_external_image senza scaricare.
 * Per tutti gli altri: sideload con media_sideload_image() + conversione WebP.
 */
function dnap_set_featured_image($post_id, $title, $item, $source_url = '', $image_url = '') {

    $img_url = '';

    // 0. URL immagine passato direttamente (priorità massima)
    if (!empty($image_url) && dnap_is_valid_image_url($image_url)) {
        $img_url = $image_url;
    }

    // 1. Enclosure RSS
    if (!$img_url) {
        $enclosures = $item->get_enclosures();
        if ($enclosures) {
            foreach ($enclosures as $enc) {
                $type = $enc->get_type();
                $type = $type ? $type : '';
                if (strpos($type, 'image') !== false) {
                    $img_url = $enc->get_link();
                    break;
                }
            }
        }
    }

    // 2. media:thumbnail
    if (!$img_url) {
        $thumbnail = $item->get_item_tags('http://search.yahoo.com/mrss/', 'thumbnail');
        if (!empty($thumbnail[0]['attribs']['']['url'])) {
            $img_url = $thumbnail[0]['attribs']['']['url'];
        }
    }

    // 3. Prima <img> nel contenuto HTML del feed
    if (!$img_url) {
        $html_content = $item->get_content();
        if (!$html_content) $html_content = $item->get_description();
        if ($html_content && preg_match('/<img[^>]+src=["\']([^"\']+)["\']/', $html_content, $m)) {
            $img_url = $m[1];
        }
    }

    // 4 & 5: Vai a prendere l'immagine dalla pagina sorgente
    // Fondamentale per Google News che non include immagini nel feed
    if (!$img_url && !empty($source_url)) {
        $img_url = dnap_fetch_article_image($source_url);
    }

    // Fallback: prova con il permalink del feed item
    if (!$img_url) {
        $permalink = $item->get_permalink();
        if ($permalink && $permalink !== $source_url) {
            $img_url = dnap_fetch_article_image($permalink);
        }
    }

    // 6. Immagine di fallback per categoria
    if (!$img_url) {
        // Usa /800x450/ invece di /featured/ per evitare il redirect cached
        // server-side di Unsplash che restituisce sempre la stessa foto.
        $category_images = [
            'cronaca'             => 'https://source.unsplash.com/800x450/?crime,news,italy',
            'sport'               => 'https://source.unsplash.com/800x450/?sport,athletics',
            'politica'            => 'https://source.unsplash.com/800x450/?politics,government',
            'economia-lavoro'     => 'https://source.unsplash.com/800x450/?business,economy',
            'ambiente-mare'       => 'https://source.unsplash.com/800x450/?sea,nature,beach',
            'eventi-cultura'      => 'https://source.unsplash.com/800x450/?culture,event,art',
            'salute'              => 'https://source.unsplash.com/800x450/?health,medicine',
            'incidenti-sicurezza' => 'https://source.unsplash.com/800x450/?emergency,safety',
        ];
        $default_image = 'https://source.unsplash.com/800x450/?italy,landscape';

        $categories   = get_the_category($post_id);
        $matched_slug = 'default';
        foreach ($categories as $cat) {
            if (isset($category_images[$cat->slug])) {
                $matched_slug = $cat->slug;
                $img_url      = $category_images[$cat->slug] . '&sig=' . $post_id;
                break;
            }
        }
        if (!$img_url) {
            $img_url = $default_image . '&sig=' . $post_id;
        }
        dnap_log("Immagine fallback categoria: {$matched_slug}");
    }

    dnap_log("Immagine trovata: " . mb_substr($img_url, 0, 80));

    // URL Unsplash: non scaricare — salva come meta esterno e termina.
    $host = parse_url($img_url, PHP_URL_HOST);
    if ($host && strpos($host, 'unsplash.com') !== false) {
        update_post_meta($post_id, '_dnap_external_image', esc_url_raw($img_url));
        dnap_log("Immagine Unsplash salvata come meta esterno");
        return;
    }

    // Scarica e importa con media_sideload_image() (gestisce thumbnails in automatico).
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';

    $attach_id = media_sideload_image($img_url, $post_id, $title, 'id');

    if (is_wp_error($attach_id)) {
        dnap_log("Errore sideload immagine: " . $attach_id->get_error_message());
        return;
    }

    // Converti JPG/PNG → WebP e rimuovi l'originale.
    dnap_convert_attachment_to_webp($attach_id);

    set_post_thumbnail($post_id, $attach_id);
    dnap_log("Immagine impostata [attachment {$attach_id}]");
}

/**
 * Converte un attachment JPG/PNG in WebP usando PHP GD.
 * Converte il file originale e tutte le dimensioni intermedie generate da WP,
 * quindi elimina i file sorgente e aggiorna i metadati dell'attachment.
 */
function dnap_convert_attachment_to_webp($attach_id) {
    if (!function_exists('imagewebp')) {
        dnap_log("GD WebP non supportato — conversione saltata");
        return;
    }

    $file_path = get_attached_file($attach_id);
    if (!$file_path || !file_exists($file_path)) return;

    $mime = get_post_mime_type($attach_id);
    if (!in_array($mime, ['image/jpeg', 'image/png'], true)) return;

    $meta       = wp_get_attachment_metadata($attach_id);
    $upload_dir = dirname($file_path);

    // Raccogli file originale + tutte le dimensioni intermedie.
    $files_to_convert = [$file_path];
    if (!empty($meta['sizes'])) {
        foreach ($meta['sizes'] as $size_data) {
            $size_file = $upload_dir . '/' . $size_data['file'];
            if (file_exists($size_file)) {
                $files_to_convert[] = $size_file;
            }
        }
    }

    // Converti ogni file in WebP ed elimina l'originale.
    foreach ($files_to_convert as $src_path) {
        $webp_path = preg_replace('/\.(jpe?g|png)$/i', '.webp', $src_path);
        if ($webp_path === $src_path) continue;

        if ($mime === 'image/jpeg') {
            $img = @imagecreatefromjpeg($src_path);
        } else {
            $img = @imagecreatefrompng($src_path);
            if ($img) {
                imagepalettetotruecolor($img);
                imagealphablending($img, true);
                imagesavealpha($img, true);
            }
        }

        if (!$img) continue;

        if (imagewebp($img, $webp_path, 82)) {
            @unlink($src_path);
        }
        imagedestroy($img);
    }

    // Aggiorna i metadati dell'attachment per puntare ai file WebP.
    $new_file_path = preg_replace('/\.(jpe?g|png)$/i', '.webp', $file_path);
    if (!file_exists($new_file_path)) return;

    update_attached_file($attach_id, $new_file_path);

    global $wpdb;
    $wpdb->update($wpdb->posts, ['post_mime_type' => 'image/webp'], ['ID' => $attach_id]);
    clean_post_cache($attach_id);

    if (!empty($meta['sizes'])) {
        foreach ($meta['sizes'] as &$size_data) {
            $new_size_file = preg_replace('/\.(jpe?g|png)$/i', '.webp', $size_data['file']);
            if (file_exists($upload_dir . '/' . $new_size_file)) {
                $size_data['file']      = $new_size_file;
                $size_data['mime-type'] = 'image/webp';
            }
        }
        unset($size_data);
    }
    $meta['file']      = _wp_relative_upload_path($new_file_path);
    $meta['mime-type'] = 'image/webp';
    wp_update_attachment_metadata($attach_id, $meta);

    dnap_log("Conversione WebP completata [attachment {$attach_id}]");
}

/**
 * Scarica la pagina e cerca l'immagine principale.
 * Cerca in ordine: og:image → twitter:image → prima <img> grande
 */
function dnap_fetch_article_image($url) {

    if (empty($url)) return '';

    $host = parse_url($url, PHP_URL_HOST);
    if ($host && strpos($host, 'google.com') !== false) return '';

    $response = wp_remote_get($url, array(
        'timeout'    => 20,
        'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        'headers'    => array(
            'Accept-Language' => 'it-IT,it;q=0.9',
            'Accept'          => 'text/html,application/xhtml+xml',
            'Referer'         => 'https://www.google.it/',
        ),
    ));

    if (is_wp_error($response)) return '';
    if (wp_remote_retrieve_response_code($response) !== 200) return '';

    $html = wp_remote_retrieve_body($response);
    if (empty($html)) return '';

    // 1. og:image — il più affidabile, presente su quasi tutti i siti di news
    if (preg_match('/<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)["\']/', $html, $m)) {
        $img = html_entity_decode(trim($m[1]));
        if (dnap_is_valid_image_url($img)) return $img;
    }
    // Variante con content prima di property
    if (preg_match('/<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:image["\']/', $html, $m)) {
        $img = html_entity_decode(trim($m[1]));
        if (dnap_is_valid_image_url($img)) return $img;
    }

    // 2. twitter:image
    if (preg_match('/<meta[^>]+name=["\']twitter:image["\'][^>]+content=["\']([^"\']+)["\']/', $html, $m)) {
        $img = html_entity_decode(trim($m[1]));
        if (dnap_is_valid_image_url($img)) return $img;
    }

    // 3. Prima immagine grande nel body (escludi logo, icone, ecc.)
    if (preg_match_all('/<img[^>]+src=["\']([^"\']+)["\'][^>]*>/i', $html, $matches)) {
        foreach ($matches[1] as $src) {
            $src = html_entity_decode(trim($src));
            if (!dnap_is_valid_image_url($src)) continue;

            // Salta immagini che sembrano icone/logo/tracking pixel
            $lower = strtolower($src);
            if (
                strpos($lower, 'logo') !== false ||
                strpos($lower, 'icon') !== false ||
                strpos($lower, 'avatar') !== false ||
                strpos($lower, 'sprite') !== false ||
                strpos($lower, 'pixel') !== false ||
                strpos($lower, '1x1') !== false ||
                strpos($lower, 'blank') !== false
            ) continue;

            // Preferisci URL con width/height alto (es. image-800x600.jpg)
            if (preg_match('/[_-](\d{3,4})x(\d{3,4})/i', $src, $dim)) {
                if (intval($dim[1]) >= 400 && intval($dim[2]) >= 200) {
                    return $src;
                }
            }

            return $src; // prendi la prima immagine valida
        }
    }

    return '';
}

/**
 * Verifica che l'URL sia un'immagine valida e assoluta
 */
function dnap_is_valid_image_url($url) {
    if (empty($url)) return false;
    if (substr($url, 0, 4) !== 'http') return false;
    if (strpos($url, ' ') !== false) return false;

    $ext = strtolower(pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
    $ext = preg_replace('/[^a-z0-9]/', '', $ext);

    // Accetta anche URL senza estensione (es. CDN con parametri)
    if (empty($ext)) return true;

    return in_array($ext, array('jpg', 'jpeg', 'png', 'webp', 'gif', 'avif'));
}
