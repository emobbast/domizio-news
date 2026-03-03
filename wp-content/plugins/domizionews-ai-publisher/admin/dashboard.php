<?php
if (!defined('ABSPATH')) exit;

add_action('admin_menu', function(){
    add_menu_page(
        'Domizio News',
        'Domizio News',
        'manage_options',
        'dnap-dashboard',
        'dnap_dashboard',
        'dashicons-rss',
        30
    );
});

function dnap_dashboard() {

    // Salva API key
    if (isset($_POST['api_key']) && check_admin_referer('dnap_save_settings')) {
        update_option('dnap_api_key', sanitize_text_field(trim($_POST['api_key'])));
        echo '<div class="notice notice-success"><p>✅ API Key salvata.</p></div>';
    }

    // Import manuale
    if (isset($_POST['import_now']) && check_admin_referer('dnap_import')) {
        dnap_import_now();
        echo '<div class="notice notice-success"><p>✅ Import completato. Vedi il log sotto.</p></div>';
    }

    // Svuota log
    if (isset($_POST['clear_log']) && check_admin_referer('dnap_clear_log')) {
        dnap_clear_log();
        echo '<div class="notice notice-info"><p>🗑️ Log svuotato.</p></div>';
    }

    // Test API key
    if (isset($_POST['test_api']) && check_admin_referer('dnap_test_api')) {
        $test = dnap_call_gpt('Rispondi solo con: {"ok":true}', 20);
        $parsed = $test ? dnap_parse_gpt_json($test) : null;
        if ($parsed && !empty($parsed['ok'])) {
            echo '<div class="notice notice-success"><p>✅ API Key valida! Connessione OpenAI funzionante.</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>❌ API Key non valida o errore di rete. Controlla il log.</p></div>';
        }
    }

    $api_key     = get_option('dnap_api_key', '');
    $last_import = get_option('dnap_last_import', null);
    $next_cron   = wp_next_scheduled('dnap_cron_import');
    $feeds       = get_option('dnap_feeds', []);
    $active_feeds = array_filter($feeds, function($f) { return !empty($f['active']); });
    $log_entries = dnap_get_log();

    ?>
    <div class="wrap">
    <h1>🗞 Domizio News AI Publisher <span style="font-size:13px;font-weight:normal;color:#888;">v<?php echo DNAP_VERSION; ?></span></h1>

    <!-- STATUS BAR -->
    <div style="display:flex;gap:16px;flex-wrap:wrap;margin:16px 0;">

        <?php
        $status_items = [
            [
                'label' => 'API Key',
                'value' => $api_key ? '✅ Configurata' : '❌ Mancante',
                'color' => $api_key ? '#d4edda' : '#f8d7da',
            ],
            [
                'label' => 'Feed Attivi',
                'value' => count($active_feeds) . ' / ' . count($feeds),
                'color' => count($active_feeds) > 0 ? '#d4edda' : '#fff3cd',
            ],
            [
                'label' => 'Prossimo Cron',
                'value' => $next_cron ? human_time_diff($next_cron) . ' fa' : '❌ Non schedulato',
                'color' => $next_cron ? '#d4edda' : '#f8d7da',
            ],
            [
                'label' => 'Ultimo Import',
                'value' => $last_import
                    ? "✅ {$last_import['imported']} importati · {$last_import['errors']} errori<br><small>" . esc_html($last_import['time']) . "</small>"
                    : '— Mai eseguito',
                'color' => $last_import ? '#d4edda' : '#f8f8f8',
            ],
        ];

        foreach ($status_items as $s) :
        ?>
        <div style="background:<?php echo $s['color']; ?>;border:1px solid #ddd;border-radius:6px;padding:12px 18px;min-width:180px;">
            <div style="font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:#555;margin-bottom:4px;"><?php echo $s['label']; ?></div>
            <div style="font-weight:600;font-size:14px;"><?php echo $s['value']; ?></div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- SE IL CRON NON È SCHEDULATO: WARNING -->
    <?php if (!$next_cron) : ?>
    <div class="notice notice-error">
        <p>
            <strong>⚠️ Il Cron automatico non è attivo!</strong>
            Il plugin è stato attivato ma WP-Cron non risulta schedulato.
            <a href="#" onclick="document.getElementById('reschedule-form').submit();return false;">Clicca qui per riprogrammarlo</a>.
        </p>
    </div>
    <form id="reschedule-form" method="post">
        <?php wp_nonce_field('dnap_reschedule'); ?>
        <input type="hidden" name="reschedule_cron" value="1">
    </form>
    <?php
    // Gestisci reschedule
    if (isset($_POST['reschedule_cron']) && check_admin_referer('dnap_reschedule')) {
        wp_clear_scheduled_hook('dnap_cron_import');
        wp_schedule_event(time(), 'hourly', 'dnap_cron_import');
        echo '<div class="notice notice-success"><p>✅ Cron riprogrammato!</p></div>';
    }
    ?>
    <?php endif; ?>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;margin-top:20px;">

        <!-- COL SX: Impostazioni -->
        <div>

            <!-- API KEY -->
            <div style="background:#fff;border:1px solid #ddd;border-radius:6px;padding:20px;margin-bottom:20px;">
                <h2 style="margin-top:0;">🔑 Configurazione OpenAI</h2>
                <form method="post">
                    <?php wp_nonce_field('dnap_save_settings'); ?>
                    <table class="form-table" style="margin:0;">
                        <tr>
                            <th><label for="api_key">API Key</label></th>
                            <td>
                                <input type="password" id="api_key" name="api_key"
                                       value="<?php echo esc_attr($api_key); ?>"
                                       style="width:100%;max-width:420px;"
                                       placeholder="sk-...">
                                <p class="description">Ottieni la tua key su <a href="https://platform.openai.com/api-keys" target="_blank">platform.openai.com</a></p>
                            </td>
                        </tr>
                    </table>
                    <p style="margin:12px 0 0;">
                        <input type="submit" class="button button-primary" value="💾 Salva API Key">
                    </p>
                </form>
                <form method="post" style="margin-top:10px;display:inline;">
                    <?php wp_nonce_field('dnap_test_api'); ?>
                    <input type="submit" name="test_api" class="button" value="🧪 Testa Connessione">
                </form>
            </div>

            <!-- IMPORT MANUALE -->
            <div style="background:#fff;border:1px solid #ddd;border-radius:6px;padding:20px;">
                <h2 style="margin-top:0;">⚡ Import Manuale</h2>
                <p style="color:#666;margin-bottom:12px;">
                    L'import automatico avviene ogni ora tramite WP-Cron.
                    Usa questo pulsante per un import immediato.
                </p>
                <form method="post">
                    <?php wp_nonce_field('dnap_import'); ?>
                    <input type="submit" name="import_now" class="button button-secondary"
                           value="▶ Importa Ora"
                           onclick="this.value='⏳ Importazione in corso...';this.disabled=true;this.form.submit();">
                </form>
                <?php if ($last_import) : ?>
                <div style="margin-top:16px;padding:12px;background:#f9f9f9;border-radius:4px;font-size:13px;">
                    <strong>Ultimo import:</strong> <?php echo esc_html($last_import['time']); ?><br>
                    ✅ Importati: <strong><?php echo $last_import['imported']; ?></strong> &nbsp;
                    ⏭ Saltati: <strong><?php echo $last_import['skipped']; ?></strong> &nbsp;
                    ❌ Errori: <strong style="color:<?php echo $last_import['errors'] > 0 ? 'red' : 'inherit'; ?>">
                        <?php echo $last_import['errors']; ?>
                    </strong>
                </div>
                <?php endif; ?>
            </div>

        </div>

        <!-- COL DX: Log -->
        <div>
            <div style="background:#fff;border:1px solid #ddd;border-radius:6px;padding:20px;height:100%;">
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;">
                    <h2 style="margin:0;">📋 Log Attività</h2>
                    <form method="post" style="margin:0;">
                        <?php wp_nonce_field('dnap_clear_log'); ?>
                        <input type="submit" name="clear_log" class="button button-small" value="🗑️ Svuota Log">
                    </form>
                </div>

                <div style="background:#0d1117;border-radius:4px;padding:14px;height:380px;overflow-y:auto;font-family:monospace;font-size:12px;line-height:1.7;">
                    <?php if (empty($log_entries)) : ?>
                        <span style="color:#666;">Nessun log disponibile. Esegui un import per vedere l'attività.</span>
                    <?php else : ?>
                        <?php foreach ($log_entries as $entry) :
                            $msg = esc_html($entry['msg']);
                            // Colora le righe in base al tipo
                            if (strpos($msg, '✅') !== false)      $color = '#3fb950';
                            elseif (strpos($msg, '❌') !== false)  $color = '#f85149';
                            elseif (strpos($msg, '⚠️') !== false) $color = '#d29922';
                            elseif (strpos($msg, '🚀') !== false)  $color = '#58a6ff';
                            elseif (strpos($msg, '🏁') !== false)  $color = '#58a6ff';
                            elseif (strpos($msg, '✏️') !== false)  $color = '#e3b341';
                            else $color = '#8b949e';
                        ?>
                        <div style="color:<?php echo $color; ?>;">
                            <span style="color:#444;"><?php echo esc_html($entry['time']); ?></span>
                            <?php echo $msg; ?>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <p style="font-size:11px;color:#888;margin:8px 0 0;">
                    Mostra le ultime <?php echo DNAP_LOG_MAX; ?> righe. Auto-refresh: <a href="<?php echo admin_url('admin.php?page=dnap-dashboard'); ?>">ricarica pagina</a>.
                </p>
            </div>
        </div>

    </div><!-- grid -->
    </div><!-- wrap -->
    <?php
}
