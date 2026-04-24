<?php
if (!defined('ABSPATH')) exit;

// Articoli più vecchi di N giorni vengono scartati prima della chiamata a Claude.
// Evita la ripubblicazione di archivi e feed stantii (es. eventi di Carnevale ad aprile).
if (!defined('DNAP_MAX_ARTICLE_AGE_DAYS')) define('DNAP_MAX_ARTICLE_AGE_DAYS', 14);

// TTL di sicurezza sul lock di import: se un processo muore per errore fatale
// prima che register_shutdown_function rilasci il lock, il successivo run
// considera stantio un lock più vecchio di questo valore e lo sovrascrive.
if (!defined('DNAP_LOCK_TTL_SECONDS')) define('DNAP_LOCK_TTL_SECONDS', 600);

/* ============================================================
   TASSONOMIA "CITY"
   Registrata qui perché core.php viene caricato ad ogni
   plugins_loaded, garantendo che la taxonomy sia sempre
   disponibile prima che WP risolva le query e le REST API.
   ============================================================ */
add_action('init', 'dnap_register_city_taxonomy', 5);
function dnap_register_city_taxonomy() {
    register_taxonomy('city', 'post', [
        'label'              => 'Città',
        'labels'             => [
            'name'              => 'Città',
            'singular_name'     => 'Città',
            'search_items'      => 'Cerca città',
            'all_items'         => 'Tutte le città',
            'edit_item'         => 'Modifica città',
            'update_item'       => 'Aggiorna città',
            'add_new_item'      => 'Aggiungi città',
            'new_item_name'     => 'Nuova città',
            'menu_name'         => 'Città',
        ],
        'hierarchical'       => false,
        'public'             => true,
        'show_ui'            => true,
        'show_in_menu'       => true,
        'show_in_nav_menus'  => true,
        'show_tagcloud'      => false,
        'show_in_quick_edit' => true,
        'show_admin_column'  => true,
        'show_in_rest'       => true,   // esposta nelle REST API e nel blocco Gutenberg
        'rest_base'          => 'city',
        'rewrite'            => ['slug' => 'citta', 'with_front' => false],
        'query_var'          => true,
        'capabilities'       => [
            'manage_terms' => 'manage_categories',
            'edit_terms'   => 'manage_categories',
            'delete_terms' => 'manage_categories',
            'assign_terms' => 'edit_posts',
        ],
    ]);
}

/* ============================================================
   AGGREGATE CITY TERMS
   Virtual slugs che raggruppano coppie geografiche vicine
   ("Cellole e Baia Domizia", "Falciano e Carinola") esposte
   come slot unico nella home SPA. I termini sono creati qui
   così esistono nel DB e i loro archivi /citta/<slug>/ sono
   crawlabili da Googlebot anche prima che gli articoli siano
   riassegnati (riassegnazione via WP-CLI post-deploy).
   ============================================================ */
add_action('init', 'dnap_ensure_aggregate_city_terms', 10);
function dnap_ensure_aggregate_city_terms() {
    // Label senza slash ("e" al posto di "/"): leggibile, Material Design–friendly.
    // Slug non cambiano: /citta/cellole-baia-domizia/ e /citta/falciano-carinola/
    // sono già indicizzati, cambiarli romperebbe i permalink.
    $aggregates = [
        'cellole-baia-domizia' => 'Cellole e Baia Domizia',
        'falciano-carinola'    => 'Falciano e Carinola',
    ];
    foreach ($aggregates as $slug => $name) {
        $existing = term_exists($slug, 'city');
        if (!$existing) {
            wp_insert_term($name, 'city', ['slug' => $slug]);
            continue;
        }
        $term_id = is_array($existing) ? (int) $existing['term_id'] : (int) $existing;
        $term    = get_term($term_id, 'city');
        if ($term && !is_wp_error($term) && $term->name !== $name) {
            wp_update_term($term_id, 'city', ['name' => $name]);
        }
    }
}

/* ============================================================
   AUTORE "REDAZIONE"
   ============================================================ */
function dnap_get_redazione_author() {
    $user = get_user_by('login', 'redazione');
    if (!$user) {
        $id = wp_create_user('redazione', wp_generate_password(), 'redazione@' . parse_url(home_url(), PHP_URL_HOST));
        wp_update_user(['ID' => $id, 'display_name' => 'Redazione']);
        dnap_log('Utente redazione creato.');
        return $id;
    }
    return $user->ID;
}

/* ============================================================
   ANTI-DUPLICATO — hash + similarità titolo (48h)
   ============================================================ */
function dnap_source_exists(string $source_url, string $hash, string $new_title): bool {

    // 1. URL esatto
    if (get_posts(['post_type' => 'post', 'meta_key' => '_source_url',
                   'meta_value' => $source_url, 'posts_per_page' => 1, 'fields' => 'ids'])) {
        return true;
    }

    // 2. Hash contenuto
    if (get_posts(['post_type' => 'post', 'meta_key' => '_source_hash',
                   'meta_value' => $hash, 'posts_per_page' => 1, 'fields' => 'ids'])) {
        return true;
    }

    // 3. Titolo simile negli ultimi 30 giorni (similar_text ≥ 70%)
    $recent = get_posts([
        'post_type'      => 'post',
        'posts_per_page' => 300,
        'fields'         => 'ids',
        'date_query'     => [['after' => '30 days ago']],
    ]);

    foreach ($recent as $pid) {
        $existing_title = get_the_title($pid);
        similar_text(
            mb_strtolower($new_title),
            mb_strtolower($existing_title),
            $pct
        );
        if ($pct >= 70) {
            dnap_log("Duplicato titolo ({$pct}%): \"{$new_title}\" ~ \"{$existing_title}\"");
            return true;
        }
    }

    return false;
}

/* ============================================================
   RISOLVI URL GOOGLE NEWS (no sslverify false)
   ============================================================ */
function dnap_resolve_google_url(string $url): string {
    if (strpos($url, 'news.google.com') === false) return $url;

    $response = wp_remote_get($url, [
        'timeout'     => 15,
        'redirection' => 10,
        'user-agent'  => 'Mozilla/5.0 (compatible; DomizioNewsBot/1.0)',
        'headers'     => ['Accept-Language' => 'it-IT,it;q=0.9'],
    ]);

    if (is_wp_error($response)) {
        dnap_log('Errore risoluzione Google URL (' . $url . '): ' . $response->get_error_message());
        return $url;
    }

    $body = wp_remote_retrieve_body($response);

    // Link canonico
    if (preg_match('/<link[^>]+rel=["\']canonical["\'][^>]+href=["\']([^"\']+)["\']/', $body, $m)) {
        $found = html_entity_decode($m[1]);
        if (strpos($found, 'google.com') === false && filter_var($found, FILTER_VALIDATE_URL)) {
            return esc_url_raw($found);
        }
    }

    // Meta refresh
    if (preg_match('/content=["\'][0-9]+;\s*url=([^"\'&]+)/i', $body, $m)) {
        $found = esc_url_raw(html_entity_decode(trim($m[1])));
        if (filter_var($found, FILTER_VALIDATE_URL)) return $found;
    }

    return $url;
}

/* ============================================================
   DECODE URL BASE64 DA LINK GOOGLE NEWS
   Decodifica il payload CBMi... di news.google.com/rss/articles/
   senza effettuare richieste HTTP. Restituisce l'URL originale
   dell'articolo o null in caso di fallimento.
   ============================================================ */
function dnap_decode_google_news_url( string $url ): ?string {
    try {
        $parsed = wp_parse_url( $url );
        $path   = is_array( $parsed ) ? ( $parsed['path'] ?? '' ) : '';
        if ( ! is_string( $path ) || strpos( $path, '/articles/' ) === false ) {
            return null;
        }
        $parts   = explode( '/articles/', $path, 2 );
        $payload = isset( $parts[1] ) ? $parts[1] : '';
        // Elimina eventuali segmenti extra (?, /, #) dopo il payload
        $payload = preg_replace( '~[/?#].*$~', '', $payload );
        if ( ! is_string( $payload ) || $payload === '' ) {
            return null;
        }
        // URL-safe base64: traduci - e _ e re-aggiungi il padding mancante
        $payload = strtr( $payload, '-_', '+/' );
        $pad     = strlen( $payload ) % 4;
        if ( $pad ) {
            $payload .= str_repeat( '=', 4 - $pad );
        }
        $decoded = @base64_decode( $payload, false );
        if ( ! is_string( $decoded ) || $decoded === '' ) {
            return null;
        }
        if ( ! preg_match( '~https?://[^\x00-\x20"\'<>\\\\]+~', $decoded, $m ) ) {
            return null;
        }
        $found = $m[0];
        if ( ! filter_var( $found, FILTER_VALIDATE_URL ) ) {
            return null;
        }
        $host = wp_parse_url( $found, PHP_URL_HOST );
        if ( ! is_string( $host ) || $host === '' ) {
            return null;
        }
        if ( stripos( $host, 'google.com' ) !== false ) {
            return null;
        }
        return $found;
    } catch ( \Throwable $e ) {
        return null;
    }
}

/* ============================================================
   NORMALIZZA HOST DA URL (lowercase, strip "www.")
   ============================================================ */
function dnap_normalize_host( string $url ): ?string {
    $host = wp_parse_url( $url, PHP_URL_HOST );
    if ( ! is_string( $host ) || $host === '' ) {
        return null;
    }
    $host = strtolower( $host );
    if ( strncmp( $host, 'www.', 4 ) === 0 ) {
        $host = substr( $host, 4 );
    }
    return $host;
}

/* ============================================================
   HOST DEI FEED DIRETTI
   Rilegge dnap_feeds ad ogni invocazione (no cache): qualsiasi
   modifica dall'admin UI (add/remove/pause) contribuisce
   automaticamente al dedup di Google News.
   Esclude news.google.com (search aggregator, non editore diretto).
   ============================================================ */
function dnap_direct_feed_hosts(): array {
    $feeds = get_option( 'dnap_feeds', [] );
    if ( ! is_array( $feeds ) ) {
        return [];
    }
    $hosts = [];
    foreach ( $feeds as $feed ) {
        if ( ! is_array( $feed ) || empty( $feed['active'] ) ) continue;
        $url = isset( $feed['url'] ) ? (string) $feed['url'] : '';
        if ( $url === '' ) continue;
        $host = dnap_normalize_host( $url );
        if ( $host === null || $host === 'news.google.com' ) continue;
        $hosts[ $host ] = true;
    }
    return array_keys( $hosts );
}

/* ============================================================
   RISOLUZIONE URL REALE DA ITEM GOOGLE NEWS
   Tenta di ricavare l'URL dell'articolo originale dai metadati
   dell'item SimplePie, senza effettuare richieste HTTP.
   Ritorna ['url' => string, 'skip' => bool].
   ============================================================ */
function dnap_resolve_google_news_url( $item, string $source_url ): array {

    if ( strpos( $source_url, 'news.google.com' ) === false ) {
        return [ 'url' => $source_url, 'skip' => false ];
    }

    // 0. Decodifica il payload base64 (CBMi...) — no HTTP
    $decoded = dnap_decode_google_news_url( $source_url );
    if ( $decoded !== null ) {
        dnap_log( "✅ Google base64 hit: {$source_url} → {$decoded}" );
        $decoded_host = dnap_normalize_host( $decoded );
        if ( $decoded_host !== null && in_array( $decoded_host, dnap_direct_feed_hosts(), true ) ) {
            dnap_log( "⏭ Google News skip — già coperto da feed diretto: {$decoded_host}" );
            return [ 'url' => $source_url, 'skip' => true ];
        }
        return [ 'url' => esc_url_raw( $decoded ), 'skip' => false ];
    }
    dnap_log( "❌ Google base64 miss: {$source_url}" );

    // 1. Enclosure link
    $enc = $item->get_enclosure();
    if ( $enc ) {
        $enc_link = $enc->get_link();
        if ( $enc_link && strpos( $enc_link, 'google.com' ) === false && filter_var( $enc_link, FILTER_VALIDATE_URL ) ) {
            dnap_log( "Google News → enclosure: {$enc_link}" );
            return [ 'url' => esc_url_raw( $enc_link ), 'skip' => false ];
        }
    }

    // 2. Tutti i link dell'item — primo non-google
    $links = $item->get_links() ?? [];
    foreach ( $links as $link ) {
        if ( $link && strpos( $link, 'google.com' ) === false && filter_var( $link, FILTER_VALIDATE_URL ) ) {
            dnap_log( "Google News → link: {$link}" );
            return [ 'url' => esc_url_raw( $link ), 'skip' => false ];
        }
    }

    // 3. Tag <a href="..."> nella description/content dell'item
    $content = $item->get_content() ?: ( $item->get_description() ?: '' );
    if ( $content && preg_match_all( '/<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>/i', $content, $matches ) ) {
        foreach ( $matches[1] as $href ) {
            $href = html_entity_decode( $href );
            if ( strpos( $href, 'google.com' ) === false && filter_var( $href, FILTER_VALIDATE_URL ) ) {
                dnap_log( "Google News → href in content: {$href}" );
                return [ 'url' => esc_url_raw( $href ), 'skip' => false ];
            }
        }
    }

    // 4. Fallback: URL originale (dnap_scrape_meta gestirà il redirect HTTP)
    return [ 'url' => $source_url, 'skip' => false ];
}

/* ============================================================
   SCRAPING INTELLIGENTE
   Solo: titolo, estratto (og:description), immagine (og:image),
   URL canonico. NON importa il corpo dell'articolo.
   ============================================================ */
function dnap_scrape_meta(string $url): array {
    $result = [
        'canonical'   => $url,
        'description' => '',
        'image'       => '',
        'title'       => '',
    ];

    // Risoluzione redirect per Google News (prima di qualsiasi scraping)
    if (strpos($url, 'news.google.com') !== false) {
        $gr = wp_remote_get($url, [
            'timeout'     => 15,
            'redirection' => 5,
            'user-agent'  => 'Mozilla/5.0 (compatible; DomizioNewsBot/1.0)',
            'headers'     => ['Accept-Language' => 'it-IT,it;q=0.9'],
        ]);
        if (is_wp_error($gr)) {
            dnap_log('Errore redirect Google News (' . $url . '): ' . $gr->get_error_message());
        } else {
            $resolved = '';
            // 1. effectiveUrl dalla libreria Requests (URL finale dopo tutti i redirect)
            $http_obj = $gr['http_response'] ?? null;
            if ($http_obj instanceof WP_HTTP_Requests_Response) {
                $eff = $http_obj->get_response_object()->url;
                if ($eff && strpos($eff, 'google.com') === false && filter_var($eff, FILTER_VALIDATE_URL)) {
                    $resolved = esc_url_raw($eff);
                }
            }
            // 2. Location header (ultimo redirect non seguito)
            if (!$resolved) {
                $loc = wp_remote_retrieve_header($gr, 'location');
                if ($loc && strpos($loc, 'google.com') === false && filter_var($loc, FILTER_VALIDATE_URL)) {
                    $resolved = esc_url_raw($loc);
                }
            }
            // 3. canonical nel body della risposta
            if (!$resolved) {
                $body = wp_remote_retrieve_body($gr);
                if (preg_match('/<link[^>]+rel=["\']canonical["\'][^>]+href=["\']([^"\']+)["\']/', $body, $m)) {
                    $found = html_entity_decode($m[1]);
                    if (strpos($found, 'google.com') === false && filter_var($found, FILTER_VALIDATE_URL)) {
                        $resolved = esc_url_raw($found);
                    }
                }
            }
            if ($resolved) {
                $url = $resolved;
                $result['canonical'] = $url;
                dnap_log("Google News risolto → {$url}");
            }
        }
    }

    // Non procedere con URL ancora Google (news non risolto o altri URL google.com)
    $host = parse_url($url, PHP_URL_HOST);
    if ($host && strpos($host, 'google.com') !== false) {
        dnap_log("URL Google non risolto, skip scraping: {$url}");
        return $result;
    }

    $response = wp_remote_get($url, [
        'timeout'    => 15,
        'user-agent' => 'Mozilla/5.0 (compatible; DomizioNewsBot/1.0)',
        'headers'    => ['Accept-Language' => 'it-IT,it;q=0.9'],
    ]);

    if (is_wp_error($response)) {
        dnap_log('Errore scraping meta (' . $url . '): ' . $response->get_error_message());
        return $result;
    }

    $code = wp_remote_retrieve_response_code($response);
    if ($code !== 200) {
        dnap_log("Scraping meta HTTP {$code}: {$url}");
        return $result;
    }

    $html = wp_remote_retrieve_body($response);
    if (strlen($html) < 100) return $result;

    // Estrai solo il <head>
    preg_match('/<head[^>]*>([\s\S]*?)<\/head>/i', $html, $head_match);
    $head = $head_match[1] ?? substr($html, 0, 5000);

    // og:title
    if (preg_match('/<meta[^>]+property=["\']og:title["\'][^>]+content=["\']([^"\']+)["\']/', $head, $m)) {
        $result['title'] = html_entity_decode(trim($m[1]), ENT_QUOTES, 'UTF-8');
    }
    // og:description
    if (preg_match('/<meta[^>]+(?:property=["\']og:description["\']|name=["\']description["\'])[^>]+content=["\']([^"\']{20,})["\']/', $head, $m)) {
        $result['description'] = html_entity_decode(trim($m[1]), ENT_QUOTES, 'UTF-8');
    }
    // og:image
    if (preg_match('/<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)["\']/', $head, $m)) {
        $result['image'] = esc_url_raw(html_entity_decode($m[1]));
    }
    // canonical
    if (preg_match('/<link[^>]+rel=["\']canonical["\'][^>]+href=["\']([^"\']+)["\']/', $head, $m)) {
        $found = esc_url_raw(html_entity_decode($m[1]));
        if (filter_var($found, FILTER_VALIDATE_URL)) {
            $result['canonical'] = $found;
        }
    }

    return $result;
}

/* ============================================================
   ESTRAI TESTO DAL FEED ITEM
   ============================================================ */
function dnap_get_item_text($item): string {
    $text = '';
    if (method_exists($item, 'get_content')) $text = $item->get_content();
    if (empty(trim(strip_tags($text)))) $text = $item->get_description();
    if (empty(trim(strip_tags($text)))) $text = $item->get_title() . '. ' . $item->get_description();

    $text = wp_strip_all_tags($text);
    $text = preg_replace('/\s+/', ' ', $text);
    return trim(mb_substr($text, 0, 800));
}

/* ============================================================
   FILTRO CONTENUTO LOCALE
   Restituisce true solo se il testo contiene almeno una keyword
   geografica del litorale domizio / area casertana.
   ============================================================ */
function dnap_is_local_content(string $text): bool {
    $keywords = [
        'mondragone', 'castel volturno', 'pinetamare', 'villaggio coppola',
        'pescopagano', 'ischitella', 'baia verde', 'baia domizia', 'baia felice',
        'cellole', 'borgo centore', 'san limato', 'falciano del massico', 'falciano',
        'carinola', 'ventaroli', 'varano', 'maiorano di monte', 'sessa aurunca',
        'piedimonte massicano', 'litorale domizio', 'litorale domitio',
        'giovanni zannini', 'zannini',
        'pineta nuova', 'pineta riviera', 'baia azzurra', 'levagnole',
        'nocelleto', 'casanova di carinola',
        'carano', 'cascano', 'rongolise', 'fasani', 'lauro',
        'corbara', 'valogno', 'san castrese', 'san carlo',
    ];
    $lower = mb_strtolower($text);
    foreach ($keywords as $kw) {
        if (strpos($lower, $kw) !== false) return true;
    }
    return false;
}

/* ============================================================
   RILEVAMENTO CITTÀ DAL TESTO
   Scorre il testo cercando keyword geografiche.
   Restituisce array di slug città univoci.
   ============================================================ */
/**
 * Extract Litorale Domizio city slugs from article text.
 * Uses word-boundary regex to avoid false positives on generic frazioni names.
 *
 * Examples:
 *   "ponte della Scafa a Roma"     → [] (no match — "ponte" is not a word followed by Scafa here as a frazione)
 *   "festa a Ponte di Castel V."   → ['castel-volturno']
 *   "Cellole e Baia Domizia"       → ['cellole', 'baia-domizia']
 *   "Montelauro in provincia"      → [] (no match — "lauro" is substring, not word)
 *   "Lauro, frazione di Sessa"     → ['sessa-aurunca']
 */
function dnap_get_cities_from_text(string $text): array {
    $map = [
        'giovanni zannini'     => 'mondragone',
        'zannini'              => 'mondragone',
        'falciano del massico' => 'falciano-del-massico',
        'villaggio coppola'    => 'castel-volturno',
        'maiorano di monte'    => 'carinola',
        'san castrese'         => 'sessa-aurunca',
        'san carlo'            => 'sessa-aurunca',
        'rongolise'            => 'sessa-aurunca',
        'cascano'              => 'sessa-aurunca',
        'corbara'              => 'sessa-aurunca',
        'valogno'              => 'sessa-aurunca',
        'carano'               => 'sessa-aurunca',
        'fasani'               => 'sessa-aurunca',
        'lauro di sessa'       => 'sessa-aurunca',
        'lauro sessa aurunca'  => 'sessa-aurunca',
        'piedimonte massicano' => 'sessa-aurunca',
        'castel volturno'      => 'castel-volturno',
        'borgo centore'        => 'cellole',
        'sessa aurunca'        => 'sessa-aurunca',
        'baia domizia'         => 'baia-domizia',
        'baia felice'          => 'sessa-aurunca',
        'baia verde'           => 'castel-volturno',
        'san limato'           => 'cellole',
        'bagni di mondragone'  => 'mondragone',
        'pineta riviera'       => 'mondragone',
        'pineta nuova'         => 'mondragone',
        'baia azzurra'         => 'mondragone',
        'levagnole'            => 'mondragone',
        'mondragone'           => 'mondragone',
        'pinetamare'           => 'castel-volturno',
        'pescopagano'          => 'castel-volturno',
        'ischitella'           => 'castel-volturno',
        'ventaroli'            => 'carinola',
        'falciano'             => 'falciano-del-massico',
        'casanova di carinola' => 'carinola',
        'nocelleto'            => 'carinola',
        'carinola'             => 'carinola',
        'cellole'              => 'cellole',
        'varano di carinola'   => 'carinola',
    ];

    $slugs = [];
    foreach ($map as $keyword => $slug) {
        $pattern = '/\b' . preg_quote($keyword, '/') . '\b/iu';
        if (preg_match($pattern, $text) && !in_array($slug, $slugs, true)) {
            $slugs[] = $slug;
        }
    }
    return $slugs;
}

/* ============================================================
   SOGGETTI VIP — post in evidenza (sticky)
   Restituisce true se il testo contiene almeno un tag da
   'dnap_vip_tags' (wp_options). Case-insensitive.
   ============================================================ */
function dnap_is_featured_subject(string $text): bool {
    $keywords = get_option('dnap_vip_tags', ['zannini', 'giovanni zannini']);
    if (empty($keywords)) return false;
    $lower = mb_strtolower($text);
    foreach ($keywords as $kw) {
        if ($kw !== '' && strpos($lower, mb_strtolower($kw)) !== false) return true;
    }
    return false;
}

/* ============================================================
   IMPORT PRINCIPALE
   Rate limiting: max DNAP_MAX_PER_RUN per esecuzione
   Lock transient per evitare run paralleli
   ============================================================ */
function dnap_import_now() {

    // Atomic lock via INSERT IGNORE (add_option returns false if
    // option already exists — atomic at DB level, safe without Redis)
    $existing_lock = get_option('dnap_import_lock', false);
    if ($existing_lock !== false) {
        $lock_age = time() - (int) $existing_lock;
        if ($lock_age > DNAP_LOCK_TTL_SECONDS) {
            dnap_log("Lock stantio rilevato (età {$lock_age}s) — rimosso e riavvio");
            delete_option('dnap_import_lock');
        }
    }

    if (!add_option('dnap_import_lock', time(), '', 'no')) {
        dnap_log('Import già in esecuzione (lock attivo) — skip');
        return;
    }
    // Register shutdown to release lock even on fatal errors
    register_shutdown_function(function() {
        delete_option('dnap_import_lock');
    });

    $feeds = get_option('dnap_feeds', []);
    if (empty($feeds)) {
        dnap_log('Nessun feed configurato.');
        delete_option('dnap_import_lock');
        return;
    }

    require_once ABSPATH . WPINC . '/feed.php';

    $total_imported = 0;
    $total_skipped  = 0;
    $total_errors   = 0;
    $run_limit      = (int) get_option('dnap_max_per_run', DNAP_MAX_PER_RUN);

    $active_feeds = array_filter($feeds, fn($f) => !empty($f['active']));
    dnap_log("Import avviato — " . count($active_feeds) . " feed attivi, max {$run_limit}/run");

    foreach ($feeds as $feed) {

        if (empty($feed['active'])) continue;
        if ($total_imported >= $run_limit) {
            dnap_log("Limite {$run_limit} articoli/run raggiunto. Fermato.");
            break;
        }

        $feed_url = esc_url_raw(sanitize_text_field($feed['url']));
        dnap_log("Feed: {$feed_url}");

        // Forza un fetch fresco ad ogni run: la cache SimplePie (default 12h) può
        // diventare stantia se WP-Cron non gira regolarmente, bloccando il feed
        // sugli stessi articoli già importati. Con 30 minuti, ogni esecuzione oraria
        // ottiene dati aggiornati dal feed RSS remoto.
        $short_feed_cache = fn() => 30 * MINUTE_IN_SECONDS;
        // Cap SimplePie HTTP timeout so a single slow feed can't exhaust PHP max_execution_time.
        $set_feed_timeout = function($feed) { $feed->set_timeout(10); };
        add_filter( 'wp_feed_cache_transient_lifetime', $short_feed_cache, 99 );
        add_action( 'wp_feed_options', $set_feed_timeout );
        $rss = fetch_feed($feed_url);
        remove_action( 'wp_feed_options', $set_feed_timeout );
        remove_filter( 'wp_feed_cache_transient_lifetime', $short_feed_cache, 99 );
        if (is_wp_error($rss)) {
            dnap_log("ERRORE feed: {$feed_url} — " . $rss->get_error_message());
            $total_errors++;
            continue;
        }

        try {
            $items = $rss->get_items(0, 15);
        } catch (\Throwable $e) {
            dnap_log("ERRORE get_items ({$feed_url}): " . $e->getMessage());
            $total_errors++;
            continue;
        }
        if (empty($items)) {
            dnap_log("Feed vuoto: {$feed_url}");
            continue;
        }

        foreach ($items as $item) {
            try {

            if ($total_imported >= $run_limit) break;

            $source_url = esc_url_raw($item->get_permalink());
            $title_raw  = sanitize_text_field($item->get_title());
            $feed_text  = dnap_get_item_text($item);

            // Estrai pubDate dal feed item (SimplePie). Alcuni feed (es. Google News)
            // non espongono la data in modo affidabile → accettiamo l'articolo.
            $item_pubdate    = $item->get_date('Y-m-d H:i:s');
            $item_pubdate_ts = $item_pubdate ? strtotime($item_pubdate) : null;
            if (!$item_pubdate_ts) {
                dnap_log("Nessuna pubDate nel feed item: {$title_raw}");
            }

            // Filtro freschezza: scarta articoli più vecchi di DNAP_MAX_ARTICLE_AGE_DAYS.
            // Eseguito PRIMA della chiamata Claude per risparmiare token.
            if ($item_pubdate_ts !== null) {
                $age_days = (time() - $item_pubdate_ts) / DAY_IN_SECONDS;
                if ($age_days > DNAP_MAX_ARTICLE_AGE_DAYS) {
                    dnap_log(sprintf(
                        "⏭ Articolo troppo vecchio (%.1f giorni): %s",
                        $age_days,
                        $title_raw
                    ));
                    $total_skipped++;
                    continue;
                }
            }

            // Risolvi URL reale: prima base64 Google News, poi fallback metadati item
            $resolved = dnap_resolve_google_news_url( $item, $source_url );
            if ( ! empty( $resolved['skip'] ) ) {
                $total_skipped++;
                continue;
            }
            $source_url = $resolved['url'];

            // Scraping meta (og:description, og:image, canonical)
            $meta = dnap_scrape_meta($source_url);

            // Use the canonical URL returned by scraping (resolves Google News
            // redirects). This ensures _source_url is saved as the real article
            // URL, which is essential for future URL-based deduplication.
            if (!empty($meta['canonical'])
                && filter_var($meta['canonical'], FILTER_VALIDATE_URL)
                && strpos(parse_url($meta['canonical'], PHP_URL_HOST) ?? '', 'google.com') === false
                && $meta['canonical'] !== $source_url) {
                dnap_log("URL aggiornato da canonical: {$source_url} → {$meta['canonical']}");
                $source_url = $meta['canonical'];
            }

            // Testo per GPT: usa og:description se disponibile, altrimenti testo feed
            $ai_text = !empty($meta['description']) ? $meta['description'] : $feed_text;
            if (empty(trim($ai_text))) {
                $ai_text = $title_raw;
            }
            $ai_text = mb_substr($ai_text, 0, 800);

            $hash = md5($source_url . $ai_text);

            if (dnap_source_exists($source_url, $hash, $title_raw)) {
                $total_skipped++;
                continue;
            }

            // Filtra articoli non locali (PRIMA di chiamare GPT)
            // Controlla solo titolo + primi 200 chars del body + og:description
            if (!dnap_is_local_content($title_raw . ' ' . mb_substr($feed_text, 0, 200) . ' ' . $meta['description'])) {
                dnap_log("⏭ Saltato (non locale): {$title_raw}");
                $total_skipped++;
                continue;
            }

            dnap_log("GPT: {$title_raw}");

            $rewritten = dnap_gpt_rewrite($ai_text, $title_raw, $meta['canonical']);
            if (!$rewritten) {
                dnap_log("ERRORE GPT: {$title_raw}");
                $total_errors++;
                continue;
            }

            // Skip if Claude determined the article is not relevant to Litorale Domizio
            if (!empty($rewritten['skip']) && $rewritten['skip'] === true) {
                dnap_log("⏭️  Skip (non pertinente Litorale Domizio): {$title_raw}");
                $total_skipped++;
                continue;
            }

            // ── Layer 1.5: post-rewrite title similarity (Bug #4) ────────
            // Layer 1 pre-Claude catches wire copies; this catches same-fact
            // coverage with different phrasing rewritten by Claude into
            // stylistically similar titles.
            $rewritten_title = $rewritten['title'] ?? '';
            if ($rewritten_title !== '') {
                $recent = get_posts([
                    'post_type'      => 'post',
                    'post_status'    => 'publish',
                    'posts_per_page' => 100,
                    'date_query'     => [['after' => '12 hours ago']],
                    'fields'         => 'ids',
                    'no_found_rows'  => true,
                ]);
                foreach ($recent as $rid) {
                    $existing_title = get_the_title($rid);
                    if ($existing_title === '') continue;
                    $pct = 0.0;
                    similar_text(
                        mb_strtolower($rewritten_title),
                        mb_strtolower($existing_title),
                        $pct
                    );
                    if ($pct >= 75) {
                        dnap_log(sprintf(
                            '⏭ Layer 1.5 dedup: "%s" simile al %0.1f%% a post %d "%s"',
                            $rewritten_title, $pct, $rid, $existing_title
                        ));
                        $total_skipped++;
                        continue 2; // skip to next RSS item
                    }
                }
            }

            // ── Layer 2: multi-layer event dedup (Bug #4 + Bug B) ────────
            // 2a: same entity + >=2 keyword overlap in 30 days (long-running
            //     stories: inchieste, processi, scomparse).
            // 2b: same entity in 72h (keywords may drift across arc).
            // 2c: keyword overlap + city/entity in 6h (entity-null cases,
            //     Bug #4 fix — preserved as primary for fresh events).
            // Legacy fallback preserved for posts pre-event_keywords still
            // within the 6h window.
            $evt_keywords = is_array($rewritten['event_keywords'] ?? null) ? $rewritten['event_keywords'] : [];
            $evt_entity   = !empty($rewritten['event_entity']) ? sanitize_text_field(strtolower(trim($rewritten['event_entity']))) : '';
            $evt_city     = (!empty($rewritten['cities']) && is_array($rewritten['cities']))
                ? sanitize_key(reset($rewritten['cities']))
                : '';

            // ── Layer 2a: same entity + >=2 keyword overlap, 30 days ──
            if ($evt_entity !== '' && mb_strlen($evt_entity) >= 3 && count($evt_keywords) >= 2) {
                $same_entity_30d = get_posts([
                    'post_type'      => 'post',
                    'post_status'    => 'publish',
                    'posts_per_page' => 20,
                    'date_query'     => [['after' => '30 days ago']],
                    'fields'         => 'ids',
                    'no_found_rows'  => true,
                    'meta_key'       => '_dnap_event_entity',
                    'meta_value'     => $evt_entity,
                ]);
                foreach ($same_entity_30d as $cid) {
                    $existing_kw = get_post_meta($cid, '_dnap_event_keywords', true);
                    if (!is_array($existing_kw) || empty($existing_kw)) continue;
                    $overlap = count(array_intersect($evt_keywords, $existing_kw));
                    if ($overlap >= 2) {
                        dnap_log(sprintf(
                            '⏭ Dedup entità+keyword 30gg (overlap=%d, entity=%s): "%s" duplica post %d',
                            $overlap, $evt_entity,
                            $rewritten['title'] ?? '(no title)', $cid
                        ));
                        $total_skipped++;
                        continue 2; // next RSS item (exit inner foreach + items foreach)
                    }
                }
            }

            // ── Layer 2b: same entity, 72h (keywords may drift) ──
            // No inner loop here, so `continue` (level 1) targets the items
            // foreach directly — `continue 2` would escape to the feeds loop.
            if ($evt_entity !== '' && mb_strlen($evt_entity) >= 3) {
                $same_entity_72h = get_posts([
                    'post_type'      => 'post',
                    'post_status'    => 'publish',
                    'posts_per_page' => 1,
                    'date_query'     => [['after' => '72 hours ago']],
                    'fields'         => 'ids',
                    'no_found_rows'  => true,
                    'meta_key'       => '_dnap_event_entity',
                    'meta_value'     => $evt_entity,
                ]);
                if (!empty($same_entity_72h)) {
                    dnap_log(sprintf(
                        '⏭ Dedup entità 72h (entity=%s): "%s" duplica post %d',
                        $evt_entity,
                        $rewritten['title'] ?? '(no title)',
                        $same_entity_72h[0]
                    ));
                    $total_skipped++;
                    continue; // next RSS item
                }
            }

            // ── Layer 2c: keyword overlap + city/entity, 6h (Bug #4) ──
            if (!empty($evt_keywords) && ($evt_city !== '' || $evt_entity !== '')) {
                $meta_query = ['relation' => 'OR'];
                if ($evt_city !== '')   $meta_query[] = ['key' => '_dnap_event_city',   'value' => $evt_city];
                if ($evt_entity !== '') $meta_query[] = ['key' => '_dnap_event_entity', 'value' => $evt_entity];

                $candidates = get_posts([
                    'post_type'      => 'post',
                    'post_status'    => 'publish',
                    'posts_per_page' => 50,
                    'date_query'     => [['after' => '6 hours ago']],
                    'fields'         => 'ids',
                    'no_found_rows'  => true,
                    'meta_query'     => $meta_query,
                ]);

                foreach ($candidates as $cid) {
                    $existing_kw = get_post_meta($cid, '_dnap_event_keywords', true);
                    if (!is_array($existing_kw) || empty($existing_kw)) continue;
                    $overlap = count(array_intersect($evt_keywords, $existing_kw));
                    if ($overlap >= 2) {
                        dnap_log(sprintf(
                            '⏭ Dedup evento (keyword overlap=%d): "%s" duplica post %d',
                            $overlap, $rewritten['title'], $cid
                        ));
                        $total_skipped++;
                        continue 2; // skip to next RSS item
                    }
                }
            } elseif (!empty($rewritten['event_type'])) {
                // Legacy fallback (B4/B4.2) — preserved for backward compat
                // with posts published before event_keywords existed.
                $ev_type = sanitize_key($rewritten['event_type']);

                $dedup_key_field = null;
                $dedup_key_value = null;

                if (!empty($rewritten['event_entity'])) {
                    $dedup_key_field = '_dnap_event_entity';
                    $dedup_key_value = sanitize_text_field(strtolower(trim($rewritten['event_entity'])));
                } elseif (!empty($rewritten['cities']) && is_array($rewritten['cities'])) {
                    $first_city = sanitize_key(reset($rewritten['cities']));
                    if ($first_city) {
                        $dedup_key_field = '_dnap_event_city';
                        $dedup_key_value = $first_city;
                    }
                }

                if ($dedup_key_field !== null) {
                    $dup = get_posts([
                        'post_type'      => 'post',
                        'post_status'    => 'publish',
                        'posts_per_page' => 1,
                        'fields'         => 'ids',
                        'no_found_rows'  => true,
                        'date_query'     => [
                            ['after' => '6 hours ago', 'inclusive' => true],
                        ],
                        'meta_query'     => [
                            'relation' => 'AND',
                            ['key' => '_dnap_event_type', 'value' => $ev_type, 'compare' => '='],
                            ['key' => $dedup_key_field,   'value' => $dedup_key_value, 'compare' => '='],
                        ],
                    ]);

                    if (!empty($dup)) {
                        $existing_id    = $dup[0];
                        $existing_title = get_the_title($existing_id);
                        $key_label      = ($dedup_key_field === '_dnap_event_entity') ? 'entity' : 'city';
                        dnap_log("⏭ Dedup evento (legacy): '{$ev_type}' + {$key_label}='{$dedup_key_value}' già pubblicato nelle ultime 6h (post {$existing_id}: {$existing_title})");
                        $total_skipped++;
                        continue;
                    }
                }
            }

            $word_count = str_word_count(strip_tags($rewritten['content']));
            if ($word_count < 80) {
                dnap_log("Troppo corto ({$word_count} parole): {$title_raw}");
                $total_skipped++;
                continue;
            }

            // Slug ottimizzato
            $slug = !empty($rewritten['slug'])
                ? sanitize_title($rewritten['slug'])
                : sanitize_title($rewritten['title']);

            $content = wpautop($rewritten['content']);

            $post_id = wp_insert_post([
                'post_title'   => wp_slash(sanitize_text_field($rewritten['title'])),
                'post_content' => wp_slash($content),
                'post_status'  => 'publish',
                'post_author'  => dnap_get_redazione_author(),
                'post_excerpt' => wp_slash(sanitize_textarea_field($rewritten['excerpt'])),
                'post_name'    => $slug,
            ]);

            if (is_wp_error($post_id)) {
                dnap_log("ERRORE inserimento: " . $post_id->get_error_message());
                $total_errors++;
                continue;
            }

            update_post_meta($post_id, '_source_url',      $source_url);
            update_post_meta($post_id, '_source_hash',     $hash);
            update_post_meta($post_id, '_meta_description', sanitize_textarea_field($rewritten['meta_description'] ?? ''));

            if ($item_pubdate_ts !== null) {
                update_post_meta($post_id, '_source_pubdate',
                    date('Y-m-d H:i:s', $item_pubdate_ts));
            }

            if (!empty($rewritten['social_caption'])) {
                update_post_meta($post_id, '_dnap_social_caption', sanitize_text_field($rewritten['social_caption']));
            }
            if (!empty($rewritten['event_type'])) {
                update_post_meta($post_id, '_dnap_event_type', sanitize_key($rewritten['event_type']));
            }
            if (!empty($rewritten['event_entity'])) {
                update_post_meta($post_id, '_dnap_event_entity', sanitize_text_field(strtolower(trim($rewritten['event_entity']))));
            }
            // Always persist city when known — used by Layer 2 keyword dedup
            // as candidate-pool filter, independent of entity presence.
            if (!empty($rewritten['cities']) && is_array($rewritten['cities'])) {
                $first_city = sanitize_key(reset($rewritten['cities']));
                if ($first_city) {
                    update_post_meta($post_id, '_dnap_event_city', $first_city);
                }
            }
            if (!empty($rewritten['event_keywords']) && is_array($rewritten['event_keywords'])) {
                update_post_meta($post_id, '_dnap_event_keywords', $rewritten['event_keywords']);
            }
            if (!empty($rewritten['image_prompt'])) {
                update_post_meta($post_id, '_dnap_image_prompt', sanitize_textarea_field($rewritten['image_prompt']));
            }
            if (!empty($rewritten['image_symbol'])) {
                update_post_meta($post_id, '_dnap_image_symbol', sanitize_text_field($rewritten['image_symbol']));
            }

            // Post in evidenza (sticky) per soggetti VIP
            $subject_text = $title_raw . ' ' . $feed_text . ' ' . $meta['description'];
            if (dnap_is_featured_subject($subject_text)) {
                update_post_meta($post_id, '_is_sticky', 1);
                stick_post($post_id);
                dnap_log("⭐ Post in evidenza: {$rewritten['title']}");
            }

            // ── CATEGORIA ────────────────────────────────────────────
            $assigned_cat = false;
            if (!empty($rewritten['category'])) {
                $cat_obj = get_category_by_slug($rewritten['category']);
                if ($cat_obj) {
                    wp_set_post_categories($post_id, [$cat_obj->term_id]);
                    dnap_log("Categoria: {$cat_obj->name}");
                    $assigned_cat = true;
                }
            }
            if (!$assigned_cat && !empty($feed['cat_id'])) {
                $cat_obj = get_category(intval($feed['cat_id']));
                if ($cat_obj && !is_wp_error($cat_obj)) {
                    wp_set_post_categories($post_id, [$cat_obj->term_id]);
                }
            }

            // Immagine in evidenza: priorità og:image dallo scraping.
            // DEVE essere chiamata dopo wp_set_post_categories() perché il
            // fallback Unsplash usa get_the_category() per scegliere la keyword.
            $image_url = !empty($meta['image']) ? $meta['image'] : '';
            dnap_set_featured_image($post_id, $rewritten['title'], $item, $source_url, $image_url);

            // ── CITTÀ ────────────────────────────────────────────────
            // Merge keyword scan (titolo + og:description) con GPT cities.
            // Keyword match ha priorità di ordine, GPT aggiunge slug mancanti.
            $city_text     = $title_raw . ' ' . $meta['description'];
            $keyword_slugs = dnap_get_cities_from_text($city_text);
            $gpt_slugs     = is_array($rewritten['cities'] ?? null) ? $rewritten['cities'] : [];

            $merged_slugs = $keyword_slugs;
            foreach ($gpt_slugs as $slug) {
                if ($slug !== '' && !in_array($slug, $merged_slugs, true)) {
                    $merged_slugs[] = $slug;
                }
            }

            if ($merged_slugs) {
                wp_set_object_terms($post_id, $merged_slugs, 'city');
                dnap_log("Città: " . implode(', ', $merged_slugs));
            } elseif (!empty($feed['city_slug'])) {
                wp_set_object_terms($post_id, [sanitize_text_field($feed['city_slug'])], 'city');
            }

            // ── TAG ──────────────────────────────────────────────────
            if (!empty($rewritten['tags']) && is_array($rewritten['tags'])) {
                $clean_tags = array_map('sanitize_text_field', array_slice($rewritten['tags'], 0, 5));
                wp_set_post_tags($post_id, $clean_tags);
            }

            dnap_log("✅ Pubblicato [{$post_id}]: {$rewritten['title']}");
            $total_imported++;
            } catch (\Throwable $e) {
                $item_url = isset($source_url) ? $source_url : $feed_url;
                dnap_log("ERRORE item ({$item_url}): " . $e->getMessage());
                $total_errors++;
                continue;
            }
        }
    }

    dnap_log("Completato — Importati: {$total_imported} | Saltati: {$total_skipped} | Errori: {$total_errors}");

    update_option('dnap_last_import', [
        'time'     => current_time('mysql'),
        'imported' => $total_imported,
        'skipped'  => $total_skipped,
        'errors'   => $total_errors,
    ]);

    delete_option('dnap_import_lock');
}

function dnap_send_telegram($post_id) {
  if (wp_is_post_revision($post_id)) return;
  if (get_post_status($post_id) !== 'publish') return;

  // Check and set atomically to prevent duplicate dispatch
  $already = get_post_meta($post_id, '_dnap_telegram_sent', true);
  if ($already) {
      dnap_log("Telegram già inviato per post {$post_id} — skip");
      return;
  }
  // Mark as pending immediately (before HTTP call)
  update_post_meta($post_id, '_dnap_telegram_sent', 'pending');

  $token   = get_option('dnap_telegram_token', '');
  $channel = get_option('dnap_telegram_channel', '');
  if (!$token || !$channel) {
    delete_post_meta($post_id, '_dnap_telegram_sent');
    return;
  }

  $url            = get_permalink($post_id);
  $social_caption = get_post_meta($post_id, '_dnap_social_caption', true);

  // Build message: social_caption (if present) + link on its own line.
  // Telegram renders the preview card automatically from Open Graph tags.
  if (!empty($social_caption)) {
    // Telegram HTML parse_mode decodes ONLY &lt; &gt; &amp;.
    // Apostrophes/quotes must remain raw or they render as visible
    // entity text (&apos; / &quot;). Order matters: & first, else
    // &lt; becomes &amp;lt;.
    $caption_escaped = str_replace(
      ['&', '<', '>'],
      ['&amp;', '&lt;', '&gt;'],
      $social_caption
    );
    $text = "💬 {$caption_escaped}\n\n<a href=\"{$url}\">Leggi l'articolo</a>";
  } else {
    $text = "<a href=\"{$url}\">Leggi l'articolo</a>";
  }

  $endpoint = "https://api.telegram.org/bot{$token}/sendMessage";
  $payload  = [
    'chat_id'                  => $channel,
    'text'                     => $text,
    'parse_mode'               => 'HTML',
    'disable_web_page_preview' => false,
  ];

  $response = wp_remote_post($endpoint, [
    'body'    => json_encode($payload),
    'headers' => ['Content-Type' => 'application/json'],
    'timeout' => 10,
  ]);

  if (is_wp_error($response)) {
    $error = $response->get_error_message();
    delete_post_meta($post_id, '_dnap_telegram_sent');
    dnap_log("Telegram fallito per post {$post_id}: {$error}");
    return;
  }

  $status = wp_remote_retrieve_response_code($response);
  if ($status !== 200) {
    $body = wp_remote_retrieve_body($response);
    $error = "HTTP {$status}: " . mb_substr($body, 0, 200);
    delete_post_meta($post_id, '_dnap_telegram_sent');
    dnap_log("Telegram fallito per post {$post_id}: {$error}");
    return;
  }

  update_post_meta($post_id, '_dnap_telegram_sent', '1');
  if (!empty($social_caption)) {
    dnap_log("Telegram inviato con social_caption (post {$post_id})");
  } else {
    dnap_log("Telegram inviato (post {$post_id})");
  }
}
// Async Telegram dispatch: schedule a single WP-Cron event instead of sending
// inline on publish. Keeps the import loop fast (no ~10s HTTP timeout stacking
// inside the import lock) and the 10-second delay gives taxonomy terms time
// to commit on bulk imports.
add_action( 'transition_post_status', 'dnap_schedule_telegram_dispatch', 10, 3 );
function dnap_schedule_telegram_dispatch( $new_status, $old_status, $post ) {
    if ( $new_status !== 'publish' || $old_status === 'publish' ) return;
    if ( $post->post_type !== 'post' ) return;
    if ( get_post_meta( $post->ID, '_dnap_telegram_sent', true ) ) return;
    wp_schedule_single_event( time() + 10, 'dnap_dispatch_telegram', [ $post->ID ] );
}
add_action( 'dnap_dispatch_telegram', 'dnap_send_telegram' );
