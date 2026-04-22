<?php
if (!defined('ABSPATH')) exit;

// Unsplash API key — override in wp-config.php with:
//   define('DNAP_UNSPLASH_KEY', 'your_real_access_key');
if (!defined('DNAP_UNSPLASH_KEY')) {
    define('DNAP_UNSPLASH_KEY', 'YOUR_ACCESS_KEY');
}

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

    // 6. Unsplash API fallback — cerca un'immagine per categoria tramite API ufficiale.
    if (!$img_url) {
        if (dnap_unsplash_api_fallback($post_id)) {
            return; // meta salvati direttamente dalla funzione
        }
        dnap_log("Nessuna immagine trovata per il post {$post_id}");
        return;
    }

    dnap_log("Immagine trovata: " . mb_substr($img_url, 0, 80));

    // URL Unsplash trovato durante scraping (step 0-5): salva come meta esterno, non scaricare.
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
 * Recupera un'immagine casuale da Unsplash API basata sulla categoria del post.
 * Salva l'URL come meta _dnap_external_image (hotlink, non scaricato).
 * Salva il credito fotografo come meta _dnap_unsplash_credit.
 * Attiva il download endpoint richiesto dalle linee guida Unsplash API.
 *
 * @param int $post_id Post ID.
 * @return bool True se l'immagine è stata trovata e i meta salvati.
 */
function dnap_unsplash_api_fallback($post_id) {
    if (!defined('DNAP_UNSPLASH_KEY') || DNAP_UNSPLASH_KEY === 'YOUR_ACCESS_KEY' || empty(DNAP_UNSPLASH_KEY)) {
        dnap_log("Unsplash API: chiave non configurata — fallback saltato");
        return false;
    }

    // ── Traduzione keyword italiane → inglesi per query titolo ──────────────────
    $title_word_map = [
        'cronaca'   => 'news italy',
        'sport'     => 'sport italy',
        'arresti'   => 'arrest italy',
        'arrestato' => 'arrest italy',
        'incendio'  => 'fire italy',
        'incendi'   => 'fire italy',
        'alluvione' => 'flood italy',
        'politica'  => 'politics italy',
        'economia'  => 'economy italy',
        'lavoro'    => 'work italy',
        'salute'    => 'health italy',
        'cultura'   => 'culture italy',
        'ambiente'  => 'nature italy',
        'mare'      => 'sea coast italy',
        'traffico'  => 'traffic italy',
        'comune'    => 'city hall italy',
        'elezioni'  => 'elections italy',
        'calcio'    => 'football italy',
        'scuola'    => 'school italy',
        'tribunale' => 'court italy',
        'ospedale'  => 'hospital italy',
        'polizia'   => 'police italy',
        'vigili'    => 'firefighters italy',
    ];

    // Italian stopwords to remove from title query
    $it_stopwords = ['di', 'del', 'della', 'dei', 'degli', 'delle', 'il', 'lo', 'la',
                     'gli', 'le', 'un', 'una', 'uno', 'che', 'con', 'per', 'nel', 'nella',
                     'nei', 'nelle', 'sul', 'sulla', 'sui', 'sulle', 'tra', 'fra', 'dal',
                     'dalla', 'dai', 'dalle', 'dal', 'alle', 'alla', 'agli', 'allo', 'agli',
                     'non', 'sono', 'era', 'stato', 'stato', 'viene', 'dopo', 'prima', 'ogni',
                     'anche', 'come', 'quando', 'dove', 'che', 'chi', 'piu', 'sua', 'suo',
                     'loro', 'questo', 'questa', 'questi', 'queste', 'nuovo', 'nuova'];

    // ── Categoria → query inglese ────────────────────────────────────────────────
    $category_query_map = [
        'cronaca'             => 'city news italy',
        'sport'               => 'sport italy',
        'politica'            => 'politics government italy',
        'economia-lavoro'     => 'business economy italy',
        'ambiente-mare'       => 'nature environment italy',
        'eventi-cultura'      => 'event concert italy',
        'salute'              => 'health medicine italy',
        'incidenti-sicurezza' => 'emergency safety italy',
    ];

    $categories = get_the_category($post_id);

    // ── Attempt 1: query from post title ────────────────────────────────────────
    $post_title = get_the_title($post_id);
    $title_query = '';
    if ($post_title) {
        $words = preg_split('/\s+/', strtolower(wp_strip_all_tags($post_title)));
        $filtered = [];
        foreach ($words as $w) {
            $w_clean = preg_replace('/[^a-z\x{00C0}-\x{024F}]/u', '', $w);
            if (strlen($w_clean) > 3 && !in_array($w_clean, $it_stopwords, true)) {
                // Translate if in map, else keep original (may be useful in English)
                if (isset($title_word_map[$w_clean])) {
                    $filtered[] = $title_word_map[$w_clean];
                    break; // mapped term already provides full context
                } else {
                    $filtered[] = $w_clean;
                }
            }
            if (count($filtered) >= 3) break;
        }
        $title_query = implode(' ', $filtered);
    }

    // ── Attempt 2: category-based English query ──────────────────────────────────
    // No generic fallback: if the post's category is not mapped, skip this attempt.
    $category_query = '';
    foreach ($categories as $cat) {
        if (isset($category_query_map[$cat->slug])) {
            $category_query = $category_query_map[$cat->slug];
            break;
        }
    }

    $queries_to_try = array_filter([$title_query, $category_query]);
    // deduplicate while preserving order
    $seen = [];
    $unique_queries = [];
    foreach ($queries_to_try as $q) {
        if (!isset($seen[$q])) {
            $seen[$q] = true;
            $unique_queries[] = $q;
        }
    }

    $data = null;
    $query = '';
    foreach ($unique_queries as $attempt_query) {
        $api_url = add_query_arg([
            'query'       => $attempt_query,
            'orientation' => 'landscape',
            'client_id'   => DNAP_UNSPLASH_KEY,
        ], 'https://api.unsplash.com/photos/random');

        $response = wp_remote_get($api_url, ['timeout' => 10]);

        if (is_wp_error($response)) {
            dnap_log("Unsplash API errore (query: {$attempt_query}): " . $response->get_error_message());
            continue;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code === 422) {
            dnap_log("Unsplash API: nessuna foto per query '{$attempt_query}' (422) — provo prossima");
            continue;
        }
        if ($code !== 200) {
            dnap_log("Unsplash API risposta HTTP {$code} (query: {$attempt_query})");
            continue;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($body['urls']['regular']) || empty($body['id'])) {
            dnap_log("Unsplash API: risposta JSON non valida (query: {$attempt_query})");
            continue;
        }

        $data  = $body;
        $query = $attempt_query;
        break;
    }

    if (!$data) {
        $queries_str = $unique_queries ? implode(', ', $unique_queries) : '(nessuna query valida)';
        dnap_log("Unsplash: nessuna immagine trovata per query '{$queries_str}' — articolo senza immagine");
        return false;
    }

    $photo_id     = $data['id'];
    $img_url      = $data['urls']['regular'];
    $photographer = isset($data['user']['name'])       ? $data['user']['name']             : 'Unknown';
    $profile_url  = isset($data['user']['links']['html']) ? $data['user']['links']['html'] : 'https://unsplash.com';

    // Trigger del download endpoint — obbligatorio per le linee guida Unsplash API.
    wp_remote_get(
        'https://api.unsplash.com/photos/' . $photo_id . '/download?client_id=' . DNAP_UNSPLASH_KEY,
        ['timeout' => 10, 'blocking' => false]
    );

    // Salva l'immagine come hotlink (non scaricare).
    update_post_meta($post_id, '_dnap_external_image', esc_url_raw($img_url));

    // Salva il credito fotografo con link UTM come richiesto da Unsplash.
    $credit = sprintf(
        'Photo by <a href="%s" rel="noopener noreferrer">%s</a> on <a href="https://unsplash.com/?utm_source=domizio_news&utm_medium=referral" rel="noopener noreferrer">Unsplash</a>',
        esc_url(add_query_arg(['utm_source' => 'domizio_news', 'utm_medium' => 'referral'], $profile_url)),
        esc_html($photographer)
    );
    update_post_meta($post_id, '_dnap_unsplash_credit', $credit);

    dnap_log("Unsplash API: immagine salvata [{$photo_id}] — query: {$query} — by {$photographer}");
    return true;
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
        'timeout'    => 8,
        'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        'headers'    => array(
            'Accept-Language' => 'it-IT,it;q=0.9',
            'Accept'          => 'text/html,application/xhtml+xml',
            'Referer'         => 'https://www.google.it/',
        ),
    ));

    if (is_wp_error($response)) {
        dnap_log('Errore fetch immagine articolo (' . $url . '): ' . $response->get_error_message());
        return '';
    }
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
