<?php
/**
 * DOMIZIO NEWS — Script di setup categorie e città
 * ================================================
 * ISTRUZIONI:
 * 1. Carica questo file nella ROOT di WordPress (dove c'è wp-config.php)
 * 2. Aprilo nel browser: https://tuosito.it/dnap-setup.php
 * 3. Clicca "Esegui Setup"
 * 4. CANCELLA il file dopo l'uso (per sicurezza)
 */

// Protezione base — richiede conferma via POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['run'])) {
    ?>
    <!DOCTYPE html>
    <html lang="it">
    <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Domizio News — Setup</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, sans-serif; background: #f0f0f1; display: flex; align-items: center; justify-content: center; min-height: 100vh; }
        .box { background: #fff; border-radius: 8px; padding: 40px; max-width: 560px; width: 100%; box-shadow: 0 4px 24px rgba(0,0,0,.1); }
        h1 { font-size: 22px; color: #1d2327; margin-bottom: 6px; }
        .sub { color: #666; font-size: 14px; margin-bottom: 24px; }
        .warn { background: #fff8e5; border: 1px solid #f0c000; border-radius: 4px; padding: 12px 16px; font-size: 13px; color: #7a5800; margin-bottom: 24px; line-height: 1.6; }
        .section { margin-bottom: 20px; }
        .section h3 { font-size: 13px; font-weight: 700; text-transform: uppercase; letter-spacing: .5px; color: #888; margin-bottom: 10px; }
        .tag-list { display: flex; flex-wrap: wrap; gap: 6px; }
        .tag { background: #f0f0f1; border: 1px solid #ddd; border-radius: 20px; padding: 3px 10px; font-size: 12px; color: #444; }
        .tag.city { background: #e8f0fe; border-color: #b3c8f5; color: #1a56db; }
        button { width: 100%; padding: 14px; background: #c0392b; color: #fff; border: none; border-radius: 6px; font-size: 16px; font-weight: 700; cursor: pointer; margin-top: 20px; }
        button:hover { background: #a93226; }
        .note { font-size: 12px; color: #aaa; text-align: center; margin-top: 12px; }
    </style>
    </head>
    <body>
    <div class="box">
        <h1>🗞 Domizio News — Setup Iniziale</h1>
        <p class="sub">Questo script creerà automaticamente categorie e città su WordPress.</p>

        <div class="warn">
            ⚠️ <strong>Leggi prima di procedere:</strong><br>
            • Le categorie/città già esistenti <strong>non verranno duplicate</strong><br>
            • Gli slug esistenti verranno mantenuti<br>
            • <strong>Cancella questo file dopo l'uso</strong>
        </div>

        <div class="section">
            <h3>📂 Categorie che verranno create</h3>
            <div class="tag-list">
                <?php
                $cats = array('Cronaca','Sport','Politica','Economia & Lavoro','Ambiente & Mare','Eventi & Cultura','Salute','Incidenti & Sicurezza');
                foreach ($cats as $c) echo '<span class="tag">' . $c . '</span>';
                ?>
            </div>
        </div>

        <div class="section">
            <h3>📍 Città che verranno create</h3>
            <div class="tag-list">
                <?php
                $cities = array('Mondragone','Castel Volturno','Baia Domizia','Sessa Aurunca','Cellole','Falciano del Massico','Carinola');
                foreach ($cities as $c) echo '<span class="tag city">' . $c . '</span>';
                ?>
            </div>
        </div>

        <form method="post">
            <button type="submit" name="run" value="1">▶ Esegui Setup</button>
        </form>
        <p class="note">Dopo l'esecuzione, cancella questo file dal server.</p>
    </div>
    </body>
    </html>
    <?php
    exit;
}

// ============================================================
// ESECUZIONE
// ============================================================

// Carica WordPress
define('ABSPATH_SEARCH', true);
$wp_load = '';
$paths = array(
    __DIR__ . '/wp-load.php',
    __DIR__ . '/../wp-load.php',
    __DIR__ . '/../../wp-load.php',
);
foreach ($paths as $p) {
    if (file_exists($p)) { $wp_load = $p; break; }
}

if (!$wp_load) {
    die('<p style="color:red;font-family:sans-serif;padding:20px;">Errore: wp-load.php non trovato. Assicurati di aver messo questo file nella root di WordPress.</p>');
}

require_once $wp_load;

if (!function_exists('wp_insert_term')) {
    die('<p style="color:red;font-family:sans-serif;padding:20px;">Errore: WordPress non caricato correttamente.</p>');
}

// ============================================================
// DEFINIZIONE CATEGORIE
// ============================================================
$categories_to_create = array(
    array(
        'name'        => 'Cronaca',
        'slug'        => 'cronaca',
        'description' => 'Notizie di cronaca locale dal Litorale Domizio: fatti, eventi e accadimenti del territorio.',
    ),
    array(
        'name'        => 'Sport',
        'slug'        => 'sport',
        'description' => 'Sport locale: calcio, tennis, nuoto e tutte le discipline sportive del Litorale Domizio.',
    ),
    array(
        'name'        => 'Politica',
        'slug'        => 'politica',
        'description' => 'Notizie di politica locale: comuni, regione Campania e decisioni amministrative.',
    ),
    array(
        'name'        => 'Economia & Lavoro',
        'slug'        => 'economia-lavoro',
        'description' => 'Economia, lavoro, imprese e sviluppo del territorio del Litorale Domizio.',
    ),
    array(
        'name'        => 'Ambiente & Mare',
        'slug'        => 'ambiente-mare',
        'description' => 'Ambiente, mare, balneabilità e tutela del territorio costiero del Litorale Domizio.',
    ),
    array(
        'name'        => 'Eventi & Cultura',
        'slug'        => 'eventi-cultura',
        'description' => 'Eventi, sagre, concerti, mostre e iniziative culturali sul Litorale Domizio.',
    ),
    array(
        'name'        => 'Salute',
        'slug'        => 'salute',
        'description' => 'Salute, sanità e notizie mediche dalla provincia di Caserta e dal Litorale Domizio.',
    ),
    array(
        'name'        => 'Incidenti & Sicurezza',
        'slug'        => 'incidenti-sicurezza',
        'description' => 'Incidenti stradali, sicurezza pubblica e interventi delle forze dell\'ordine.',
    ),
);

// ============================================================
// DEFINIZIONE CITTÀ (tassonomia "city")
// ============================================================
$cities_to_create = array(
    array(
        'name'        => 'Mondragone',
        'slug'        => 'mondragone',
        'description' => 'Notizie locali da Mondragone, comune costiero della provincia di Caserta.',
    ),
    array(
        'name'        => 'Castel Volturno',
        'slug'        => 'castel-volturno',
        'description' => 'Notizie locali da Castel Volturno, sul Litorale Domizio in provincia di Caserta.',
    ),
    array(
        'name'        => 'Baia Domizia',
        'slug'        => 'baia-domizia',
        'description' => 'Notizie locali da Baia Domizia, rinomata località balneare del Litorale Domizio.',
    ),
    array(
        'name'        => 'Sessa Aurunca',
        'slug'        => 'sessa-aurunca',
        'description' => 'Notizie locali da Sessa Aurunca, comune in provincia di Caserta.',
    ),
    array(
        'name'        => 'Cellole',
        'slug'        => 'cellole',
        'description' => 'Notizie locali da Cellole, comune del Litorale Domizio in provincia di Caserta.',
    ),
    array(
        'name'        => 'Falciano del Massico',
        'slug'        => 'falciano-del-massico',
        'description' => 'Notizie locali da Falciano del Massico, comune in provincia di Caserta.',
    ),
    array(
        'name'        => 'Carinola',
        'slug'        => 'carinola',
        'description' => 'Notizie locali da Carinola, comune in provincia di Caserta.',
    ),
);

// ============================================================
// ESECUZIONE INSERIMENTO
// ============================================================
$results = array('cats' => array(), 'cities' => array());

// Categorie
foreach ($categories_to_create as $cat) {
    $existing = get_category_by_slug($cat['slug']);
    if ($existing) {
        $results['cats'][] = array('name' => $cat['name'], 'status' => 'skip', 'id' => $existing->term_id);
    } else {
        $inserted = wp_insert_term($cat['name'], 'category', array(
            'slug'        => $cat['slug'],
            'description' => $cat['description'],
        ));
        if (is_wp_error($inserted)) {
            $results['cats'][] = array('name' => $cat['name'], 'status' => 'error', 'msg' => $inserted->get_error_message());
        } else {
            $results['cats'][] = array('name' => $cat['name'], 'status' => 'created', 'id' => $inserted['term_id']);
        }
    }
}

// Città
foreach ($cities_to_create as $city) {
    $existing = get_term_by('slug', $city['slug'], 'city');
    if ($existing) {
        $results['cities'][] = array('name' => $city['name'], 'status' => 'skip', 'id' => $existing->term_id);
    } else {
        $inserted = wp_insert_term($city['name'], 'city', array(
            'slug'        => $city['slug'],
            'description' => $city['description'],
        ));
        if (is_wp_error($inserted)) {
            $results['cities'][] = array('name' => $city['name'], 'status' => 'error', 'msg' => $inserted->get_error_message());
        } else {
            $results['cities'][] = array('name' => $city['name'], 'status' => 'created', 'id' => $inserted['term_id']);
        }
    }
}

// Flush rewrite rules
flush_rewrite_rules();

// ============================================================
// OUTPUT RISULTATI
// ============================================================
$icon = array('created' => '✅', 'skip' => '⏭', 'error' => '❌');
$color = array('created' => '#d4edda', 'skip' => '#f8f9fa', 'error' => '#f8d7da');
$label = array('created' => 'Creata', 'skip' => 'Già esistente', 'error' => 'Errore');
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Setup completato — Domizio News</title>
<style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: -apple-system, sans-serif; background: #f0f0f1; padding: 40px 20px; }
    .box { background: #fff; border-radius: 8px; padding: 32px; max-width: 600px; margin: 0 auto; box-shadow: 0 4px 24px rgba(0,0,0,.1); }
    h1 { font-size: 22px; color: #1d2327; margin-bottom: 6px; }
    .sub { color: #666; font-size: 14px; margin-bottom: 28px; }
    h2 { font-size: 15px; font-weight: 700; color: #1d2327; margin: 24px 0 12px; padding-bottom: 8px; border-bottom: 2px solid #f0f0f1; }
    table { width: 100%; border-collapse: collapse; font-size: 14px; }
    td, th { padding: 8px 12px; text-align: left; }
    th { font-size: 12px; color: #888; text-transform: uppercase; letter-spacing: .5px; }
    tr td:first-child { width: 30px; }
    .id { color: #aaa; font-size: 12px; }
    .err { color: #c00; font-size: 12px; }
    .danger { background: #fff8e5; border: 1px solid #f0c000; border-radius: 6px; padding: 14px 18px; margin-top: 28px; font-size: 13px; color: #7a5800; line-height: 1.7; }
    .danger strong { color: #c0392b; }
    .btn { display: inline-block; margin-top: 20px; padding: 10px 20px; background: #2271b1; color: #fff; border-radius: 4px; text-decoration: none; font-size: 14px; font-weight: 600; }
    .btn:hover { background: #135e96; }
</style>
</head>
<body>
<div class="box">
    <h1>✅ Setup completato!</h1>
    <p class="sub">Categorie e città sono state configurate su WordPress.</p>

    <h2>📂 Categorie</h2>
    <table>
        <tr><th></th><th>Nome</th><th>Stato</th><th>ID</th></tr>
        <?php foreach ($results['cats'] as $r) : ?>
        <tr style="background:<?php echo $color[$r['status']]; ?>;">
            <td><?php echo $icon[$r['status']]; ?></td>
            <td><?php echo esc_html($r['name']); ?></td>
            <td><?php echo $label[$r['status']]; ?></td>
            <td class="<?php echo $r['status'] === 'error' ? 'err' : 'id'; ?>">
                <?php echo isset($r['id']) ? '#' . $r['id'] : esc_html($r['msg']); ?>
            </td>
        </tr>
        <?php endforeach; ?>
    </table>

    <h2>📍 Città</h2>
    <table>
        <tr><th></th><th>Nome</th><th>Stato</th><th>ID</th></tr>
        <?php foreach ($results['cities'] as $r) : ?>
        <tr style="background:<?php echo $color[$r['status']]; ?>;">
            <td><?php echo $icon[$r['status']]; ?></td>
            <td><?php echo esc_html($r['name']); ?></td>
            <td><?php echo $label[$r['status']]; ?></td>
            <td class="<?php echo $r['status'] === 'error' ? 'err' : 'id'; ?>">
                <?php echo isset($r['id']) ? '#' . $r['id'] : esc_html($r['msg']); ?>
            </td>
        </tr>
        <?php endforeach; ?>
    </table>

    <div class="danger">
        <strong>⚠️ IMPORTANTE — Cancella questo file ora!</strong><br>
        Questo script permette l'esecuzione di codice sul tuo server senza autenticazione.<br>
        <strong>Connettiti via FTP/SFTP e cancella <code>dnap-setup.php</code> dalla root di WordPress.</strong>
    </div>

    <a href="<?php echo admin_url('edit-tags.php?taxonomy=category'); ?>" class="btn">
        → Vai alle Categorie in WordPress
    </a>
    &nbsp;
    <a href="<?php echo admin_url('edit-tags.php?taxonomy=city&post_type=post'); ?>" class="btn" style="background:#1d7a1d;">
        → Vai alle Città in WordPress
    </a>
</div>
</body>
</html>
