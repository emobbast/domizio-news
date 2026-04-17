<?php
if (!defined('ABSPATH')) exit;

/* ============================================================
   CHIAMATA API OPENAI
   ============================================================ */
function dnap_call_gpt(string $prompt, int $max_tokens = 1200, float $temperature = 0.72) {

    $api_key = get_option('dnap_api_key');
    if (!$api_key) {
        dnap_log('API Key OpenAI non configurata.');
        return false;
    }

    $body = wp_json_encode([
        'model'       => 'gpt-4.1-mini',
        'max_tokens'  => $max_tokens,
        'temperature' => $temperature,
        'messages'    => [
            [
                'role'    => 'system',
                'content' => 'Sei un giornalista italiano esperto di notizie locali della Campania, specializzato nel litorale domizio (provincia di Caserta). Scrivi sempre in italiano corretto, stile giornalistico. Rispondi SOLO con JSON valido, senza markdown, senza ```json, senza testo aggiuntivo.',
            ],
            [
                'role'    => 'user',
                'content' => $prompt,
            ],
        ],
    ]);

    $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
        'headers' => [
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type'  => 'application/json',
        ],
        'body'    => $body,
        'timeout' => 60,
    ]);

    if (is_wp_error($response)) {
        dnap_log('Errore rete GPT: ' . $response->get_error_message());
        return false;
    }

    $status = wp_remote_retrieve_response_code($response);
    $raw    = wp_remote_retrieve_body($response);

    if ($status !== 200) {
        $decoded = json_decode($raw, true);
        $err     = $decoded['error']['message'] ?? mb_substr($raw, 0, 200);
        dnap_log("GPT HTTP {$status}: {$err}");
        return false;
    }

    $decoded = json_decode($raw, true);
    $content = $decoded['choices'][0]['message']['content'] ?? '';
    if (empty($content)) {
        dnap_log('GPT risposta vuota.');
        return false;
    }

    return $content;
}

/* ============================================================
   RECUPERA LISTE DA WORDPRESS
   ============================================================ */
function dnap_get_available_categories(): array {
    $cats = get_categories(['hide_empty' => false]);
    return array_map(fn($c) => $c->slug, $cats);
}

function dnap_get_available_cities(): array {
    $terms = get_terms(['taxonomy' => 'city', 'hide_empty' => false]);
    if (is_wp_error($terms) || empty($terms)) return [];
    return array_map(fn($t) => $t->slug, $terms);
}

function dnap_get_city_labels(): string {
    $terms = get_terms(['taxonomy' => 'city', 'hide_empty' => false]);
    if (is_wp_error($terms) || empty($terms)) {
        return 'mondragone (Mondragone), castel-volturno (Castel Volturno), baia-domizia (Baia Domizia), sessa-aurunca (Sessa Aurunca), cellole (Cellole), falciano-del-massico (Falciano del Massico), carinola (Carinola)';
    }
    $labels = [];
    foreach ($terms as $term) {
        $labels[] = $term->slug . ' (' . $term->name . ')';
    }
    return implode(', ', $labels);
}

/* ============================================================
   RISCRITTURA GPT v7
   Genera: titolo, body, excerpt, meta_description, slug,
           category, cities, tags
   ============================================================ */
function dnap_gpt_rewrite(string $text, string $original_title = '', string $source_url = '') {

    $text_input = mb_substr($text, 0, 800);
    $categories = dnap_get_available_categories();
    $cities     = dnap_get_available_cities();

    $cat_list  = !empty($categories) ? implode(', ', $categories) : 'cronaca, sport, politica, economia-lavoro, ambiente-mare, eventi-cultura, salute, incidenti-sicurezza';
    $city_list = dnap_get_city_labels();

    $prompt = <<<PROMPT
Riscrivi questa notizia come articolo giornalistico originale. Cambia struttura narrativa rispetto alla fonte, aggiungi contesto locale.

Rispondi SOLO con questo JSON (niente altro):
{
  "title": "Titolo giornalistico breve e diretto, massimo 75 caratteri, mai troncare parole a metà, diverso dall'originale",
  "slug": "slug-url-ottimizzato-max-6-parole",
  "excerpt": "Sommario 1-2 frasi, max 160 caratteri",
  "meta_description": "Meta description per Google, 140-155 caratteri, includi parola chiave principale",
  "content": "Articolo completo, minimo 5 paragrafi, struttura diversa dalla fonte. Primo paragrafo: il fatto principale. Paragrafi 2-3: contesto e dettagli. Paragrafo 4: impatto locale sul litorale domizio / provincia di Caserta. Penultimo paragrafo: critical editorial comment in third person linking the news to the Litorale Domizio territory. NEVER use first person phrases like 'dal mio punto di vista', 'a mio avviso', 'ritengo', 'credo'. Write in authoritative editorial tone like 'La vicenda evidenzia...', 'L'episodio riaccende il dibattito su...', 'Il caso solleva interrogativi su...' The comment must read as a natural part of the article. Ultimo paragrafo: conclusione o prossimi sviluppi. Solo tag HTML: <p> e <strong>.",
  "category": "UNO SOLO tra: {$cat_list}",
  "cities": ["Use ONLY these exact slugs: {$city_list}. Return [] if the article does not mention a specific city in the Litorale Domizio area. Do NOT invent slugs."],
  "tags": ["3-5 tag pertinenti, singole parole o brevi frasi, no luoghi già nelle cities"]
}

Titolo originale: {$original_title}
Testo fonte:
{$text_input}
PROMPT;

    $raw = dnap_call_gpt($prompt, 1500, 0.72);
    if (!$raw) return false;

    $data = dnap_parse_gpt_json($raw);

    if (!$data || empty($data['title']) || empty($data['content'])) {
        dnap_log('JSON GPT non valido, retry semplificato…');
        $retry = 'Rispondi SOLO con JSON. Campi obbligatori: title (max 75 car, non troncare parole), slug, excerpt (max 160 car), meta_description (140-155 car), content (min 5 paragrafi con <p>, nel penultimo paragrafo includi un critical editorial comment in third person linking the news to the Litorale Domizio territory. NEVER use first person phrases like "dal mio punto di vista", "a mio avviso", "ritengo", "credo". Write in authoritative editorial tone like "La vicenda evidenzia...", "L\'episodio riaccende il dibattito su...", "Il caso solleva interrogativi su..." The comment must read as a natural part of the article.), category (uno tra: ' . $cat_list . '), cities (array di slug; Use ONLY these exact slugs: ' . $city_list . '. Return [] if the article does not mention a specific city in the Litorale Domizio area. Do NOT invent slugs.), tags (array 3-5 elementi).'
                 . "\nTitolo: {$original_title}\nTesto:\n{$text_input}";
        $raw2 = dnap_call_gpt($retry, 1200, 0.65);
        if (!$raw2) return false;
        $data = dnap_parse_gpt_json($raw2);
        if (!$data || empty($data['title'])) {
            dnap_log('JSON GPT non valido al retry: ' . mb_substr($raw2, 0, 200));
            return false;
        }
    }

    // ── Sanitizza ────────────────────────────────────────────────
    $t = wp_strip_all_tags(trim($data['title']));
    if (mb_strlen($t) > 75) {
        $t = mb_substr($t, 0, 75);
        $t = mb_substr($t, 0, mb_strrpos($t, ' '));
    }
    $data['title']            = $t;
    $data['slug']             = sanitize_title($data['slug'] ?? $data['title']);
    $data['excerpt']          = wp_strip_all_tags(mb_substr(trim($data['excerpt']), 0, 160));
    $data['meta_description'] = wp_strip_all_tags(mb_substr(trim($data['meta_description'] ?? ''), 0, 155));
    $data['content']          = wp_kses($data['content'], [
        'p'          => [],
        'strong'     => [],
        'em'         => [],
        'br'         => [],
        'h2'         => [],
        'h3'         => [],
        'ul'         => [],
        'ol'         => [],
        'li'         => [],
        'blockquote' => [],
    ]);

    // ── Categoria ────────────────────────────────────────────────
    $cat_raw = sanitize_title(trim($data['category'] ?? ''));
    $data['category'] = in_array($cat_raw, $categories) ? $cat_raw : '';

    // ── Città ────────────────────────────────────────────────────
    $raw_cities  = is_array($data['cities'] ?? null) ? $data['cities'] : [];
    $data['cities'] = [];
    foreach ($raw_cities as $c) {
        $slug = sanitize_title(trim($c));
        if (in_array($slug, $cities)) $data['cities'][] = $slug;
    }

    // ── Tag ──────────────────────────────────────────────────────
    $raw_tags = is_array($data['tags'] ?? null) ? $data['tags'] : [];
    $data['tags'] = array_slice(array_map('sanitize_text_field', $raw_tags), 0, 5);

    if (empty($data['title'])) return false;

    return $data;
}

/* ============================================================
   PARSE JSON ROBUSTO
   ============================================================ */
function dnap_parse_gpt_json(string $raw): ?array {
    $clean = preg_replace('/^```(?:json)?\s*/i', '', trim($raw));
    $clean = preg_replace('/\s*```$/i', '', $clean);
    $clean = trim($clean);

    $data = json_decode($clean, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($data)) return $data;

    if (preg_match('/\{[\s\S]*\}/U', $clean, $m)) {
        $data = json_decode($m[0], true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($data)) return $data;
    }

    return null;
}
