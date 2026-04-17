<?php
if (!defined('ABSPATH')) exit;

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

    // 3. Titolo simile negli ultimi 7 giorni (similar_text ≥ 80%)
    $recent = get_posts([
        'post_type'      => 'post',
        'posts_per_page' => 100,
        'fields'         => 'ids',
        'date_query'     => [['after' => '7 days ago']],
    ]);

    foreach ($recent as $pid) {
        $existing_title = get_the_title($pid);
        similar_text(
            mb_strtolower($new_title),
            mb_strtolower($existing_title),
            $pct
        );
        if ($pct >= 80) {
            dnap_log("Duplicato titolo ({$pct}%): \"{$new_title}\" ~ \"{$existing_title}\"");
            return true;
        }
    }

    return false;
}

/* ============================================================
   INSERT AD SLOT — posizioni configurabili
   ============================================================ */
function dnap_insert_ad_slots(string $content): string {
    $pos2 = get_option('dnap_ad_pos_2', true);
    $pos4 = get_option('dnap_ad_pos_4', true);

    $paragraphs = explode('</p>', $content);
    $total = count($paragraphs);

    if ($pos2 && $total > 2) {
        $paragraphs[1] .= '<div class="dn-ad-slot dn-ad-inline" aria-label="Pubblicità"></div>';
    }
    if ($pos4 && $total > 4) {
        $paragraphs[3] .= '<div class="dn-ad-slot dn-ad-inline" aria-label="Pubblicità"></div>';
    }

    return implode('</p>', $paragraphs);
}

/* ============================================================
   RISOLVI URL GOOGLE NEWS (no sslverify false)
   ============================================================ */
function dnap_resolve_google_url(string $url): string {
    if (strpos($url, 'news.google.com') === false) return $url;

    $response = wp_remote_get($url, [
        'timeout'     => 20,
        'redirection' => 10,
        'user-agent'  => 'Mozilla/5.0 (compatible; DomizioNewsBot/1.0)',
        'headers'     => ['Accept-Language' => 'it-IT,it;q=0.9'],
    ]);

    if (is_wp_error($response)) {
        dnap_log('Errore risoluzione Google URL: ' . $response->get_error_message());
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
   RISOLUZIONE URL REALE DA ITEM GOOGLE NEWS
   Tenta di ricavare l'URL dell'articolo originale dai metadati
   dell'item SimplePie, senza effettuare richieste HTTP.
   ============================================================ */
function dnap_resolve_google_news_url( $item, string $source_url ): string {

    if ( strpos( $source_url, 'news.google.com' ) === false ) return $source_url;

    // 1. Enclosure link
    $enc = $item->get_enclosure();
    if ( $enc ) {
        $enc_link = $enc->get_link();
        if ( $enc_link && strpos( $enc_link, 'google.com' ) === false && filter_var( $enc_link, FILTER_VALIDATE_URL ) ) {
            dnap_log( "Google News → enclosure: {$enc_link}" );
            return esc_url_raw( $enc_link );
        }
    }

    // 2. Tutti i link dell'item — primo non-google
    $links = $item->get_links() ?? [];
    foreach ( $links as $link ) {
        if ( $link && strpos( $link, 'google.com' ) === false && filter_var( $link, FILTER_VALIDATE_URL ) ) {
            dnap_log( "Google News → link: {$link}" );
            return esc_url_raw( $link );
        }
    }

    // 3. Tag <a href="..."> nella description/content dell'item
    $content = $item->get_content() ?: ( $item->get_description() ?: '' );
    if ( $content && preg_match_all( '/<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>/i', $content, $matches ) ) {
        foreach ( $matches[1] as $href ) {
            $href = html_entity_decode( $href );
            if ( strpos( $href, 'google.com' ) === false && filter_var( $href, FILTER_VALIDATE_URL ) ) {
                dnap_log( "Google News → href in content: {$href}" );
                return esc_url_raw( $href );
            }
        }
    }

    // 4. Fallback: URL originale (dnap_scrape_meta gestirà il redirect HTTP)
    return $source_url;
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
            'timeout'     => 20,
            'redirection' => 5,
            'user-agent'  => 'Mozilla/5.0 (compatible; DomizioNewsBot/1.0)',
            'headers'     => ['Accept-Language' => 'it-IT,it;q=0.9'],
        ]);
        if (!is_wp_error($gr)) {
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
        'timeout'    => 20,
        'user-agent' => 'Mozilla/5.0 (compatible; DomizioNewsBot/1.0)',
        'headers'    => ['Accept-Language' => 'it-IT,it;q=0.9'],
    ]);

    if (is_wp_error($response)) {
        dnap_log('Errore scraping meta: ' . $response->get_error_message() . ' — ' . $url);
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
    ];
    $lower = mb_strtolower($text);
    foreach ($keywords as $kw) {
        if (strpos($lower, $kw) !== false) return true;
    }
    return false;
}

/* ============================================================
   RILEVAMENTO CITTÀ DAL TESTO
   Scorre il testo cercando keyword geografiche (ordinate dalla
   più lunga alla più corta per evitare match parziali prematuri,
   es. "falciano" prima di "falciano del massico").
   Restituisce array di slug città univoci.
   ============================================================ */
function dnap_get_cities_from_text(string $text): array {
    $map = [
        'giovanni zannini'     => 'mondragone',
        'zannini'              => 'mondragone',
        'falciano del massico' => 'falciano-del-massico',
        'villaggio coppola'    => 'castel-volturno',
        'maiorano di monte'    => 'carinola',
        'piedimonte massicano' => 'sessa-aurunca',
        'castel volturno'      => 'castel-volturno',
        'borgo centore'        => 'cellole',
        'sessa aurunca'        => 'sessa-aurunca',
        'baia domizia'         => 'baia-domizia',
        'baia felice'          => 'sessa-aurunca',
        'baia verde'           => 'castel-volturno',
        'san limato'           => 'cellole',
        'mondragone'           => 'mondragone',
        'pinetamare'           => 'castel-volturno',
        'pescopagano'          => 'castel-volturno',
        'ischitella'           => 'castel-volturno',
        'ventaroli'            => 'carinola',
        'falciano'             => 'falciano-del-massico',
        'carinola'             => 'carinola',
        'cellole'              => 'cellole',
        'varano'               => 'carinola',
    ];

    $lower = mb_strtolower($text);
    $slugs = [];
    foreach ($map as $keyword => $slug) {
        if (strpos($lower, $keyword) !== false && !in_array($slug, $slugs, true)) {
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

    // Lock: evita run paralleli
    if (get_transient('dnap_running')) {
        dnap_log('⏸ Import già in corso, skip.');
        return;
    }
    set_transient('dnap_running', 1, 10 * MINUTE_IN_SECONDS);

    $feeds = get_option('dnap_feeds', []);
    if (empty($feeds)) {
        dnap_log('Nessun feed configurato.');
        delete_transient('dnap_running');
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
        add_filter( 'wp_feed_cache_transient_lifetime', $short_feed_cache, 99 );
        $rss = fetch_feed($feed_url);
        remove_filter( 'wp_feed_cache_transient_lifetime', $short_feed_cache, 99 );
        if (is_wp_error($rss)) {
            dnap_log("ERRORE feed: {$feed_url} — " . $rss->get_error_message());
            $total_errors++;
            continue;
        }

        $items = $rss->get_items(0, 15);
        if (empty($items)) {
            dnap_log("Feed vuoto: {$feed_url}");
            continue;
        }

        foreach ($items as $item) {

            if ($total_imported >= $run_limit) break;

            $source_url = esc_url_raw($item->get_permalink());
            $title_raw  = sanitize_text_field($item->get_title());
            $feed_text  = dnap_get_item_text($item);

            // Risolvi URL reale da metadati item (enclosure, links, href in content)
            $source_url = dnap_resolve_google_news_url( $item, $source_url );

            // Scraping meta (og:description, og:image, canonical)
            $meta = dnap_scrape_meta($source_url);

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
            $content = dnap_insert_ad_slots($content);

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
            // Solo titolo + og:description per evitare falsi match dal corpo della fonte
            $city_text  = $title_raw . ' ' . $meta['description'];
            $city_slugs = dnap_get_cities_from_text($city_text);
            if ($city_slugs) {
                wp_set_object_terms($post_id, $city_slugs, 'city');
                dnap_log("Città: " . implode(', ', $city_slugs));
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
        }
    }

    dnap_log("Completato — Importati: {$total_imported} | Saltati: {$total_skipped} | Errori: {$total_errors}");

    update_option('dnap_last_import', [
        'time'     => current_time('mysql'),
        'imported' => $total_imported,
        'skipped'  => $total_skipped,
        'errors'   => $total_errors,
    ]);

    delete_transient('dnap_running');
}

function dnap_send_telegram($post_id) {
  if (wp_is_post_revision($post_id)) return;
  if (get_post_status($post_id) !== 'publish') return;
  if (get_post_meta($post_id, '_dnap_telegram_sent', true)) return;

  $token   = get_option('dnap_telegram_token', '');
  $channel = get_option('dnap_telegram_channel', '');
  if (!$token || !$channel) return;

  $post    = get_post($post_id);
  $title   = html_entity_decode(get_the_title($post_id), ENT_QUOTES, 'UTF-8');
  $excerpt = wp_trim_words(strip_tags($post->post_excerpt ?: $post->post_content), 30);
  $url     = get_permalink($post_id);
  $image   = get_the_post_thumbnail_url($post_id, 'large');

  $text = "*" . $title . "*\n\n" . $excerpt . "\n\n🔗 " . $url;

  if ($image) {
    $endpoint = "https://api.telegram.org/bot{$token}/sendPhoto";
    $payload  = [
      'chat_id'    => $channel,
      'photo'      => $image,
      'caption'    => $text,
      'parse_mode' => 'Markdown',
    ];
  } else {
    $endpoint = "https://api.telegram.org/bot{$token}/sendMessage";
    $payload  = [
      'chat_id'    => $channel,
      'text'       => $text,
      'parse_mode' => 'Markdown',
    ];
  }

  $response = wp_remote_post($endpoint, [
    'body'    => json_encode($payload),
    'headers' => ['Content-Type' => 'application/json'],
    'timeout' => 10,
  ]);

  if (!is_wp_error($response)) {
    update_post_meta($post_id, '_dnap_telegram_sent', true);
  }
}
add_action('publish_post', 'dnap_send_telegram');
