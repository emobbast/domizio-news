<?php
if (!defined('ABSPATH')) exit;

/*
 * Tutte le azioni POST/GET gestite su admin_init, PRIMA degli header HTTP.
 * Pattern Post/Redirect/Get: ogni azione fa redirect con ?dnap_notice=<key>,
 * che la page callback mostra come notice. Questo evita "headers already sent"
 * e permette F5 sicuro senza ri-eseguire l'azione.
 */
add_action('admin_init', function () {
    if (!isset($_GET['page']) || $_GET['page'] !== 'dnap-dashboard') return;
    if (!current_user_can('manage_options')) return;

    // Salva API key
    // hidden field "save_settings" distingue questo form dagli altri POST
    if (isset($_POST['save_settings']) && check_admin_referer('dnap_save_settings')) {
        // Secret fields: only update if the submitted value is non-empty.
        // Empty submission means the user didn't retype the key; keep the stored value.
        $submitted_anthropic = isset($_POST['dnap_anthropic_key']) ? trim($_POST['dnap_anthropic_key']) : '';
        if ($submitted_anthropic !== '') {
            update_option('dnap_anthropic_key', sanitize_text_field($submitted_anthropic));
        }
        $submitted_tg_token = isset($_POST['dnap_telegram_token']) ? trim($_POST['dnap_telegram_token']) : '';
        if ($submitted_tg_token !== '') {
            update_option('dnap_telegram_token', sanitize_text_field($submitted_tg_token));
        }
        if (isset($_POST['dnap_telegram_channel'])) {
          update_option('dnap_telegram_channel', sanitize_text_field($_POST['dnap_telegram_channel']));
        }
        wp_redirect(admin_url('admin.php?page=dnap-dashboard&dnap_notice=settings_saved'));
        exit;
    }

    // Import manuale
    // FIX: "import_now" è ora su un campo hidden, non sul submit disabilitato via onclick.
    // Un <input type="submit" disabled> non viene incluso nel POST; il campo hidden sì.
    if (isset($_POST['import_now']) && check_admin_referer('dnap_import')) {
        dnap_import_now();
        wp_redirect(admin_url('admin.php?page=dnap-dashboard&dnap_notice=import_done'));
        exit;
    }

    // Svuota log
    if (isset($_POST['clear_log']) && check_admin_referer('dnap_clear_log')) {
        dnap_clear_log();
        wp_redirect(admin_url('admin.php?page=dnap-dashboard&dnap_notice=log_cleared'));
        exit;
    }

    // Test API key
    if (isset($_POST['test_api']) && check_admin_referer('dnap_test_api')) {
        $test = dnap_call_claude(
            'Rispondi solo con: {"ok":true}',
            'Rispondi solo con JSON valido, nessun testo aggiuntivo.',
            50,
            0
        );
        if ($test && strpos($test, '"ok":true') !== false) {
            echo '<div class="notice notice-success"><p>✅ API Key valida! Connessione Anthropic Claude funzionante.</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>❌ Errore connessione Claude. Verifica la API key.</p></div>';
        }
    }

    // Reschedule cron
    if (isset($_POST['reschedule_cron']) && check_admin_referer('dnap_reschedule')) {
        wp_clear_scheduled_hook('dnap_cron_import');
        wp_schedule_event(time(), 'hourly', 'dnap_cron_import');
        wp_redirect(admin_url('admin.php?page=dnap-dashboard&dnap_notice=cron_rescheduled'));
        exit;
    }

    // Sblocca import: cancella il lock bloccato
    if (isset($_GET['dnap_unlock']) && check_admin_referer('dnap_unlock_import')) {
        delete_option('dnap_import_lock');
        wp_redirect(admin_url('admin.php?page=dnap-dashboard&dnap_notice=import_unlocked'));
        exit;
    }
});

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
    ob_start();

    $anthropic_key = (bool) get_option('dnap_anthropic_key', '');
    $last_import  = get_option('dnap_last_import', null);
    $next_cron    = wp_next_scheduled('dnap_cron_import');
    $feeds        = get_option('dnap_feeds', []);
    $active_feeds = array_filter($feeds, function($f) { return !empty($f['active']); });
    $log_entries  = dnap_get_log();
    $import_locked = (bool) get_option('dnap_import_lock');

    // Mappa notice → [tipo, messaggio]
    $notice_map = [
        'settings_saved'   => ['success', '✅ API Key salvata.'],
        'import_done'      => ['success', '✅ Import completato. Vedi il log sotto.'],
        'log_cleared'      => ['info',    '🗑️ Log svuotato.'],
        'api_ok'           => ['success', '✅ API Key valida! Connessione OpenAI funzionante.'],
        'api_fail'         => ['error',   '❌ API Key non valida o errore di rete. Controlla il log.'],
        'cron_rescheduled' => ['success', '✅ Cron riprogrammato!'],
        'import_unlocked'  => ['success', '✅ Lock rimosso. Puoi avviare un nuovo import.'],
        'tag_added'        => ['success', '⭐ Tag in evidenza aggiunto.'],
        'tag_removed'      => ['info',    '🗑️ Tag in evidenza rimosso.'],
    ];
    $notice_key = isset($_GET['dnap_notice']) ? sanitize_key($_GET['dnap_notice']) : '';
    if ($notice_key && isset($notice_map[$notice_key])) {
        [$type, $msg] = $notice_map[$notice_key];
        echo "<div class='notice notice-{$type} is-dismissible'><p>{$msg}</p></div>";
    }

    ?>
    <div class="wrap">
    <h1>🗞 Domizio News AI Publisher <span style="font-size:13px;font-weight:normal;color:#888;">v<?php echo DNAP_VERSION; ?></span></h1>

    <!-- STATUS BAR -->
    <div style="display:flex;gap:16px;flex-wrap:wrap;margin:16px 0;">

        <?php
        $status_items = [
            [
                'label' => 'API Key',
                'value' => $anthropic_key ? '✅ Configurata' : '❌ Mancante',
                'color' => $anthropic_key ? '#d4edda' : '#f8d7da',
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
    <?php endif; ?>

    <!-- SE IL LOCK IMPORT È BLOCCATO: WARNING + LINK SBLOCCO -->
    <?php if ($import_locked) : ?>
    <div class="notice notice-warning">
        <p>
            <strong>⚠️ Import in corso o lock bloccato.</strong>
            Il bottone "Importa Ora" non si attiverà finché il lock è attivo (max 10 min).
            Se un import precedente è crashato,
            <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=dnap-dashboard&dnap_unlock=1'), 'dnap_unlock_import')); ?>">sblocca subito</a>.
        </p>
    </div>
    <?php endif; ?>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;margin-top:20px;">

        <!-- COL SX: Impostazioni -->
        <div>

            <!-- API KEY -->
            <div style="background:#fff;border:1px solid #ddd;border-radius:6px;padding:20px;margin-bottom:20px;">
                <h2 style="margin-top:0;">🔑 Configurazione Anthropic</h2>
                <form method="post">
                    <?php wp_nonce_field('dnap_save_settings'); ?>
                    <input type="hidden" name="save_settings" value="1">
                    <table class="form-table" style="margin:0;">
                        <tr>
                            <th><label for="dnap_anthropic_key">API Key Anthropic (Claude)</label></th>
                            <td>
                                <?php $anthropic_set = (bool) get_option('dnap_anthropic_key', ''); ?>
                                <input type="password" id="dnap_anthropic_key" name="dnap_anthropic_key"
                                       value=""
                                       autocomplete="new-password"
                                       class="regular-text"
                                       style="width:100%;max-width:420px;"
                                       placeholder="<?php echo $anthropic_set ? '••••••••••••• (salvata — lascia vuoto per non modificarla)' : 'sk-ant-api03-...'; ?>">
                                <p class="description">Chiave API Anthropic per Claude Haiku 4.5. Inizia con sk-ant-api03-... Lascia vuoto per conservare il valore attuale.</p>
                            </td>
                        </tr>
                        <tr>
                          <th scope="row">Telegram Bot Token</th>
                          <td>
                            <?php $tg_token_set = (bool) get_option('dnap_telegram_token',''); ?>
                            <input type="password" name="dnap_telegram_token"
                                   value=""
                                   autocomplete="new-password"
                                   placeholder="<?php echo $tg_token_set ? '••••••••••••• (salvato — lascia vuoto per non modificarlo)' : '123456789:ABC...'; ?>"
                                   style="width:400px;">
                            <p class="description">Token del bot Telegram (da @BotFather). Lascia vuoto per conservare il valore attuale.</p>
                          </td>
                        </tr>
                        <tr>
                          <th scope="row">Telegram Channel</th>
                          <td>
                            <input type="text" name="dnap_telegram_channel"
                                   value="<?php echo esc_attr(get_option('dnap_telegram_channel','')); ?>"
                                   placeholder="@domizionews"
                                   style="width:400px;">
                            <p class="description">Username del canale (es. @domizionews)</p>
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

            <!-- TAG IN EVIDENZA -->
            <?php dnap_vip_tags_section(); ?>

            <!-- IMPORT MANUALE -->
            <div style="background:#fff;border:1px solid #ddd;border-radius:6px;padding:20px;">
                <h2 style="margin-top:0;">⚡ Import Manuale</h2>
                <p style="color:#666;margin-bottom:12px;">
                    L'import automatico avviene ogni ora tramite WP-Cron.
                    Usa questo pulsante per un import immediato.
                </p>
                <form method="post">
                    <?php wp_nonce_field('dnap_import'); ?>
                    <?php /* FIX: "import_now" su campo hidden — il submit disabilitato via onclick
                              non viene inviato dal browser, ma il campo hidden sì. */ ?>
                    <input type="hidden" name="import_now" value="1">
                    <input type="submit" class="button button-secondary"
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
