<?php
if (!defined('ABSPATH')) exit;

/*
 * Gestione azioni toggle/delete su admin_init, PRIMA di qualsiasi output HTML.
 * wp_redirect() deve essere chiamato prima che WordPress invii gli header della pagina;
 * farlo dentro la callback di rendering (dnap_feeds_page) è troppo tardi.
 */
add_action('admin_init', function () {
    if (!isset($_GET['page']) || $_GET['page'] !== 'dnap-feeds') return;
    if (!current_user_can('manage_options')) return;

    $feeds = get_option('dnap_feeds', []);

    // Attiva/disattiva
    if (isset($_GET['toggle']) && check_admin_referer('dnap_toggle_' . $_GET['toggle'])) {
        $idx = (int) $_GET['toggle'];
        if (isset($feeds[$idx])) {
            $feeds[$idx]['active'] = $feeds[$idx]['active'] ? 0 : 1;
            update_option('dnap_feeds', $feeds);
        }
        wp_redirect(admin_url('admin.php?page=dnap-feeds'));
        exit;
    }

    // Elimina
    if (isset($_GET['delete']) && check_admin_referer('dnap_delete_' . $_GET['delete'])) {
        $idx = (int) $_GET['delete'];
        if (isset($feeds[$idx])) {
            dnap_log("Feed rimosso: {$feeds[$idx]['url']}");
            array_splice($feeds, $idx, 1);
            update_option('dnap_feeds', $feeds);
        }
        wp_redirect(admin_url('admin.php?page=dnap-feeds'));
        exit;
    }
});

add_action('admin_menu', function(){
    add_submenu_page(
        'dnap-dashboard',
        'Gestione Feed',
        'Feed RSS',
        'manage_options',
        'dnap-feeds',
        'dnap_feeds_page'
    );
});

function dnap_feeds_page() {
    // ob_start() come safety net: cattura eventuale output accidentale
    // prima che wp_redirect() possa essere chiamato (e.g. da hook aggiuntivi).
    ob_start();

    $feeds = get_option('dnap_feeds', array());

    // Aggiungi feed (gestito qui perché mostra un notice inline, non fa redirect)
    if (isset($_POST['new_feed']) && check_admin_referer('dnap_add_feed')) {
        $url = esc_url_raw(trim($_POST['url']));
        if ($url) {
            $feeds[] = array(
                'url'       => $url,
                'city_slug' => sanitize_title(isset($_POST['city_slug']) ? $_POST['city_slug'] : ''),
                'cat_id'    => intval(isset($_POST['cat_id']) ? $_POST['cat_id'] : 0),
                'active'    => 1,
            );
            update_option('dnap_feeds', $feeds);
            dnap_log("Feed aggiunto: {$url}");
            echo '<div class="notice notice-success"><p>Feed aggiunto.</p></div>';
        }
    }

    // Nota: toggle e delete vengono gestiti in admin_init (prima di qualsiasi output)
    // tramite l'hook registrato sopra; non servono qui.

    $cities     = get_terms(array('taxonomy' => 'city', 'hide_empty' => false));
    $categories = get_categories(array('hide_empty' => false, 'orderby' => 'name'));

    ?>
    <div class="wrap">
    <h1>📡 Gestione Feed RSS <span style="background:#d4edda;color:#155724;font-size:12px;font-weight:600;padding:3px 10px;border-radius:20px;vertical-align:middle;">✨ Città e Categoria assegnate automaticamente da GPT</span></h1>

    <!-- AGGIUNGI FEED -->
    <div style="background:#fff;border:1px solid #ddd;border-radius:6px;padding:20px;margin-bottom:24px;max-width:760px;">
        <h2 style="margin-top:0;">➕ Aggiungi Feed</h2>
        <form method="post">
            <?php wp_nonce_field('dnap_add_feed'); ?>
            <table class="form-table" style="margin:0;">

                <!-- URL -->
                <tr>
                    <th style="width:120px;"><label for="url">URL Feed RSS</label></th>
                    <td>
                        <input type="url" id="url" name="url" required
                               placeholder="https://news.google.com/rss/search?q=mondragone..."
                               style="width:100%;max-width:460px;">
                        <p class="description">Feed RSS del giornale o di Google News.</p>
                    </td>
                </tr>

                <!-- CITTÀ -->
                <tr>
                    <th><label for="city_slug">Città</label></th>
                    <td>
                        <?php if ($cities && !is_wp_error($cities) && count($cities) > 0) : ?>
                            <select id="city_slug" name="city_slug" style="min-width:220px;">
                                <option value="">— Nessuna città —</option>
                                <?php foreach ($cities as $city) : ?>
                                    <option value="<?php echo esc_attr($city->slug); ?>">
                                        <?php echo esc_html($city->name); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">Fallback: usata solo se GPT non riconosce la città nell'articolo.</p>
                        <?php else : ?>
                            <span style="color:#c00;font-size:13px;">
                                Nessuna città trovata.
                                <a href="<?php echo admin_url('edit-tags.php?taxonomy=city&post_type=post'); ?>">Crea le città</a>
                            </span>
                        <?php endif; ?>
                    </td>
                </tr>

                <!-- CATEGORIA -->
                <tr>
                    <th><label for="cat_id">Categoria</label></th>
                    <td>
                        <?php if ($categories) : ?>
                            <select id="cat_id" name="cat_id" style="min-width:220px;">
                                <option value="0">— Nessuna categoria —</option>
                                <?php foreach ($categories as $cat) : ?>
                                    <option value="<?php echo esc_attr($cat->term_id); ?>">
                                        <?php echo esc_html($cat->name); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">Fallback: usata solo se GPT non riconosce la categoria nell'articolo.</p>
                        <?php else : ?>
                            <span style="color:#c00;font-size:13px;">
                                Nessuna categoria trovata.
                                <a href="<?php echo admin_url('edit-tags.php?taxonomy=category'); ?>">Crea le categorie</a>
                            </span>
                        <?php endif; ?>
                    </td>
                </tr>

            </table>
            <p style="margin:16px 0 0;">
                <input type="submit" name="new_feed" class="button button-primary" value="➕ Aggiungi Feed">
            </p>
        </form>
    </div>

    <!-- LISTA FEED -->
    <div style="background:#fff;border:1px solid #ddd;border-radius:6px;overflow:hidden;margin-bottom:24px;">
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th style="width:36px;">Stato</th>
                    <th>URL Feed</th>
                    <th style="width:130px;">Città</th>
                    <th style="width:120px;">Categoria</th>
                    <th style="width:150px;">Azioni</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($feeds)) : ?>
                <tr>
                    <td colspan="5" style="text-align:center;padding:30px;color:#888;">
                        Nessun feed configurato. Aggiungine uno sopra.
                    </td>
                </tr>
            <?php else : ?>
                <?php foreach ($feeds as $i => $f) :
                    $is_active = !empty($f['active']);

                    // Risolvi nome città
                    $city_label = '<span style="color:#bbb;">—</span>';
                    if (!empty($f['city_slug']) && $cities && !is_wp_error($cities)) {
                        foreach ($cities as $c) {
                            if ($c->slug === $f['city_slug']) {
                                $city_label = esc_html($c->name);
                                break;
                            }
                        }
                    }

                    // Risolvi nome categoria
                    $cat_label = '<span style="color:#bbb;">—</span>';
                    if (!empty($f['cat_id']) && $f['cat_id'] > 0) {
                        $cat_obj = get_category($f['cat_id']);
                        if ($cat_obj && !is_wp_error($cat_obj)) {
                            $cat_label = esc_html($cat_obj->name);
                        }
                    }
                ?>
                <tr>
                    <td style="text-align:center;">
                        <span style="font-size:16px;" title="<?php echo $is_active ? 'Attivo' : 'Disattivo'; ?>">
                            <?php echo $is_active ? '🟢' : '🔴'; ?>
                        </span>
                    </td>
                    <td style="word-break:break-all;font-size:12px;">
                        <a href="<?php echo esc_url($f['url']); ?>" target="_blank" rel="noopener noreferrer">
                            <?php echo esc_html(mb_substr($f['url'], 0, 80) . (strlen($f['url']) > 80 ? '…' : '')); ?>
                        </a>
                    </td>
                    <td style="font-size:13px;"><?php echo $city_label; ?></td>
                    <td style="font-size:13px;"><?php echo $cat_label; ?></td>
                    <td>
                        <a href="<?php echo wp_nonce_url(admin_url("admin.php?page=dnap-feeds&toggle={$i}"), "dnap_toggle_{$i}"); ?>"
                           class="button button-small">
                            <?php echo $is_active ? '⏸ Pausa' : '▶ Attiva'; ?>
                        </a>
                        <a href="<?php echo wp_nonce_url(admin_url("admin.php?page=dnap-feeds&delete={$i}"), "dnap_delete_{$i}"); ?>"
                           class="button button-small"
                           style="color:#c00;margin-left:4px;"
                           onclick="return confirm('Eliminare questo feed?')">
                            🗑
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- SUGGERIMENTI FEED -->
    <div style="background:#f0f6ff;border:1px solid #c5d8f5;border-radius:6px;padding:20px;max-width:760px;">
        <h3 style="margin-top:0;">💡 Feed RSS Consigliati — Litorale Domizio</h3>
        <table style="font-size:12px;width:100%;border-collapse:collapse;">
            <thead>
                <tr style="background:#e8f0fe;">
                    <th style="text-align:left;padding:6px 8px;width:180px;">Fonte</th>
                    <th style="text-align:left;padding:6px 8px;">URL (copia e incolla sopra)</th>
                </tr>
            </thead>
            <tbody>
            <?php
            $suggested = array(
                // Google News per città
                array('🔍 GN - Tutto il Litorale',    'https://news.google.com/rss/search?q=mondragone+OR+%22castel+volturno%22+OR+%22baia+domizia%22+OR+cellole+OR+carinola+OR+%22sessa+aurunca%22+OR+%22falciano+del+massico%22&hl=it&gl=IT&ceid=IT:it'),
                array('🔍 GN - Mondragone',            'https://news.google.com/rss/search?q=mondragone&hl=it&gl=IT&ceid=IT:it'),
                // ⚠️ Castel Volturno: aggiunti -napoli -ssc -calcio per escludere notizie SSC Napoli (si allena lì)
                array('🔍 GN - Castel Volturno',       'https://news.google.com/rss/search?q=%22castel+volturno%22+caserta+-napoli+-ssc+-calcio+-ultras+-serie&hl=it&gl=IT&ceid=IT:it'),
                array('🔍 GN - Baia Domizia',          'https://news.google.com/rss/search?q=%22baia+domizia%22&hl=it&gl=IT&ceid=IT:it'),
                array('🔍 GN - Sessa Aurunca',         'https://news.google.com/rss/search?q=%22sessa+aurunca%22&hl=it&gl=IT&ceid=IT:it'),
                array('🔍 GN - Cellole',               'https://news.google.com/rss/search?q=cellole+caserta&hl=it&gl=IT&ceid=IT:it'),
                array('🔍 GN - Carinola',              'https://news.google.com/rss/search?q=carinola+caserta&hl=it&gl=IT&ceid=IT:it'),
                array('🔍 GN - Falciano del Massico',  'https://news.google.com/rss/search?q=%22falciano+del+massico%22&hl=it&gl=IT&ceid=IT:it'),
                array('📰 CasertaNews',                'https://www.casertanews.it/feed/'),
                array('📰 PaeseNews',                  'https://www.paesenews.it/feed/'),
                array('📰 Edizione Caserta',           'https://edizionecaserta.net/feed/'),
                array('📰 ÈCaserta',                   'https://www.ecaserta.com/feed/'),
                array('📰 Il Mattino Caserta',         'https://www.ilmattino.it/rss/caserta.xml'),
                array('📰 The Report Zone',            'https://www.thereportzone.it/feed/'),
                array('📰 BelvedereNews',              'https://www.belvederenews.net/feed/'),
            );
            foreach ($suggested as $s) :
            ?>
            <tr style="border-bottom:1px solid #dce6f5;">
                <td style="padding:5px 8px;font-weight:600;"><?php echo $s[0]; ?></td>
                <td style="padding:5px 8px;font-family:monospace;font-size:11px;word-break:break-all;">
                    <a href="<?php echo esc_url($s[1]); ?>" target="_blank"><?php echo esc_html($s[1]); ?></a>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    </div><!-- wrap -->
    <?php
}
