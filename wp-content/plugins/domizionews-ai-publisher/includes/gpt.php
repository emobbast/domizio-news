<?php
if (!defined('ABSPATH')) exit;

/* ============================================================
   ANTHROPIC CLAUDE API CALL
   ============================================================ */
function dnap_call_claude(string $user_prompt, string $system_prompt, int $max_tokens = 1500, float $temperature = 0.7) {

    $api_key = get_option('dnap_anthropic_key');
    if (!$api_key) {
        dnap_log('API Key Anthropic non configurata. Vai in Impostazioni → DNAP.');
        return false;
    }

    $body = wp_json_encode([
        'model'       => 'claude-haiku-4-5',
        'max_tokens'  => $max_tokens,
        'temperature' => $temperature,
        'system'      => $system_prompt,
        'messages'    => [
            [
                'role'    => 'user',
                'content' => $user_prompt,
            ],
        ],
    ]);

    $response = wp_remote_post('https://api.anthropic.com/v1/messages', [
        'headers' => [
            'x-api-key'         => $api_key,
            'anthropic-version' => '2023-06-01',
            'Content-Type'      => 'application/json',
        ],
        'body'    => $body,
        'timeout' => 60,
    ]);

    if (is_wp_error($response)) {
        dnap_log('Errore rete Claude: ' . $response->get_error_message());
        return false;
    }

    $status = wp_remote_retrieve_response_code($response);
    $raw    = wp_remote_retrieve_body($response);

    if ($status !== 200) {
        $decoded = json_decode($raw, true);
        $err     = $decoded['error']['message'] ?? mb_substr($raw, 0, 200);
        dnap_log("Claude HTTP {$status}: {$err}");
        return false;
    }

    $decoded = json_decode($raw, true);
    $content = $decoded['content'][0]['text'] ?? '';
    if (empty($content)) {
        dnap_log('Claude risposta vuota.');
        return false;
    }

    return $content;
}

/* ============================================================
   WORDPRESS TAXONOMY HELPERS
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
   AVAILABLE IMAGE SYMBOLS (for cronaca/politica fallback)
   ============================================================ */
function dnap_get_image_symbols(): array {
    return [
        'cronaca-carabinieri',
        'cronaca-polizia',
        'cronaca-ambulanza',
        'cronaca-vigili-fuoco',
        'cronaca-pronto-soccorso',
        'cronaca-sequestro',
        'giustizia-tribunale',
        'giustizia-bilancia',
        'giustizia-palazzo',
        'giustizia-manette',
        'politica-consiglio',
        'politica-urna',
        'politica-bandiera',
        'viabilita-strada',
        'viabilita-incidente',
        'viabilita-segnale',
        'territorio-litorale',
        'territorio-massico',
        'territorio-borgo',
        'territorio-mare',
    ];
}

/* ============================================================
   MAIN REWRITE FUNCTION — Claude Haiku editorial pipeline
   ============================================================ */
function dnap_gpt_rewrite(string $text, string $original_title = '', string $source_url = '') {

    $text_input = mb_substr($text, 0, 1200);
    $categories = dnap_get_available_categories();
    $cities     = dnap_get_available_cities();
    $symbols    = dnap_get_image_symbols();

    $cat_list    = !empty($categories) ? implode(', ', $categories) : 'cronaca, sport, politica, economia, ambiente, eventi, salute, incidenti-sicurezza';
    $city_list   = dnap_get_city_labels();
    $symbol_list = implode(', ', $symbols);

    $system_prompt = "Sei un redattore italiano di una testata locale del Litorale Domizio (provincia di Caserta). Scrivi in italiano corretto, con tono asciutto e professionale, come un cronista di redazione. Rispondi SEMPRE e SOLO con JSON valido, nessun testo aggiuntivo, nessun markdown, nessun ```json.";

    $user_prompt = <<<PROMPT
Sei un redattore di una testata giornalistica locale del Litorale Domizio.
Riscrivi l'articolo seguente in italiano, con tono asciutto e professionale, come farebbe un cronista esperto di una redazione locale.

=== ARTICOLO ORIGINALE ===
Titolo: {$original_title}
Testo: {$text_input}
=== FINE ARTICOLO ===

## VALUTAZIONE RILEVANZA (OBBLIGATORIA)

Analizza l'articolo e determina se il FATTO PRINCIPALE si svolge nel Litorale Domizio. Comuni e frazioni validi:

- Mondragone (Pescopagano, Pineta Nuova, Pineta Riviera, Baia Azzurra, Levagnole)
- Castel Volturno (Pinetamare, Villaggio Coppola, Ischitella, Baia Verde, Baia Felice)
- Cellole (Borgo Centore, San Limato)
- Baia Domizia
- Falciano del Massico
- Carinola (Ventaroli, Varano, Maiorano di Monte, Nocelleto, Casanova di Carinola)
- Sessa Aurunca (Piedimonte Massicano, Carano, Cascano, Rongolise, Fasani, Lauro, Corbara, Valogno, San Castrese, San Carlo, Ponte)

Restituisci "skip": true se:
- Il fatto principale accade in un comune NON elencato sopra (es. Cancello ed Arnone, Santa Maria Capua Vetere, Caserta città, Napoli, Aversa, Capua, Grazzanise)
- E il Litorale Domizio è menzionato solo di passaggio

Restituisci "skip": false se:
- L'evento accade nei comuni/frazioni sopra elencati
- Riguarda Giovanni Zannini o politici che rappresentano il territorio
- Riguarda decisioni della Regione Campania che impattano direttamente il Litorale Domizio

## STILE DI SCRITTURA

Scrivi come un cronista locale che racconta a lettori che conoscono il territorio. Obiettivo: un articolo naturale, non formulaico.

REGOLE STILISTICHE:
- Terza persona, mai prima persona
- Tono asciutto, informativo, senza enfasi retoriche
- Paragrafi di lunghezza variabile, alcuni brevi, alcuni più distesi
- Inserisci dettagli concreti quando disponibili (orari, luoghi specifici, nomi di strade, frazioni, istituzioni coinvolte)
- Non moralizzare, non aggiungere commenti editoriali a fine articolo
- Non inserire paragrafi obbligatori di "impatto locale" — il legame col territorio deve emergere naturalmente dai fatti
- Se la notizia è breve, l'articolo deve essere breve — non gonfiare
- Numero di paragrafi VARIABILE in base ai fatti: da 2 paragrafi per notizie brevi fino a 6 per notizie complesse
- Solo tag HTML <p> e <strong>

DIVIETI ASSOLUTI (espressioni da NON usare mai):
- "La vicenda evidenzia..."
- "L'episodio riaccende il dibattito..."
- "Il caso solleva interrogativi..."
- "È importante sottolineare che..."
- "Nel contesto locale..."
- "Questa notizia rappresenta..."
- "Rappresenta un importante..."
- "Sottolinea l'importanza..."
- "Le autorità locali sono chiamate..."
- Frasi di circostanza generiche che potrebbero adattarsi a qualsiasi articolo

## ESEMPI DI STILE TARGET

ESEMPIO 1 — Cronaca breve:
Titolo: Incidente sulla Domitiana a Mondragone, due feriti nello scontro tra auto
Content:
<p>Scontro tra due vetture ieri sera sulla Domitiana, all'altezza dello svincolo per Pescopagano. Due persone sono finite in ospedale, ma nessuna versa in condizioni gravi.</p>
<p>L'urto è avvenuto intorno alle 22, quando una Fiat Panda diretta verso Baia Domizia si è scontrata con una Renault Clio che usciva da una laterale. Sul posto sono intervenuti i carabinieri della stazione di Mondragone e un'ambulanza del 118.</p>
<p>I feriti, entrambi residenti nel comune, sono stati trasportati al pronto soccorso dell'ospedale di Sessa Aurunca. Per consentire i rilievi e la rimozione dei mezzi, il traffico è rimasto rallentato per oltre un'ora.</p>
<p>Non è la prima volta che si registrano incidenti in quel tratto, spesso segnalato dai residenti per la scarsa illuminazione.</p>

ESEMPIO 2 — Politica:
Titolo: Angela Parente subentra a Zannini in Consiglio regionale
Content:
<p>Il Consiglio regionale della Campania ha ufficializzato ieri il subentro di Angela Parente al consigliere Giovanni Zannini, sospeso dopo le misure cautelari disposte nell'inchiesta sulla corruzione.</p>
<p>Parente, prima dei non eletti nella lista di riferimento, prende posto tra i banchi dell'assemblea. Nelle prossime settimane sarà chiamata a confrontarsi con i dossier aperti sul territorio casertano, a partire dalle questioni del litorale domizio.</p>
<p>La sospensione di Zannini, figura di peso per Mondragone e l'area dell'alto casertano, lascia aperti diversi interrogativi sulla rappresentanza politica del territorio. Il consigliere resta comunque in carica, pur impossibilitato a esercitare le funzioni fino a nuova decisione.</p>
<p>A commentare il cambio è intervenuto anche il gruppo consiliare di opposizione, che ha definito la vicenda un momento delicato per l'intera provincia.</p>

## CAMPO IMMAGINE

Valuta il tipo di articolo e decidi:

SE categoria è "cronaca", "politica", "incidenti-sicurezza" o riguarda arresti, denunce, processi, aggressioni, incidenti stradali:
  → Imposta "image_prompt": null
  → Imposta "image_symbol": scegli UNO tra: {$symbol_list}
    Scegli il simbolo più pertinente al contenuto (es. "cronaca-carabinieri" per arresti, "viabilita-incidente" per incidenti stradali, "giustizia-tribunale" per processi).

SE categoria è "eventi", "ambiente", "salute", "sport", "economia":
  → Imposta "image_symbol": null
  → Imposta "image_prompt": un prompt in INGLESE per generazione AI con queste caratteristiche obbligatorie:
    - Inizia con "Editorial photograph of..."
    - Stile fotogiornalistico realistico, non cartoon
    - Ambientazione: "southern Italy", "Mediterranean coast", "Campania region"
    - No persone riconoscibili, no volti, no testo, no loghi
    - Aspetto: "16:9 landscape aspect ratio, realistic photojournalism style, no text overlays"
    - Dettagli specifici basati sul contenuto dell'articolo
    - Lunghezza: 40-60 parole totali

## CAMPO SOCIAL_CAPTION

Genera UNA riga di 1-2 frasi, tono colloquiale e diretto, adatta per essere pubblicata su un gruppo Facebook locale. Deve stimolare engagement (una domanda aperta, un'osservazione diretta, una reazione emotiva contenuta). NO hashtag. NO emoji. Lunghezza massima 200 caratteri.

Esempi di stile social_caption:
- "Altro incidente sulla Domitiana, questa volta allo svincolo per Pescopagano. Quando verrà messa in sicurezza?"
- "Novità in Regione che riguardano anche Mondragone."
- "Cambio alla guida del Consiglio regionale: ecco cosa succede dopo Zannini."

## OUTPUT JSON

Rispondi SOLO con JSON valido, nessun testo aggiuntivo, nessun markdown:

{
  "skip": boolean,
  "title": "titolo nuovo, diverso dall'originale, max 75 caratteri, mai troncare parole a metà",
  "slug": "slug-url-max-6-parole",
  "excerpt": "1-2 frasi, max 160 caratteri",
  "meta_description": "140-155 caratteri, include keyword principale",
  "content": "HTML con solo tag <p> e <strong>. Lunghezza variabile in base ai fatti disponibili",
  "category": "uno slug tra: {$cat_list}",
  "cities": ["solo slug esatti da: {$city_list}, [] se nessuna"],
  "tags": ["3-5 tag pertinenti, no nomi di luoghi già in cities"],
  "image_prompt": "prompt inglese per Imagen o null",
  "image_symbol": "slug del simbolo o null",
  "social_caption": "1-2 frasi per gruppi Facebook, max 200 caratteri"
}
PROMPT;

    $raw = dnap_call_claude($user_prompt, $system_prompt, 1800, 0.7);
    if (!$raw) return false;

    $data = dnap_parse_gpt_json($raw);

    if (!$data || empty($data['title']) || empty($data['content'])) {
        dnap_log('JSON Claude non valido, retry…');
        $retry_user = "Rispondi SOLO con JSON valido. Articolo da riscrivere:\nTitolo: {$original_title}\nTesto: {$text_input}\n\nCampi richiesti: skip, title, slug, excerpt, meta_description, content, category, cities, tags, image_prompt, image_symbol, social_caption.";
        $raw2 = dnap_call_claude($retry_user, $system_prompt, 1500, 0.5);
        if (!$raw2) return false;
        $data = dnap_parse_gpt_json($raw2);
        if (!$data || empty($data['title'])) {
            dnap_log('JSON Claude non valido al retry: ' . mb_substr($raw2, 0, 200));
            return false;
        }
    }

    // ── Skip check ───────────────────────────────────────────────
    if (!empty($data['skip']) && $data['skip'] === true) {
        return ['skip' => true];
    }

    // ── Sanitize title ───────────────────────────────────────────
    $t = wp_strip_all_tags(trim($data['title']));
    if (mb_strlen($t) > 75) {
        $t = mb_substr($t, 0, 75);
        $last_space = mb_strrpos($t, ' ');
        if ($last_space) $t = mb_substr($t, 0, $last_space);
    }
    $data['title'] = $t;

    $data['slug']             = sanitize_title($data['slug'] ?? $data['title']);
    $data['excerpt']          = wp_strip_all_tags(mb_substr(trim($data['excerpt'] ?? ''), 0, 160));
    $data['meta_description'] = wp_strip_all_tags(mb_substr(trim($data['meta_description'] ?? ''), 0, 155));
    $data['content']          = wp_kses($data['content'], [
        'p'          => [],
        'strong'     => [],
        'em'         => [],
        'br'         => [],
    ]);

    // ── Category ─────────────────────────────────────────────────
    $cat_raw = sanitize_title(trim($data['category'] ?? ''));
    $data['category'] = in_array($cat_raw, $categories) ? $cat_raw : '';

    // ── Cities ───────────────────────────────────────────────────
    $raw_cities  = is_array($data['cities'] ?? null) ? $data['cities'] : [];
    $data['cities'] = [];
    foreach ($raw_cities as $c) {
        $slug = sanitize_title(trim($c));
        if (in_array($slug, $cities)) $data['cities'][] = $slug;
    }

    // ── Tags ─────────────────────────────────────────────────────
    $raw_tags = is_array($data['tags'] ?? null) ? $data['tags'] : [];
    $data['tags'] = array_slice(array_map('sanitize_text_field', $raw_tags), 0, 5);

    // ── Image fields ─────────────────────────────────────────────
    $image_prompt = $data['image_prompt'] ?? null;
    $image_symbol = $data['image_symbol'] ?? null;

    if ($image_prompt && is_string($image_prompt)) {
        $image_prompt = sanitize_textarea_field($image_prompt);
        if (strlen($image_prompt) > 500) {
            $image_prompt = mb_substr($image_prompt, 0, 500);
        }
        $data['image_prompt'] = $image_prompt;
    } else {
        $data['image_prompt'] = null;
    }

    if ($image_symbol && is_string($image_symbol)) {
        $image_symbol = sanitize_title($image_symbol);
        $data['image_symbol'] = in_array($image_symbol, $symbols) ? $image_symbol : null;
    } else {
        $data['image_symbol'] = null;
    }

    // ── Social caption ───────────────────────────────────────────
    $caption = $data['social_caption'] ?? '';
    if (is_string($caption)) {
        $caption = sanitize_text_field(trim($caption));
        if (mb_strlen($caption) > 200) {
            $caption = mb_substr($caption, 0, 200);
        }
        $data['social_caption'] = $caption;
    } else {
        $data['social_caption'] = '';
    }

    if (empty($data['title'])) return false;

    return $data;
}

/* ============================================================
   JSON PARSE
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
